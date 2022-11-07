<?php declare(strict_types=1);

namespace Contribute\Controller\Admin;

use Contribute\Controller\ContributionTrait;
use Contribute\Form\QuickSearchForm;
use DateInterval;
use DateTime;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use Omeka\Stdlib\ErrorStore;
use Omeka\Stdlib\Message;

class ContributionController extends AbstractActionController
{
    use ContributionTrait;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function browseAction()
    {
        $params = $this->params()->fromQuery();

        $formSearch = $this->getForm(QuickSearchForm::class);
        $formSearch
            ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'browse'], true))
            ->setAttribute('id', 'contribution-search');

        // Fix form radio for empty value and form select.
        $data = $params;
        if (isset($data['patch'])) {
            if ($data['patch'] === '0') {
                $data['patch'] = '00';
            } elseif ($params['patch'] === '00') {
                $params['patch'] = '0';
            }
        }
        if (isset($data['submitted'])) {
            if ($data['submitted'] === '0') {
                $data['submitted'] = '00';
            } elseif ($params['submitted'] === '00') {
                $params['submitted'] = '0';
            }
        }
        if (isset($data['reviewed'])) {
            if ($data['reviewed'] === '0') {
                $data['reviewed'] = '00';
            } elseif ($params['reviewed'] === '00') {
                $params['reviewed'] = '0';
            }
        }
        if (isset($data['resource_template_id']) && is_array($data['resource_template_id'])) {
            $data['resource_template_id'] = empty($data['resource_template_id']) ? '' : reset($data['resource_template_id']);
            $params['resource_template_id'] = $data['resource_template_id'];
        }
        if (isset($data['owner_id']) && is_array($data['owner_id'])) {
            $data['owner_id'] = empty($data['owner_id']) ? '' : reset($data['owner_id']);
            $params['owner_id'] = $data['owner_id'];
        }

        // Don't check validity: this is a search form.
        $formSearch->setData($data);

        $this->setBrowseDefaults('created', 'desc');
        if (!isset($params['sort_by'])) {
            $params['sort_by'] = 'created';
            $params['sort_order'] = 'desc';
        }

        $response = $this->api()->search('contributions', $params);
        $this->paginator($response->getTotalResults());

        /** @var \Omeka\Form\ConfirmForm $formDeleteSelected */
        $formDeleteSelected = $this->getForm(ConfirmForm::class);
        $formDeleteSelected
            ->setAttribute('id', 'confirm-delete-selected')
            ->setAttribute('action', $this->url()->fromRoute('admin/contribution/default', ['action' => 'batch-delete'], true))
            ->setButtonLabel('Confirm Delete'); // @translate

        /** @var \Omeka\Form\ConfirmForm $formDeleteAll */
        $formDeleteAll = $this->getForm(ConfirmForm::class);
        $formDeleteAll
            ->setAttribute('id', 'confirm-delete-all')
            ->setAttribute('action', $this->url()->fromRoute('admin/contribution/default', ['action' => 'batch-delete-all'], true))
            ->setButtonLabel('Confirm Delete'); // @translate
        $formDeleteAll
            ->get('submit')->setAttribute('disabled', true);

        $contributions = $response->getContent();

        return new ViewModel([
            'contributions' => $contributions,
            'resources' => $contributions,
            'formSearch' => $formSearch,
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
            return $this->forward()->dispatch('Contribute\Controller\Admin\Contribution', $params);
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

        $id = $this->params('id');
        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        $contribution = $this->api()->read('contributions', $id)->getContent();

        // Only a resource already added can have a status reviewed.
        $resource = $contribution ? $contribution->resource() : null;
        if (!$resource) {
            return new JsonModel([
                'status' => 'success',
                // Status is updated, so inverted.
                'data' => [
                    'contribution' => [
                        'status' => 'unreviewed',
                        'statusLabel' => $this->translate('Unreviewed'), // @translate
                    ],
                ],
            ]);
        }

        // Only people who can edit the resource can update the status.
        if ($resource && !$resource->userIsAllowed('update')) {
            return $this->jsonErrorUnauthorized();
        }

        $isReviewed = $contribution->isReviewed();

        $data = [];
        $data['o-module-contribute:reviewed'] = !$isReviewed;
        $response = $this->api()
            ->update('contributions', $id, $data, [], ['isPartial' => true]);
        if (!$response) {
            return $this->jsonErrorUpdate();
        }

        return new JsonModel([
            'status' => 'success',
            // Status is updated, so inverted.
            'data' => [
                'contribution' => [
                    'status' => $isReviewed ? 'unreviewed' : 'reviewed',
                    'statusLabel' => $isReviewed ? $this->translate('Unreviewed') : $this->translate('Reviewed'), // @translate
                ],
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

        // Check rights to edit without token.
        if (!$token) {
            $user = $this->identity();
            $settings = $this->settings();
            $contributeMode = $settings->get('contribute_mode');
            $contributeRoles = $settings->get('contribute_roles', []) ?: [];
            $canEditWithoutToken = $contributeMode === 'open'
                || ($user && $contributeMode === 'user')
                || ($user && $contributeMode === 'role' && in_array($user->getRole(), $contributeRoles));
            if (!$canEditWithoutToken) {
                return $this->jsonErrorUnauthorized();
            }
            return new JsonModel([
                'status' => 'success',
                'data' => [
                    'contribution_token' => [
                        'status' => 'no-token',
                        'statusLabel' => $this->translate('No token'), // @translate
                    ],
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
            'status' => 'success',
            'data' => [
                'contribution_token' => [
                    'status' => 'expired',
                    'statusLabel' => $this->translate('Expired'), // @translate
                ],
            ],
        ]);
    }

    public function createResourceAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jsonErrorNotFound();
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        $contribution = $this->api()->read('contributions', $id)->getContent();

        // If there is a resource, it can't be created.
        $contributionResource = $contribution->resource();
        if ($contributionResource) {
            return $this->jsonErrorUpdate();
        }

        // Only people who can create resource can validate.
        $acl = $contribution->getServiceLocator()->get('Omeka\Acl');
        if (!$acl->userIsAllowed('Omeka\Api\Adapter\ItemAdapter', 'create')) {
            return $this->jsonErrorUnauthorized();
        }

        $resourceData = $contribution->proposalToResourceData();
        if (!$resourceData) {
            return $this->jsonErrorUpdate(new Message(
                $this->translate('Contribution is not valid: check template.') // @translate
            ));
        }

        $errorStore = new ErrorStore();
        $resource = $this->validateOrCreateOrUpdate($contribution, $resourceData, $errorStore, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jsonErrorUpdate(new Message(
                $this->translate('Contribution cannot be created: some values are not valid.') // @translate
            ), $errorStore);
        }
        if (!$resource) {
            return $this->jsonErrorUpdate();
        }

        return new JsonModel([
            'status' => 'success',
            'data' => [
                'contribution' => $contribution,
                'is_new' => true,
                'url' => $resource->adminUrl(),
            ],
        ]);
    }

    public function validateAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jsonErrorNotFound();
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        $contribution = $this->api()->read('contributions', $id)->getContent();

        // If there is no resource, create it as a whole.
        $contributionResource = $contribution->resource();

        // Only people who can edit the resource can validate.
        if (($contributionResource && !$contributionResource->userIsAllowed('update'))
            || (!$contributionResource && !$contribution->getServiceLocator()->get('Omeka\Acl')->userIsAllowed('Omeka\Api\Adapter\ItemAdapter', 'create'))
        ) {
            return $this->jsonErrorUnauthorized();
        }

        $resourceData = $contribution->proposalToResourceData();
        if (!$resourceData) {
            return $this->jsonErrorUpdate(new Message(
                $this->translate('Contribution is not valid.') // @translate
            ));
        }

        $errorStore = new ErrorStore();
        $resource = $this->validateOrCreateOrUpdate($contribution, $resourceData, $errorStore, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jsonErrorUpdate(new Message(
                $this->translate('Contribution is not valid: check its values.') // @translate
            ), $errorStore);
        }
        if (!$resource) {
            return $this->jsonErrorUpdate();
        }

        return new JsonModel([
            'status' => 'success',
            // Status is updated, so inverted.
            'data' => [
                'contribution' => [
                    'status' => 'validated',
                    'statusLabel' => $this->translate('Validated'), // @translate
                    'reviewed' => [
                        'status' => 'reviewed',
                        'statusLabel' => $this->translate('Reviewed'), // @translate
                    ],
                ],
                'is_new' => !$contribution->isPatch(),
                'url' => $resource->adminUrl(),
            ],
        ]);
    }

    public function validateValueAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jsonErrorNotFound();
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        $contribution = $this->api()->read('contributions', $id)->getContent();

        // A resource is required to update it.
        $contributionResource = $contribution->resource();
        if (!$contributionResource) {
            return $this->jsonErrorUpdate();
        }

        // Only people who can edit the resource can validate.
        if (!$contributionResource->userIsAllowed('update')) {
            return $this->jsonErrorUnauthorized();
        }

        $term = $this->params()->fromQuery('term');
        $key = $this->params()->fromQuery('key');
        if (!$term || !is_numeric($key)) {
            return $this->returnError('Missing term or key.'); // @translate
        }

        $key = (int) $key;

        $resourceData = $contribution->proposalToResourceData($term, $key);
        if (!$resourceData) {
            return $this->jsonErrorUpdate(new Message(
                $this->translate('Contribution is not valid.') // @translate
            ));
        }

        $errorStore = new ErrorStore();
        $resource = $this->validateOrCreateOrUpdate($contribution, $resourceData, $errorStore, true);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jsonErrorUpdate(new Message(
                $this->translate('Contribution is not valid: check values.') // @translate
            ), $errorStore);
        }
        if (!$resource) {
            return $this->jsonErrorUpdate();
        }

        return new JsonModel([
            'status' => 'success',
            // Status is updated, so inverted.
            'data' => [
                'contribution' => [
                    'status' => 'validated-value',
                    'statusLabel' => $this->translate('Validated value'), // @translate
                ],
            ],
        ]);
    }
}
