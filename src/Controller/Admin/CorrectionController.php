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

        $email = $params['email'];
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
            /** @var \Correction\Api\Representation\CorrectionTokenRepresentation $token */
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
            'Created %1$s correction tokens (duration: %2$s): %3$s', // @translate
            $count,
            $tokenDuration
                ? new Message('%d days', $tokenDuration) // @translate
                : 'unlimited',  // @translate
            '<ul><li>' . implode('</li><li>', $urls) . '</li></ul>'
        );

        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
        return $params['redirect']
            ? $this->redirect()->toUrl($params['redirect'])
            : $this->redirect()->toRoute('admin/default', ['controller' => $resourceType, 'action' => 'browse'], true);
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

    /**
     * Correct existing values of the resource with the correction proposal.
     *
     * @param CorrectionRepresentation $correction
     */
    protected function validateCorrection(CorrectionRepresentation $correction)
    {
        // Check the options in the case they were updated.
        $settings = $this->settings();
        $corrigible = $settings->get('correction_properties_corrigible', []);
        $fillable = $settings->get('correction_properties_fillable', []);
        if (empty($corrigible) && empty($fillable)) {
            return;
        }

        $api = $this->api();

        // Right to update the resource is already checked.
        $resource = $correction->resource();
        $values = $resource->values();
        $proposal = $correction->proposalCheck();

        $data = [];
        foreach ($values as $term => $propertyData) {
            $data[$term] = [];
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            foreach ($propertyData['values'] as $key => $value) {
                // Keep all existing values.
                // TODO How to update only one property to avoid to update unmodified terms?
                $data[$term][$key] = $value->jsonSerialize();
                if (!isset($proposal[$term])) {
                    continue;
                }
                // TODO Manage all types of value.
                if ($value->type() !== 'literal') {
                    continue;
                }
                // Values have no id and the order key is not saved, so the
                // check should be redone.
                $v = $value->value();
                foreach ($proposal[$term] as $proposition) {
                    if ($proposition['validated']) {
                        continue;
                    }
                    if (!in_array($proposition['process'], ['remove', 'update'])) {
                        continue;
                    }
                    if ($proposition['original']['@value'] === $v) {
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

        // Convert last remaining propositions into array.
        // Only process "append" should remain.
        foreach ($proposal as $term => $propositions) {
            $propertyId = $api->searchOne('properties', ['term' => $term])->getContent()->id();
            foreach ($propositions as $proposition) {
                if ($proposition['validated']) {
                    continue;
                }
                if ($proposition['process'] !== 'append') {
                    continue;
                }
                $data[$term][] = [
                    'property_id' => $propertyId,
                    'type' => 'literal',
                    '@value' => $proposition['proposed']['@value'],
                    // 'is_public' => true,
                    // '@language' => null,
                ];
            }
        }

        $this->api()
            ->update($resource->resourceName(), $resource->id(), $data, [], ['isPartial' => true]);
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
