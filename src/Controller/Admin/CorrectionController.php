<?php
namespace Correction\Controller\Admin;

use Correction\Api\Representation\CorrectionRepresentation;
use DateInterval;
use DateTime;
use Omeka\Stdlib\Message;
use Zend\Http\Response;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

class CorrectionController extends AbstractActionController
{
    /**
     * Create a token for a list of resources.
     */
    public function createTokenAction()
    {
        if ($this->getRequest()->isGet()) {
            $params = $this->params()->fromQuery();
        } elseif ($this->getRequest()->isPost()) {
            $params = $this->params()->fromPost();
        } else {
            return $this->redirect()->toRoute('admin');
        }

        // Set default values to simplify checks.
        $params += [
            'resource_type' => null,
            'resource_ids' => [],
            'query' => [],
            'batch_action' => null,
            'redirect' => null,
            'email' => null,
            'expire' => null,
        ];

        $resourceType = $params['resource_type'];
        $resourceTypeMap = [
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
            'items' => 'items',
            'item_sets' => 'item_sets',
        ];
        if (!isset($resourceTypeMap[$resourceType])) {
            $this->messenger()->addError('You can create token only for items, media and item sets.'); // @translate
            return $params['redirect']
                ? $this->redirect()->toUrl($params['redirect'])
                : $this->redirect()->toRoute('admin');
        }

        $siteSlug = $this->defaultSiteSlug();
        if (is_null($siteSlug)) {
            $this->messenger()->addError('A site is required to create a public token.'); // @translate
            return $params['redirect']
                ? $this->redirect()->toUrl($params['redirect'])
                : $this->redirect()->toRoute('admin/default', ['controller' => $resourceType, 'action' => 'browse'], true);
        }

        $resource = $resourceTypeMap[$resourceType];
        // Normalize the resource type for controller url.
        $resourceType = array_search($resource, $resourceTypeMap);

        $resourceIds = $params['resource_ids']
            ? (is_array($params['resource_ids']) ? $params['resource_ids'] : explode(',', $params['resource_ids']))
            : [];
        $params['resource_ids'] = $resourceIds;
        $params['batch_action'] = $params['batch_action'] === 'correction-all' ? 'correction-all' : 'correction-selected';

        if ($params['batch_action'] === 'correction-all') {
            // Derive the query, removing limiting and sorting params.
            $query = json_decode($params['query'] ?: [], true);
            unset($query['submit'], $query['page'], $query['per_page'], $query['limit'],
                $query['offset'], $query['sort_by'], $query['sort_order']);
            $resourceIds = $this->api()->search($resource, $query, ['returnScalar' => 'id'])->getContent();
        }

        $count = count($resourceIds);
        if (empty($count)) {
            $this->messenger()->addError('You must select at least one resource to create a token.'); // @translate
            return $params['redirect']
                ? $this->redirect()->toUrl($params['redirect'])
                : $this->redirect()->toRoute('admin/default', ['controller' => $resourceType, 'action' => 'browse'], true);
        }

        $email = trim($params['email']);
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->messenger()->addError(new Message(
                'You set the optional email "%s" to create a correction token, but it is not well-formed.', // @translate
                $email
            ));
            return $params['redirect']
                ? $this->redirect()->toUrl($params['redirect'])
                : $this->redirect()->toRoute('admin/default', ['controller' => $resourceType, 'action' => 'browse'], true);
        }

        // $expire = $params['expire'];
        $tokenDuration = $this->settings()->get('correction_token_duration');
        $expire = $tokenDuration > 0
            ? (new DateTime('now'))->add(new DateInterval('PT' . ($tokenDuration * 86400) . 'S'))
            : null;

        // TODO Use the same token for all resource ids? When there is a user?
        $api = $this->api();
        $urlHelper = $this->viewHelpers()->get('url');
        $urls = [];
        foreach ($resourceIds as $resourceId) {
            /** @var \Correction\Api\Representation\TokenRepresentation $token */
            $token = $api
                ->create(
                    'correction_tokens',
                    [
                        'o:resource' => ['o:id' => $resourceId],
                        'o:email' => $email,
                        'o-module-correction:expire' => $expire,
                    ]
                )
                ->getContent();

            $query = [];
            $query['token'] = $token->token();
            $urls[] = $urlHelper(
                'site/resource-id',
                ['site-slug' => $siteSlug, 'controller' => $resourceType, 'id' => $resourceId, 'action' => 'edit'],
                ['query' => $query, 'force_canonical' => true]
            );
            unset($token);
        }

        $message = new Message(
            'Created %1$s correction tokens (email: %2$s, duration: %3$s): %4$s', // @translate
            $count,
            $email ?: new Message('none'), // @translate
            $tokenDuration
                ? new Message('%d days', $tokenDuration) // @translate
                : 'unlimited', // @translate
            '<ul><li>' . implode('</li><li>', $urls) . '</li></ul>'
        );

        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
        return $params['redirect']
            ? $this->redirect()->toUrl($params['redirect'])
            : $this->redirect()->toRoute('admin/default', ['controller' => $resourceType, 'action' => 'browse'], true);
    }

    /**
     * Expire all token of a resource.
     */
    public function expireTokensAction()
    {
        $id = $this->params('id');
        $api = $this->api();
        try {
            $resource = $api->read('resources', ['id' => $id])->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            return $this->notFoundAction();
        }

        $resourceType = $resource->getControllerName();
        $response = $api
            ->search(
                'correction_tokens',
                [
                    'resource_id' => $id,
                    'datetime' => [['field' => 'expire', 'type' => 'gte', 'value' => date('Y-m-d H:i:s')], ['joiner' => 'or', 'field' => 'expire', 'type' => 'nex']],
                ],
                ['returnScalar' => 'id']
            );
        $total = $response->getTotalResults();
        if (empty($total)) {
            $message = new Message(
                'Resource #%s has no tokens to expire.', // @translate
                sprintf(
                    '<a href="%s">%d</a>',
                    htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => $resourceType, 'id' => $id])),
                    $id
                )
            );
            $message->setEscapeHtml(false);
            $this->messenger()->addNotice($message);
            return $this->redirect()->toRoute('admin/id', ['controller' => $resourceType, 'action' => 'show'], true);
        }

        $ids = $response->getContent();

        $response = $api
            ->batchUpdate(
                'correction_tokens',
                $ids,
                ['o-module-correction:expire' => 'now']
            );

        $message = 'All tokens of the resource were expired.'; // @translate
        $this->messenger()->addSuccess($message);
        return $this->redirect()->toRoute('admin/id', ['controller' => $resourceType, 'action' => 'show'], true);
    }

    public function toggleStatusAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jsonErrorNotFound();
        }

        // Only people who can edit the resource can update the status.
        $id = $this->params('id');
        /** @var \Correction\Api\Representation\CorrectionRepresentation $correction */
        $correction = $this->api()->read('corrections', $id)->getContent();
        if (!$correction->resource()->userIsAllowed('update')) {
            return $this->jsonErrorUnauthorized();
        }

        $isReviewed = $correction->reviewed();

        $data = [];
        $data['o-module-correction:reviewed'] = !$isReviewed;
        $response = $this->api()
            ->update('corrections', $id, $data, [], ['isPartial' => true]);
        if (!$response) {
            return $this->jsonErrorUpdate();
        }

        return new JsonModel([
            'status' => Response::STATUS_CODE_200,
            // Status is updated, so inverted.
            'content' => [
                'status' => $isReviewed ? 'unreviewed' : 'reviewed',
                'statusLabel' => $isReviewed ? $this->translate('Unreviewed') : $this->translate('Reviewed'),
            ],
        ]);
    }

    public function expireTokenAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jsonErrorNotFound();
        }

        // Only people who can edit the resource can validate.
        $id = $this->params('id');
        if (empty($id)) {
            $token = $this->params()->fromQuery('token');
            if (empty($token)) {
                return $this->jsonErrorNotFound();
            }
            /** @var \Correction\Api\Representation\TokenRepresentation $token */
            $token = $this->api()->searchOne('correction_tokens', ['token' => $token])->getContent();
            if (!$token) {
                return $this->jsonErrorNotFound();
            }
        } else {
            /** @var \Correction\Api\Representation\CorrectionRepresentation $correction */
            $correction = $this->api()->read('corrections', $id)->getContent();
            $token = $correction->token();
        }

        if (!$token) {
            if (!$this->settings()->get('correction_without_token')) {
                return $this->jsonErrorUnauthorized();
            }
            return new JsonModel([
                'status' => Response::STATUS_CODE_200,
                'content' => [
                    'status' => 'no-token',
                    'statusLabel' => $this->translate('No token'),
                ],
            ]);
        }

        if (!$token->isExpired()) {
            $response = $this->api()
                ->update('correction_tokens', $token->id(), ['o-module-correction:expire' => 'now'], [], ['isPartial' => true]);
            if (!$response) {
                return $this->jsonErrorUpdate();
            }
        }

        return new JsonModel([
            'status' => Response::STATUS_CODE_200,
            'content' => [
                'status' => 'expired',
                'statusLabel' => $this->translate('Expired'),
            ],
        ]);
    }

    public function validateAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jsonErrorNotFound();
        }

        // Only people who can edit the resource can validate.
        $id = $this->params('id');
        /** @var \Correction\Api\Representation\CorrectionRepresentation $correction */
        $correction = $this->api()->read('corrections', $id)->getContent();
        if (!$correction->resource()->userIsAllowed('update')) {
            return $this->jsonErrorUnauthorized();
        }

        $this->validateCorrection($correction);

        $data = [];
        $data['o-module-correction:reviewed'] = true;
        $response = $this->api()
            ->update('corrections', $id, $data, [], ['isPartial' => true]);
        if (!$response) {
            return $this->jsonErrorUpdate();
        }

        return new JsonModel([
            'status' => Response::STATUS_CODE_200,
            // Status is updated, so inverted.
            'content' => [
                'status' => 'validated',
                'statusLabel' => $this->translate('Validated'),
                'reviewed' => [
                    'status' => 'reviewed',
                    'statusLabel' => $this->translate('Reviewed'),
                ],
            ],
        ]);
    }

    public function validateValueAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jsonErrorNotFound();
        }

        // Only people who can edit the resource can validate.
        $id = $this->params('id');
        /** @var \Correction\Api\Representation\CorrectionRepresentation $correction */
        $correction = $this->api()->read('corrections', $id)->getContent();
        if (!$correction->resource()->userIsAllowed('update')) {
            return $this->jsonErrorUnauthorized();
        }

        $term = $this->params()->fromQuery('term');
        $key = $this->params()->fromQuery('key');
        if (!$term || !is_numeric($key)) {
            return $this->returnError('Mising term or key.'); // @translate
        }

        $this->validateCorrection($correction, $term, $key);

        return new JsonModel([
            'status' => Response::STATUS_CODE_200,
            // Status is updated, so inverted.
            'content' => [
                'status' => 'validated-value',
                'statusLabel' => $this->translate('Validated value'),
            ],
        ]);
    }

    public function resourceTemplateDataAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            throw new \Omeka\Mvc\Exception\NotFoundException;
        }

        $api = $this->api();
        $resourceTemplateId = $this->params()->fromQuery('resource_template_id');

        $result = [
            'corrigible' => [],
            'fillable' => [],
        ];

        $correctionPartMap = $this->resourceTemplateCorrectionPartMap($resourceTemplateId);
        foreach ($correctionPartMap['corrigible'] as $term) {
            $property = $api->searchOne('properties', ['term' => $term])->getContent();
            if ($property) {
                $result['corrigible'][$property->id()] = $term;
            }
        }
        foreach ($correctionPartMap['fillable'] as $term) {
            $property = $api->searchOne('properties', ['term' => $term])->getContent();
            if ($property) {
                $result['fillable'][$property->id()] = $term;
            }
        }

        // No default values here.

        return new JsonModel($result);
    }

    /**
     * Correct existing values of the resource with the correction proposal.
     *
     * @param CorrectionRepresentation $correction
     * @param string $term
     * @param int $proposedKey
     */
    protected function validateCorrection(CorrectionRepresentation $correction, $term = null, $proposedKey = null)
    {
        $editable = $correction->listEditableProperties();

        if ($term) {
            $editable['corrigible'] = array_intersect_key($editable['corrigible'], [$term => null]);
            $editable['fillable'] = array_intersect_key($editable['fillable'], [$term => null]);
        } else {
            $proposedKey = null;
        }
        $hasProposedKey = !is_null($proposedKey);

        if (!count($editable['corrigible']) && !count($editable['fillable'])) {
            return;
        }

        $api = $this->api();

        // Right to update the resource is already checked.
        $resource = $correction->resource();
        $existingValues = $resource->values();
        $proposal = $correction->proposalCheck();

        // TODO How to update only one property to avoid to update unmodified terms?

        $data = [];
        foreach ($existingValues as $term => $propertyData) {
            $data[$term] = [];
            /** @var \Omeka\Api\Representation\ValueRepresentation $existingValue */
            foreach ($propertyData['values'] as $originalKey => $existingValue) {
                // Keep all existing values.
                $data[$term][$originalKey] = $existingValue->jsonSerialize();
                if (!isset($proposal[$term])) {
                    continue;
                }
                // TODO Manage all types of value.
                if (!in_array($existingValue->type(), ['literal', 'uri'])) {
                    continue;
                }

                // Values have no id and the order key is not saved, so the
                // check should be redone.
                $existingVal = $existingValue->value();
                $existingUri = $existingValue->uri();
                foreach ($proposal[$term] as $key => $proposition) {
                    if ($hasProposedKey && $proposedKey != $key) {
                        continue;
                    }
                    if ($proposition['validated']) {
                        continue;
                    }
                    if (!in_array($proposition['process'], ['remove', 'update'])) {
                        continue;
                    }

                    $isUri = array_key_exists('@uri', $proposition['original']);
                    $isValue = array_key_exists('@value', $proposition['original']);

                    if ($isUri) {
                        if ($proposition['original']['@uri'] === $existingUri) {
                            switch ($proposition['process']) {
                                case 'remove':
                                    unset($data[$term][$key]);
                                    break;
                                case 'update':
                                    $data[$term][$key]['@id'] = $proposition['proposed']['@uri'];
                                    $data[$term][$key]['o:label'] = $proposition['proposed']['@label'];
                                    break;
                            }
                            break;
                        }
                    } elseif ($isValue) {
                        if ($proposition['original']['@value'] === $existingVal) {
                            switch ($proposition['process']) {
                                case 'remove':
                                    unset($data[$term][$key]);
                                    break;
                                case 'update':
                                    $data[$term][$key]['@value'] = $proposition['proposed']['@value'];
                                    break;
                            }
                            break;
                        }
                    }
                }
            }
        }

        // Convert last remaining propositions into array.
        // Only process "append" should remain.
        foreach ($proposal as $term => $propositions) {
            $propertyId = $api->searchOne('properties', ['term' => $term])->getContent()->id();
            foreach ($propositions as $key => $proposition) {
                if ($hasProposedKey && $proposedKey != $key) {
                    continue;
                }
                if ($proposition['validated']) {
                    continue;
                }
                if ($proposition['process'] !== 'append') {
                    continue;
                }

                if (array_key_exists("@value", $proposition['original'])) {
                    $data[$term][] = [
                        'property_id' => $propertyId,
                        'type' => 'literal',
                        '@value' => $proposition['proposed']['@value'],
                        'is_public' => true,
                        // '@language' => null,
                    ];
                } elseif (array_key_exists("@uri", $proposition['original'])) {
                    $data[$term][] = [
                        'type' => 'uri',
                        'property_id' => $propertyId,
                        'o:label' => $proposition['proposed']['@label'],
                        '@id' => $proposition['proposed']['@uri'],
                        'is_public' => true,
                    ];
                }
            }
        }

        $api->update($resource->resourceName(), $resource->id(), $data, [], ['isPartial' => true]);
    }

    protected function jsonErrorUnauthorized()
    {
        return $this->returnError($this->translate('Unauthorized access.'), Response::STATUS_CODE_403); // @translate
    }

    protected function jsonErrorNotFound()
    {
        return $this->returnError($this->translate('Resource not found.'), Response::STATUS_CODE_404); // @translate
    }

    protected function jsonErrorUpdate()
    {
        return $this->returnError($this->translate('An internal error occurred.'), Response::STATUS_CODE_500); // @translate
    }

    protected function returnError($message, $statusCode = Response::STATUS_CODE_400, array $errors = null)
    {
        $response = $this->getResponse();
        $response->setStatusCode($statusCode);
        $result = [
            'status' => $statusCode,
            'message' => $message,
        ];
        if (is_array($errors)) {
            $result['errors'] = $errors;
        }
        return new JsonModel($result);
    }
}
