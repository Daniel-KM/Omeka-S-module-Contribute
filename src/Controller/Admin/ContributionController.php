<?php declare(strict_types=1);

namespace Contribute\Controller\Admin;

use Common\Mvc\Controller\Plugin\JSend;
use Common\Stdlib\PsrMessage;
use Contribute\Controller\ContributionTrait;
use Contribute\Form\SendMessageForm;
use Contribute\Form\QuickSearchForm;
use DateInterval;
use DateTime;
use Doctrine\ORM\EntityManager;
use Laminas\Http\PhpEnvironment\RemoteAddress;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use Omeka\Stdlib\ErrorStore;

class ContributionController extends AbstractActionController
{
    use ContributionTrait;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var array
     */
    protected $defaultMessages;

    public function __construct(EntityManager $entityManager, array $defaultMessages)
    {
        $this->entityManager = $entityManager;
        $this->defaultMessages = $defaultMessages;
    }

    public function browseAction()
    {
        $params = $this->params()->fromQuery();

        $this->setBrowseDefaults('created', 'desc');
        if (!isset($params['sort_by'])) {
            $params['sort_by'] = 'created';
            $params['sort_order'] = 'desc';
        }

        $this->browse()->setDefaults('contributions');

        $response = $this->api()->search('contributions', $params);
        $this->paginator($response->getTotalResults());

        $subject = $this->settings()->get('contribute_author_message_subject')
            ?: $this->translate($this->defaultMessages['contribute_author_message_subject']);
        $body = $this->settings()->get('contribute_author_message_body')
            ?: $this->translate($this->defaultMessages['contribute_author_message_body']);
        $subject = $this->fillMessage($subject);
        $body = $this->fillMessage($body);

        /** @var \Contribute\Form\SendMessageForm $formSendMessage */
        $formSendMessage = $this->getForm(SendMessageForm::class);
        $formSendMessage
            ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'send-message'], true))
            ->setAttribute('id', 'send-message-form')
            ->setSubject($subject)
            ->setBody($body);

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
            'formSendMessage' => $formSendMessage,
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
            $message = new PsrMessage('This contribution is a new resource or has no more resource.'); // @translate
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

        $defaultSite = $this->viewHelpers()->get('defaultSite');
        $siteSlug = $defaultSite('slug');
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
            $this->messenger()->addError(new PsrMessage(
                'You set the optional email "{email}" to create a contribution token, but it is not well-formed.', // @translate
                ['email' => $email]
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

        $message = new PsrMessage(
            'Created {total} contribution tokens (email: {email}, duration: {duration}): {urls}', // @translate
            [
                'total' => $count,
                'email' => $email ?: new PsrMessage('none'), // @translate
                'duration' => $tokenDuration
                    ? new PsrMessage('{days} days', $tokenDuration) // @translate
                    : new PsrMessage('unlimited'), // @translate
                'urls' => '<ul><li>' . implode('</li><li>', $urls) . '</li></ul>',
            ]
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
            $message = new PsrMessage(
                'Resource #{resource_id} has no tokens to expire.', // @translate
                [
                    'resource_id' => sprintf(
                        '<a href="%s">%d</a>',
                        htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => $resourceType, 'id' => $id])),
                        $id
                    ),
                ]
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
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        try {
            $contribution = $this->api()->read('contributions', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // Only a resource already added can have a status reviewed.
        $resource = $contribution ? $contribution->resource() : null;
        if (!$resource) {
            return $this->jSend(JSend::SUCCESS, [
                // Status is updated, so inverted.
                'contribution' => [
                    'status' => 'unreviewed',
                    'statusLabel' => $this->translate('Unreviewed'), // @translate
                ],
            ]);
        }

        // Only people who can edit the resource can update the status.
        if ($resource && !$resource->userIsAllowed('update')) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $isReviewed = $contribution->isReviewed();

        $data = [];
        $data['o-module-contribute:reviewed'] = !$isReviewed;
        $response = $this->api()
            ->update('contributions', $id, $data, [], ['isPartial' => true]);
        if (!$response) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend(JSend::SUCCESS, [
            // Status is updated, so inverted.
            'contribution' => [
                'status' => $isReviewed ? 'unreviewed' : 'reviewed',
                'statusLabel' => $isReviewed ? $this->translate('Unreviewed') : $this->translate('Reviewed'), // @translate
            ],
        ]);
    }

    public function expireTokenAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        // Only people who can edit the resource can validate.
        $id = $this->params('id');
        if (empty($id)) {
            $token = $this->params()->fromQuery('token');
            if (empty($token)) {
                return $this->jSend(JSend::FAIL, null, $this->translate(
                    'Resource not found.' // @translate
                ), HttpResponse::STATUS_CODE_404);
            }
            /** @var \Contribute\Api\Representation\TokenRepresentation $token */
            try {
                $token = $this->api()->read('contribution_tokens', ['token' => $token])->getContent();
            } catch (\Exception $e) {
                return $this->jSend(JSend::FAIL, null, $this->translate(
                    'Resource not found.' // @translate
                ), HttpResponse::STATUS_CODE_404);
            }
        } else {
            /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
            $contribution = $this->api()->read('contributions', $id)->getContent();
            $token = $contribution->token();
        }

        // Check rights to edit without token.
        if (!$token) {
            $canContribute = $this->viewHelpers()->get('canContribute');
            $canEditWithoutToken = $canContribute();
            if (!$canEditWithoutToken) {
                return $this->jSend(JSend::FAIL, null, $this->translate(
                    'Unauthorized access.' // @translate
                ), HttpResponse::STATUS_CODE_401);
            }
            return $this->jSend(JSend::SUCCESS, [
                'contribution_token' => [
                    'status' => 'no-token',
                    'statusLabel' => $this->translate('No token'), // @translate
                ],
            ]);
        }

        if (!$token->isExpired()) {
            $response = $this->api()
                ->update('contribution_tokens', $token->id(), ['o-module-contribute:expire' => 'now'], [], ['isPartial' => true]);
            if (!$response) {
                return $this->jSend(JSend::ERROR, null, $this->translate(
                    'An internal error occurred.' // @translate
                ));
            }
        }

        return $this->jSend(JSend::SUCCESS, [
            'contribution_token' => [
                'status' => 'expired',
                'statusLabel' => $this->translate('Expired'), // @translate
            ],
        ]);
    }

    public function createResourceAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        try {
            $contribution = $this->api()->read('contributions', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // If there is a resource, it can't be created.
        $contributionResource = $contribution->resource();
        if ($contributionResource) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // Only people who can create resource can validate.
        $acl = $contribution->getServiceLocator()->get('Omeka\Acl');
        if (!$acl->userIsAllowed(\Omeka\Api\Adapter\ItemAdapter::class, 'create')) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $resourceData = $contribution->proposalToResourceData();
        if (!$resourceData) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                $this->translate('Contribution is not valid: check template.') // @translate
            ));
        }

        // Validate and create the resource.
        $errorStore = new ErrorStore();
        $resource = $this->validateOrCreateOrUpdate($contribution, $resourceData, $errorStore, false, false, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jSend(JSend::FAIL, $errorStore->getErrors() ?: null, $this->translate(
                'Contribution cannot be created: some values are not valid.' // @translate
            ));
        }
        if (!$resource) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend(JSend::SUCCESS, [
            'contribution' => $contribution,
            'is_new' => true,
            'url' => $resource->adminUrl(),
        ]);
    }

    public function sendMessageAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        try {
            $contribution = $this->api()->read('contributions', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate('Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        /** @var \Omeka\Entity\User $user */
        $user = $this->identity();
        if (!$user) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $updateContribution = (bool) $this->params()->fromPost('reject', false);
        if ($updateContribution && !$contribution->userIsAllowed('update')) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'The user has no right to update the contribution.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        if ($updateContribution && $contribution->resource()) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'The contribution is already validated and status cannot be changed.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        // No check for validity, since this is a message in admin.
        // Furthermore, the csrf is not updated for each post and may be false.
        // Anyway, this is just pure text sent by admin.

        // TODO Fill message sent?

        $body = (string) $this->params()->fromPost('body');
        $body = trim((string) $body);

        if (!strlen($body)) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Empty message.' // @translate
            ));
        }

        if (mb_strlen($body) > 10000) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Too long message.' // @translate
            ));
        }

        $subject = $this->params()->fromPost('subject');
        $subject = trim((string) $subject);
        if (!strlen($subject)) {
            $subject = $this->settings()->get('contribute_author_message_subject')
                ?: $this->translate($this->defaultMessages['contribute_author_message_subject']);
            $subject = $this->fillMessage($subject);
        }

        if (mb_strlen($body) > 1000) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Too long subject.' // @translate
            ));
        }

        $owner = $contribution->owner();
        $toEmail = $contribution->email() ?: ($owner ? $owner->email() : null);
        if (!$toEmail) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'No email defined for this contribution.' // @translate
            ));
        }

        $to = [$toEmail => $owner ? $owner->name() : ''];

        $bcc = $this->params()->fromPost('bcc', false)
            ? [$user->getEmail() => $user->getName()]
            : null;

        $replyTo = $this->params()->fromPost('reply_to', false)
            ? [$user->getEmail() => $user->getName()]
            : null;

        /** @see \Common\Mvc\Controller\Plugin\SendEmail */
        $result = $this->sendEmail($body, $subject, $to, null, null, $bcc, $replyTo);
        if (!$result) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'Sorry, the message cannot be sent. Contact the administrator.' // @translate
            ));
        }

        if ($updateContribution) {
            $data = [];
            $data['o-module-contribute:submitted'] = true;
            $data['o-module-contribute:reviewed'] = true;
            $response = $this->api()
                ->update('contributions', $id, $data, [], ['isPartial' => true]);
            // Normally, there is never an issue here.
            if (!$response) {
                return $this->jSend(JSend::ERROR, null, $this->translate(
                    'An internal error occurred.' // @translate
                ));
            }
        }

        $message = new PsrMessage(
            'Message successfully sent to {email}.', // @translate
            ['email' => $toEmail]
        );
        return $this->jSend(JSend::SUCCESS, [
            'contribution' => $contribution,
        ], $message->setTranslator($this->translator()));
    }

    public function validateAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        try {
            $contribution = $this->api()->read('contributions', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // If there is no resource, create it as a whole.
        $contributionResource = $contribution->resource();

        // Only people who can edit the resource can validate.
        if (($contributionResource && !$contributionResource->userIsAllowed('update'))
            || (!$contributionResource && !$contribution->getServiceLocator()->get('Omeka\Acl')->userIsAllowed('Omeka\Api\Adapter\ItemAdapter', 'create'))
        ) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $resourceData = $contribution->proposalToResourceData();
        if (!$resourceData) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Contribution is not valid.' // @translate
            ));
        }

        // Validate and update the resource.
        $errorStore = new ErrorStore();
        $resource = $this->validateOrCreateOrUpdate($contribution, $resourceData, $errorStore, false, false, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jSend(JSend::FAIL, $errorStore->getErrors() ?: null, $this->translate(
                'Contribution is not valid: check its values.' // @translate
            ));
        }
        if (!$resource) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend(JSend::SUCCESS, [
            // Status is updated, so inverted.
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
        ]);
    }

    public function validateValueAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        try {
            $contribution = $this->api()->read('contributions', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // A resource is required to update it.
        $contributionResource = $contribution->resource();
        if (!$contributionResource) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // Only people who can edit the resource can validate.
        if (!$contributionResource->userIsAllowed('update')) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $term = $this->params()->fromQuery('term');
        $key = $this->params()->fromQuery('key');
        if (!$term || !is_numeric($key)) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Missing term or key.' // @translate
            ));
        }

        $key = (int) $key;

        $resourceData = $contribution->proposalToResourceData($term, $key);
        if (!$resourceData) {
            return $this->jSend(JSend::FAIL, null, $this->translate(
                'Contribution is not valid.' // @translate
            ));
        }

        $errorStore = new ErrorStore();
        $resource = $this->validateOrCreateOrUpdate($contribution, $resourceData, $errorStore, true, false, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jSend(JSend::FAIL, $errorStore->getErrors() ?: null, $this->translate(
                'Contribution is not valid: check values.' // @translate
            ));
        }
        if (!$resource) {
            return $this->jSend(JSend::ERROR, null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend(JSend::SUCCESS, [
            // Status is updated, so inverted.
            'contribution' => [
                'status' => 'validated-value',
                'statusLabel' => $this->translate('Validated value'), // @translate
            ],
        ]);
    }

    /**
     * Fill a message with placeholders (moustache style).
     */
    protected function fillMessage(?string $message, array $placeholders = []): string
    {
        if (empty($message)) {
            return (string) $message;
        }

        $plugins = $this->getPluginManager();
        $url = $plugins->get('url');
        $settings = $plugins->get('settings')();
        // $site = $this->currentSite();

        $replace = $placeholders;

        $defaultPlaceholders = [
            '{ip}' => (new RemoteAddress())->getIpAddress(),
            '{main_title}' => $settings->get('installation_title', 'Omeka S'),
            '{main_url}' => $url->fromRoute('top', [], ['force_canonical' => true]),
            // '{site_title}' => $site->title(),
            // '{site_url}' => $site->siteUrl(null, true),
        ];
        $replace += $defaultPlaceholders;

        return strtr($message, $replace);
    }
}
