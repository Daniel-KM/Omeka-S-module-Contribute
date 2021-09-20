<?php declare(strict_types=1);

namespace Contribute\Controller\Admin;

use Contribute\Api\Representation\ContributionRepresentation;
use DateInterval;
use DateTime;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use Omeka\Stdlib\Message;

class ContributionController extends AbstractActionController
{
    public function browseAction()
    {
        $this->setBrowseDefaults('created');
        $response = $this->api()->search('contributions', $this->params()->fromQuery());
        $this->paginator($response->getTotalResults());

        /** @var \Omeka\Form\ConfirmForm $formDeleteSelected */
        $formDeleteSelected = $this->getForm(ConfirmForm::class);
        $formDeleteSelected
            ->setAttribute('id', 'confirm-delete-selected')
            ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'batch-delete'], true))
            ->setButtonLabel('Confirm Delete'); // @translate

        /** @var \Omeka\Form\ConfirmForm $formDeleteAll */
        $formDeleteAll = $this->getForm(ConfirmForm::class);
        $this->getForm(ConfirmForm::class)
            ->setAttribute('id', 'confirm-delete-all')
            ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'batch-delete-all'], true))
            ->setButtonLabel('Confirm Delete'); // @translate
        $formDeleteAll
            ->get('submit')->setAttribute('disabled', true);

        $contributions = $response->getContent();

        return new ViewModel([
            'contributions' => $contributions,
            'resources' => $contributions,
            'formDeleteSelected' => $formDeleteSelected,
            'formDeleteAll' => $formDeleteAll,
        ]);
    }

    public function showAction()
    {
        $params = $this->params()->fromRoute();
        $response = $this->api()->read('contributions', $this->params('id'));
        $contribution = $response->getContent();
        $res = $contribution->resource();
        if (!$res) {
            $message = new Message('This contribution is a new resource or has no more resource.'); // @translate
            $this->messenger()->addError($message);
            $params['action'] = 'browse';
            return $this->forward()->dispatch(__CLASS__, $params);
        }

        $params = [];
        $params['controller'] = $res->getControllerName();
        $params['action'] = 'show';
        $params['id'] = $res->id();
        $url = $this->url()->fromRoute('admin/id', $params, ['fragment' => 'contribution']);
        return $this->redirect()->toUrl($url);
    }

    public function showDetailsAction()
    {
        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $response = $this->api()->read('contributions', $this->params('id'));
        $contribution = $response->getContent();

        $view = new ViewModel([
            'linkTitle' => $linkTitle,
            'resource' => $contribution,
            'values' => json_encode([]),
        ]);
        return $view
            ->setTemplate('contribute/admin/contribution/show-details')
            ->setTerminal(true);
    }

    public function deleteConfirmAction()
    {
        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $response = $this->api()->read('contributions', $this->params('id'));
        $contribution = $response->getContent();

        $view = new ViewModel([
            'contribution' => $contribution,
            'resource' => $contribution,
            'resourceLabel' => 'contribution', // @translate
            'partialPath' => 'contribute/admin/contribution/show-details',
            'linkTitle' => $linkTitle,
            'values' => json_encode([]),
        ]);
        return $view
            ->setTerminal(true)
            ->setTemplate('common/delete-confirm-details');
    }

    public function deleteAction()
    {
        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $response = $this->api($form)->delete('contributions', $this->params('id'));
                if ($response) {
                    $this->messenger()->addSuccess('Contribution successfully deleted'); // @translate
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }
        return $this->redirect()->toRoute(
            'admin/contribution',
            ['action' => 'browse'],
            true
        );
    }

    public function batchDeleteAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
        }

        $resourceIds = $this->params()->fromPost('resource_ids', []);
        if (!$resourceIds) {
            $this->messenger()->addError('You must select at least one contribution to batch delete.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->getRequest()->getPost());
        if ($form->isValid()) {
            $response = $this->api($form)->batchDelete('contributions', $resourceIds, [], ['continueOnError' => true]);
            if ($response) {
                $this->messenger()->addSuccess('Contributions successfully deleted'); // @translate
            }
        } else {
            $this->messenger()->addFormErrors($form);
        }
        return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
    }

    public function batchDeleteAllAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
        }

        // Derive the query, removing limiting and sorting params.
        $query = json_decode($this->params()->fromPost('query', []), true);
        unset($query['submit'], $query['page'], $query['per_page'], $query['limit'],
            $query['offset'], $query['sort_by'], $query['sort_order']);

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->getRequest()->getPost());
        if ($form->isValid()) {
            $this->jobDispatcher()->dispatch(\Omeka\Job\BatchDelete::class, [
                'resource' => 'contributions',
                'query' => $query,
            ]);
            $this->messenger()->addSuccess('Deleting contributions. This may take a while.'); // @translate
        } else {
            $this->messenger()->addFormErrors($form);
        }
        return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
    }

    /* Ajax */

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
        $params['batch_action'] = $params['batch_action'] === 'contribution-all' ? 'contribution-all' : 'contribution-selected';

        if ($params['batch_action'] === 'contribution-all') {
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

        $email = trim($params['email'] ?? '');
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->messenger()->addError(new Message(
                'You set the optional email "%s" to create a contribution token, but it is not well-formed.', // @translate
                $email
            ));
            return $params['redirect']
                ? $this->redirect()->toUrl($params['redirect'])
                : $this->redirect()->toRoute('admin/default', ['controller' => $resourceType, 'action' => 'browse'], true);
        }

        // $expire = $params['expire'];
        $tokenDuration = $this->settings()->get('contribute_token_duration');
        $expire = $tokenDuration > 0
            ? (new DateTime('now'))->add(new DateInterval('PT' . ($tokenDuration * 86400) . 'S'))
            : null;

        // TODO Use the same token for all resource ids? When there is a user?
        $api = $this->api();
        $urlHelper = $this->viewHelpers()->get('url');
        $urls = [];
        foreach ($resourceIds as $resourceId) {
            /** @var \Contribute\Api\Representation\TokenRepresentation $token */
            $token = $api
                ->create(
                    'contribution_tokens',
                    [
                        'o:resource' => ['o:id' => $resourceId],
                        'o:email' => $email,
                        'o-module-contribute:expire' => $expire,
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
            'Created %1$s contribution tokens (email: %2$s, duration: %3$s): %4$s', // @translate
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
                'contribution_tokens',
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
                'contribution_tokens',
                $ids,
                ['o-module-contribute:expire' => 'now']
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
        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        $contribution = $this->api()->read('contributions', $id)->getContent();
        if (!$contribution->resource()->userIsAllowed('update')) {
            return $this->jsonErrorUnauthorized();
        }

        $isReviewed = $contribution->reviewed();

        $data = [];
        $data['o-module-contribute:reviewed'] = !$isReviewed;
        $response = $this->api()
            ->update('contributions', $id, $data, [], ['isPartial' => true]);
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
            /** @var \Contribute\Api\Representation\TokenRepresentation $token */
            $token = $this->api()->searchOne('contribution_tokens', ['token' => $token])->getContent();
            if (!$token) {
                return $this->jsonErrorNotFound();
            }
        } else {
            /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
            $contribution = $this->api()->read('contributions', $id)->getContent();
            $token = $contribution->token();
        }

        if (!$token) {
            $contributeMode = $this->settings()->get('contribute_mode');
            if (!in_array($contributeMode, ['user', 'open'])
                || ($contributeMode === 'user' && !$this->identity())
            ) {
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
                ->update('contribution_tokens', $token->id(), ['o-module-contribute:expire' => 'now'], [], ['isPartial' => true]);
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

    public function createResourceAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jsonErrorNotFound();
        }

        // Only people who can edit the resource can validate.
        $id = $this->params('id');
        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        $contribution = $this->api()->read('contributions', $id)->getContent();

        // If there is a resource, it can't be created.
        $resource = $contribution->resource();
        if ($resource) {
            return $this->jsonErrorUpdate();
        }

        $acl = $contribution->getServiceLocator()->get('Omeka\Acl');
        if (!$acl->userIsAllowed('Omeka\Api\Adapter\ItemAdapter', 'create')) {
            return $this->jsonErrorUnauthorized();
        }

        $owner = $contribution->owner() ?: null;
        $resource = $this->api()->create('items', ['o:owner' => $owner ? ['o:id' => $owner->id()] : null])->getContent();

        $data = [];
        $data['o-module-contribute:reviewed'] = false;
        $data['o:resource'] = ['o:id' => $resource->id()];
        $response = $this->api()
            ->update('contributions', $id, $data, [], ['isPartial' => true]);
        if (!$response) {
            return $this->jsonErrorUpdate();
        }

        return new JsonModel([
            'status' => 'success',
            'content' => [
                'contribution' => $contribution,
                'url' => $contribution->adminUrl(),
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
        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        $contribution = $this->api()->read('contributions', $id)->getContent();

        $resource = $contribution->resource();
        if (!$resource) {
            return $this->jsonErrorUpdate();
        }

        if (!$resource->userIsAllowed('update')) {
            return $this->jsonErrorUnauthorized();
        }

        $this->validateContribution($contribution);

        $data = [];
        $data['o-module-contribute:reviewed'] = true;
        $response = $this->api()
            ->update('contributions', $id, $data, [], ['isPartial' => true]);
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
        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        $contribution = $this->api()->read('contributions', $id)->getContent();

        $resource = $contribution->resource();
        if (!$resource) {
            return $this->jsonErrorUpdate();
        }

        if (!$resource->userIsAllowed('update')) {
            return $this->jsonErrorUnauthorized();
        }

        $term = $this->params()->fromQuery('term');
        $key = $this->params()->fromQuery('key');
        if (!$term || !is_numeric($key)) {
            return $this->returnError('Missing term or key.'); // @translate
        }

        $this->validateContribution($contribution, $term, $key);

        return new JsonModel([
            'status' => Response::STATUS_CODE_200,
            // Status is updated, so inverted.
            'content' => [
                'status' => 'validated-value',
                'statusLabel' => $this->translate('Validated value'),
            ],
        ]);
    }

    /**
     * Update existing values of the contributed resource with the proposal.
     *
     * @todo Factorize with \Contribute\Site\ContributeController::prepareProposal()
     *
     * @param ContributionRepresentation $contribution
     * @param string|null $term Validate only a specific term.
     * @param int|null $proposedKey Validate only a specific key.
     */
    protected function validateContribution(ContributionRepresentation $contribution, $term = null, $proposedKey = null): void
    {
        $contributive = $contribution->contributiveData();
        if (!$contributive->isContributive()) {
            return;
        }

        // Right to update the resource is already checked.
        $resource = $contribution->resource();
        if ($resource) {
            $resourceTemplate = $resource->resourceTemplate();
            $existingValues = $resource->values();
        } else {
            $resourceTemplate = null;
            $existingValues = [];
        }
        $proposal = $contribution->proposalCheck();
        $hasProposedKey = !is_null($proposedKey);

        $api = $this->api();
        $propertyIds = $this->propertyIdsByTerms();

        // TODO How to update only one property to avoid to update unmodified terms?

        $data = [];
        foreach ($existingValues as $term => $propertyData) {
            // Keep all existing values.
            $data[$term] = array_map(function ($v) {
                return $v->jsonSerialize();
            }, $propertyData['values']);
            if (!$contributive->isTermContributive($term)) {
                continue;
            }
            /** @var \Omeka\Api\Representation\ValueRepresentation $existingValue */
            foreach ($propertyData['values'] as $existingValue) {
                if (!isset($proposal[$term])) {
                    continue;
                }
                if (!$contributive->isTermDatatype($term, $existingValue->type())) {
                    continue;
                }

                // Values have no id and the order key is not saved, so the
                // check should be redone.
                $existingVal = $existingValue->value();
                $existingUri = $existingValue->uri();
                $existingResourceId = $existingValue->valueResource() ? $existingValue->valueResource()->id() : null;
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
                    $isResource = array_key_exists('@resource', $proposition['original']);
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
                    } elseif ($isResource) {
                        if ($proposition['original']['@resource'] === $existingResourceId) {
                            switch ($proposition['process']) {
                                case 'remove':
                                    unset($data[$term][$key]);
                                    break;
                                case 'update':
                                    $data[$term][$key]['value_resource_id'] = $proposition['proposed']['@resource'];
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
            if (!$contributive->isTermContributive($term)) {
                continue;
            }
            $propertyId = $propertyIds[$term] ?? null;
            if (!$propertyId) {
                continue;
            }

            $typeTemplate = null;
            if ($resourceTemplate) {
                $resourceTemplateProperty = $resourceTemplate->resourceTemplateProperty($propertyId);
                if ($resourceTemplateProperty) {
                    $typeTemplate = $resourceTemplateProperty->dataType();
                }
            }

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

                if ($typeTemplate) {
                    $type = $typeTemplate;
                } else {
                    if (array_key_exists('@uri', $proposition['original'])) {
                        $type = 'uri';
                    } elseif (array_key_exists('@resource', $proposition['original'])) {
                        $type = 'resource';
                    } else {
                        $type = 'literal';
                    }
                }

                switch ($type) {
                    case 'literal':
                        $data[$term][] = [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $proposition['proposed']['@value'],
                            'is_public' => true,
                            // '@language' => null,
                        ];
                        break;
                    case 'resource':
                    case strtok($type, ':') === 'resource':
                        $data[$term][] = [
                            'type' => $type,
                            'property_id' => $propertyId,
                            'o:label' => null,
                            'value_resource_id' => $proposition['proposed']['@resource'],
                            '@id' => null,
                            'is_public' => true,
                            '@language' => null,
                        ];
                        break;
                    case 'uri':
                        $data[$term][] = [
                            'type' => 'uri',
                            'property_id' => $propertyId,
                            'o:label' => $proposition['proposed']['@label'],
                            '@id' => $proposition['proposed']['@uri'],
                            'is_public' => true,
                        ];
                        break;
                    case in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall']):
                        $data[$term][] = [
                            'type' => $type,
                            'property_id' => $propertyId,
                            'o:label' => $proposition['proposed']['@label'],
                            '@id' => $proposition['proposed']['@uri'],
                            'is_public' => true,
                        ];
                        break;
                    default:
                        // Nothing.
                        continue 2;
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
