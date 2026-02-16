<?php declare(strict_types=1);

namespace Contribute\Controller\Admin;

use Common\Stdlib\PsrMessage;
use Contribute\Controller\ContributionTrait;
use Contribute\Form\QuickSearchForm;
use Contribute\Form\SendMessageForm;
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

        $settings = $this->settings();

        $subject = $this->settings()->get('contribute_message_author_mail_subject')
            ?: $this->translate($this->defaultMessages['contribute_message_author_mail_subject']);
        $body = $this->settings()->get('contribute_message_author_mail_body')
            ?: $this->translate($this->defaultMessages['contribute_message_author_mail_body']);

        $messageData = [
            'subject' => $this->fillMessage($subject),
            'body' => $this->fillMessage($body),
            'myself' => $settings->get('contribute_send_message_recipient_myself', []) ?: [],
            'cc' => $settings->get('contribute_send_message_recipients_cc') ?: [],
            'bcc' => $settings->get('contribute_send_message_recipients_bcc') ?: [],
            'reply' => $settings->get('contribute_send_message_recipients_reply') ?: [],
        ];

        /** @var \Contribute\Form\SendMessageForm $formSendMessage */
        $formSendMessage = $this->getForm(SendMessageForm::class);
        $formSendMessage
            ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'send-message'], true))
            ->setAttribute('id', 'contribute-send-message-form')
            ->populateValues($messageData);

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
        if (isset($data['undertaken'])) {
            if ($data['undertaken'] === '0') {
                $data['undertaken'] = '00';
            } elseif ($params['undertaken'] === '00') {
                $params['undertaken'] = '0';
            }
        }
        if (isset($data['validated'])) {
            if ($data['validated'] === '0') {
                $data['validated'] = '00';
            } elseif ($params['validated'] === '00') {
                $params['validated'] = '0';
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

        // Quick check for missing files in unvalidated contributions
        // (not yet transformed into a resource).
        $basePath = $this->getEvent()->getApplication()->getServiceManager()
            ->get('Config')['file_store']['local']['base_path']
            ?: (OMEKA_PATH . '/files');
        $contributionPath = $basePath . '/contribution/';
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('c.id', 'c.proposal')
            ->from(\Contribute\Entity\Contribution::class, 'c')
            ->where('c.resource IS NULL');
        $rows = $qb->getQuery()->getArrayResult();
        $missingFiles = [];
        foreach ($rows as $row) {
            $proposal = $row['proposal'] ?? [];
            foreach ($proposal['media'] ?? [] as $mediaData) {
                foreach ($mediaData['file'] ?? [] as $fileData) {
                    $store = $fileData['proposed']['store'] ?? null;
                    if ($store && !file_exists($contributionPath . $store)) {
                        $missingFiles[$row['id']][] = $fileData['proposed']['@value'] ?? $store;
                    }
                }
            }
        }
        if ($missingFiles) {
            $list = [];
            foreach ($missingFiles as $id => $files) {
                $list[] = sprintf('Contribution #%d: %s', $id, implode(', ', $files));
            }
            $message = new PsrMessage(
                '{count} contribution(s) without resource have missing files in /files/contribution: {list}', // @translate
                ['count' => count($missingFiles), 'list' => implode(' ; ', $list)]
            );
            $this->messenger()->addWarning($message);
        }

        return new ViewModel([
            'contributions' => $contributions,
            'resources' => $contributions,
            'formSendMessage' => $formSendMessage,
            'formSearch' => $formSearch,
            'formDeleteSelected' => $formDeleteSelected,
            'formDeleteAll' => $formDeleteAll,
            'contributionsMissingFiles' => array_keys($missingFiles),
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

        // Check missing files for this contribution.
        $missingFiles = [];
        if (!$contribution->resource()) {
            $basePath = $this->getEvent()->getApplication()->getServiceManager()
                ->get('Config')['file_store']['local']['base_path']
                ?: (OMEKA_PATH . '/files');
            $contributionPath = $basePath . '/contribution/';
            foreach ($contribution->proposalMedias() as $mediaData) {
                foreach ($mediaData['file'] ?? [] as $fileData) {
                    $store = $fileData['proposed']['store'] ?? null;
                    if ($store && !file_exists($contributionPath . $store)) {
                        $missingFiles[] = $fileData['proposed']['@value'] ?? $store;
                    }
                }
            }
        }

        $view = new ViewModel([
            'linkTitle' => $linkTitle,
            'resource' => $contribution,
            'values' => json_encode([]),
            'missingFiles' => $missingFiles,
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
        $resourceName = $this->easyMeta()->resourceName($resourceType);
        if (!in_array($resourceName, ['items', 'media', 'item_sets'])) {
            $this->messenger()->addError('You can create token only for items, media and item sets.'); // @translate
            return $params['redirect']
                ? $this->redirect()->toUrl($params['redirect'])
                : $this->redirect()->toRoute('admin');
        }

        $defaultSite = $this->viewHelpers()->get('defaultSite');
        $siteSlug = $defaultSite('slug');
        if ($siteSlug === null) {
            $this->messenger()->addError('A site is required to create a public token.'); // @translate
            return $params['redirect']
                ? $this->redirect()->toUrl($params['redirect'])
                : $this->redirect()->toRoute('admin/default', ['controller' => $resourceType, 'action' => 'browse'], true);
        }

        // Normalize the resource type for controller url.
        $resourceTypeMap = [
            'items' => 'item',
            'media' => 'media',
            'item_sets' => 'item-set',
        ];
        $resourceType = $resourceTypeMap[$resourceName];

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
            $resourceIds = $this->api()->search($resourceName, $query, ['returnScalar' => 'id'])->getContent();
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
        $tokenDuration = $this->settingTemplateOrMainOrConfig($resourceIds, 'contribute_token_duration', false);
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

    public function expireTokenAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend()->fail(null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        // Only people who can edit the resource can validate.
        $id = $this->params('id');
        if (empty($id)) {
            $token = $this->params()->fromQuery('token');
            if (empty($token)) {
                return $this->jSend()->fail(null, $this->translate(
                    'Resource not found.' // @translate
                ), HttpResponse::STATUS_CODE_404);
            }
            /** @var \Contribute\Api\Representation\TokenRepresentation $token */
            try {
                $token = $this->api()->read('contribution_tokens', ['token' => $token])->getContent();
            } catch (\Exception $e) {
                return $this->jSend()->fail(null, $this->translate(
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
            $canEditWithoutToken = $canContribute($contribution ? $contribution->resourceTemplate() : null, true);
            if (!$canEditWithoutToken) {
                return $this->jSend()->fail(null, $this->translate(
                    'Unauthorized access.' // @translate
                ), HttpResponse::STATUS_CODE_401);
            }
            return $this->jSend()->success([
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
                return $this->jSend()->error(null, $this->translate(
                    'An internal error occurred.' // @translate
                ));
            }
        }

        return $this->jSend()->success([
            'contribution_token' => [
                'status' => 'expired',
                'statusLabel' => $this->translate('Expired'), // @translate
            ],
        ]);
    }

    public function toggleUndertakingAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend()->fail(null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        try {
            $contribution = $this->api()->read('contributions', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend()->fail(null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        $wasUndertaken = $contribution->isUndertaken();

        // If there is a resource, it is always undertaken (except patch).
        $resource = $contribution ? $contribution->resource() : null;
        if ($resource && !$contribution->isPatch()) {
            // Set the contribution undertaking flag true if it is not set.
            if (!$wasUndertaken) {
                $data = [];
                $data['o-module-contribute:undertaken'] = true;
                $response = $this->api()
                    ->update('contributions', $id, $data, [], ['isPartial' => true]);
                if (!$response) {
                    return $this->jSend()->error(null, $this->translate(
                        'An internal error occurred.' // @translate
                    ));
                }
                $contribution = $response->getContent();
            }
            return $this->jSend()->success([
                'contribution' => $contribution->jsonSerialize()+ [
                    'status' => 'undertaken',
                    'statusLabel' => $this->translate('Undertaken'), // @translate
                ],
            ]);
        }

        // Only people who can edit the resource can update the status.
        if ($resource && !$resource->userIsAllowed('update')) {
            return $this->jSend()->fail(null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        // In other cases, toggle the flag.
        $data = [];
        $data['o-module-contribute:undertaken'] = !$wasUndertaken;
        $response = $this->api()
            ->update('contributions', $id, $data, [], ['isPartial' => true]);
        if (!$response) {
            return $this->jSend()->error(null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        $contribution = $response->getContent();

        return $this->jSend()->success([
            // Status is updated, so inverted.
            'contribution' => $contribution->jsonSerialize()+ [
                'status' => $wasUndertaken ? 'not-undertaken' : 'undertaken',
                'statusLabel' => $wasUndertaken
                    ? $this->translate('Not undertaken') // @translate
                    : $this->translate('Undertaken'), // @translate
            ],
        ]);
    }

    public function toggleStatusAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend()->fail(null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        try {
            $contribution = $this->api()->read('contributions', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend()->fail(null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // Now, the status validated can be used without resource.
        $resource = $contribution ? $contribution->resource() : null;

        // Only people who can edit the resource can update the status.
        if ($resource && !$resource->userIsAllowed('update')) {
            return $this->jSend()->fail(null, $this->translate('Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        // This flag is three-states: null > true > false.
        $wasValidated = $contribution->isValidated();
        $willBeValidated = $wasValidated === null ? true : ($wasValidated ? false : null);

        $data = [];
        $data['o-module-contribute:validated'] = $willBeValidated;
        $response = $this->api()
            ->update('contributions', $id, $data, [], ['isPartial' => true]);
        if (!$response) {
            return $this->jSend()->error(null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        $contribution = $response->getContent();

        $statuses = [
            null => 'undetermined',
            1 => 'validated',
            0 => 'not-validated',
        ];
        $labels = [
            null => $this->translate('Undetermined'), // @translate,
            1 => $this->translate('Validated'), // @translate,
            0 => $this->translate('Rejected'), // @translate,
        ];

        return $this->jSend()->success([
            'contribution' => $contribution->jsonSerialize() + [
                'status' => $statuses[$willBeValidated === null ? null : (int) $willBeValidated],
                'statusLabel' => $labels[$willBeValidated === null ? null : (int) $willBeValidated],
            ],
        ]);
    }

    public function createResourceAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend()->fail(null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        try {
            $contribution = $this->api()->read('contributions', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend()->fail(null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // If there is a resource, it can't be created.
        $contributionResource = $contribution->resource();
        if ($contributionResource) {
            return $this->jSend()->fail(null, $this->translate(
                'Resource exists already.' // @translate
            ), HttpResponse::STATUS_CODE_400);
        }

        // Only people who can create resource can validate.
        $acl = $contribution->getServiceLocator()->get('Omeka\Acl');
        if (!$acl->userIsAllowed(\Omeka\Api\Adapter\ItemAdapter::class, 'create')) {
            return $this->jSend()->fail(null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $resourceData = $contribution->proposalToResourceData();
        if (!$resourceData) {
            return $this->jSend()->fail(null, $this->translate(
                'Contribution is not valid: check template.' // @translate
            ));
        }

        // Validate and create the resource.
        $errorStore = new ErrorStore();
        $resource = $this->validateOrCreateOrUpdate($contribution, $resourceData, $errorStore, true, null, false, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jSend()->fail($errorStore->getErrors() ?: null, $this->translate(
                'Contribution cannot be created: some values are not valid.' // @translate
            ));
        }
        if (!$resource) {
            return $this->jSend()->error(null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        // Toggle the flag undertaken.
        $data = [];
        $data['o-module-contribute:undertaken'] = true;
        try {
            $response = $this->api()
                ->update('contributions', $id, $data, [], ['isPartial' => true]);
        } catch (\Exception $e) {
        }

        // Check if the template should be converted.
        $resourceTemplate = $contribution->resourceTemplate();
        $message = null;
        if ($resourceTemplate) {
            $convertTemplate = $resourceTemplate->dataValue('contribute_template_convert');
            if ($convertTemplate) {
                /** @var \Omeka\Mvc\Controller\Plugin\Api $api */
                $api = $this->api();
                try {
                    $convertTemplate = $api->read('resource_templates', is_numeric($convertTemplate) ? ['id' => $convertTemplate] : ['label' => $convertTemplate])->getContent();
                } catch (\Exception $e) {
                    $message = new PsrMessage(
                        'The template "{val}" to convert into from template {template_id} does not exist or is not valid. The conversion is skipped', // @translate
                        ['val' => $convertTemplate, 'template_id' => $resourceTemplate->id()]
                    );
                    $this->messenger()->addError($message);
                }
                if ($convertTemplate) {
                    if ($convertTemplate->id() === $resourceTemplate->id()) {
                        $message = new PsrMessage(
                            'The template "{val}" should be converted to the same template. Check the template settings.', // @translate
                            ['val' => $convertTemplate, 'template_id' => $resourceTemplate->id()]
                        );
                        $this->messenger()->addWarning($message);
                    } else {
                        // Option "skipValidation" is specific to AdvancedResourceTemplate.
                        // The validation is done only against initial template.
                        try {
                            $response = $api
                                ->update(
                                    $resource->resourceName(),
                                    $resource->id(),
                                    ['o:resource_template' => ['o:id' => $convertTemplate->id()]],
                                    [],
                                    ['isPartial' => true, 'skipValidation' => true]
                                );
                        } catch (\Exception $e) {
                            $response = null;
                        }
                        // The resource is already created, so just warn about the
                        // issue, that should be fixed manually by the admin.
                        if (!$response) {
                            $message = new PsrMessage(
                                'The template "{template_id}" to convert into from template {template_id_2} does not validate the resource. Check the consistency between the two termplates or missing required values in the second template.', // @translate
                                ['template_id' => $convertTemplate->id(), 'template_id_2' => $resourceTemplate->id()]
                            );
                            $this->messenger()->addError($message);
                        } else {
                            // Generally the final resource template form is not
                            // consistent with the new one.
                            $resource = $response->getContent();
                        }
                    }
                }
            }
        }

        return $this->jSend()->success([
            'contribution' => $contribution->jsonSerialize() + [
                'is_new' => true,
                'url' => $resource->adminUrl(),
            ],
        ]);
    }

    public function validateAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend()->fail(null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        try {
            $contribution = $this->api()->read('contributions', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend()->fail(null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // If there is no resource, create it as a whole.
        $contributionResource = $contribution->resource();

        // Only people who can edit the resource can validate.
        if (($contributionResource && !$contributionResource->userIsAllowed('update'))
            || (!$contributionResource && !$contribution->getServiceLocator()->get('Omeka\Acl')->userIsAllowed('Omeka\Api\Adapter\ItemAdapter', 'create'))
        ) {
            return $this->jSend()->fail(null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $resourceData = $contribution->proposalToResourceData();
        if (!$resourceData) {
            return $this->jSend()->fail(null, $this->translate(
                'Contribution is not valid.' // @translate
            ));
        }

        // Validate and update the resource.
        $errorStore = new ErrorStore();
        $resource = $this->validateOrCreateOrUpdate($contribution, $resourceData, $errorStore, true, true, false, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jSend()->fail($errorStore->getErrors() ?: null, $this->translate(
                'Contribution is not valid: check its values.' // @translate
            ));
        }
        if (!$resource) {
            return $this->jSend()->error(null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend()->success([
            // Status is updated, so inverted.
            'contribution' => $contribution->jsonSerialize() + [
                'status' => 'validated',
                'statusLabel' => $this->translate('Validated'), // @translate
                'is_new' => !$contribution->isPatch(),
                'url' => $resource->adminUrl(),
            ],
        ]);
    }

    public function validateValueAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend()->fail(null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $id = $this->params('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        try {
            $contribution = $this->api()->read('contributions', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend()->fail(null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // A resource is required to update it.
        $contributionResource = $contribution->resource();
        if (!$contributionResource) {
            return $this->jSend()->fail(null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        // Only people who can edit the resource can validate.
        if (!$contributionResource->userIsAllowed('update')) {
            return $this->jSend()->fail(null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $term = $this->params()->fromQuery('term');
        $key = $this->params()->fromQuery('key');
        if (!$term || !is_numeric($key)) {
            return $this->jSend()->fail(null, $this->translate(
                'Missing term or key.' // @translate
            ));
        }

        $key = (int) $key;

        $resourceData = $contribution->proposalToResourceData($term, $key);
        if (!$resourceData) {
            return $this->jSend()->fail(null, $this->translate(
                'Contribution is not valid.' // @translate
            ));
        }

        // Validate the value for the resource.
        $errorStore = new ErrorStore();
        $resource = $this->validateOrCreateOrUpdate($contribution, $resourceData, $errorStore, true, '', false, false);
        if ($errorStore->hasErrors()) {
            // Keep similar messages different to simplify debug.
            return $this->jSend()->fail($errorStore->getErrors() ?: null, $this->translate(
                'Contribution is not valid: check values.' // @translate
            ));
        }
        if (!$resource) {
            return $this->jSend()->error(null, $this->translate(
                'An internal error occurred.' // @translate
            ));
        }

        return $this->jSend()->success([
            // Status is updated, so inverted.
            'contribution' => [
                'status' => 'validated-value',
                'statusLabel' => $this->translate('Validated value'), // @translate
            ],
        ]);
    }

    public function sendMessageAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return $this->jSend()->fail(null, $this->translate(
                'Method not allowed.' // @translate
            ), HttpResponse::STATUS_CODE_405);
        }

        $params = $this->params();
        $id = $params->fromRoute('id');

        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        try {
            $contribution = $this->api()->read('contributions', $id)->getContent();
        } catch (\Exception $e) {
            return $this->jSend()->fail(null, $this->translate(
                'Resource not found.' // @translate
            ), HttpResponse::STATUS_CODE_404);
        }

        /** @var \Omeka\Entity\User $user */
        $user = $this->identity();
        if (!$user) {
            return $this->jSend()->fail(null, $this->translate(
                'Unauthorized access.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        $updateContribution = (bool) $params->fromPost('reject', false);
        if ($updateContribution && !$contribution->userIsAllowed('update')) {
            return $this->jSend()->fail(null, $this->translate(
                'The user has no right to update the contribution.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        if ($updateContribution && $contribution->resource()) {
            return $this->jSend()->fail(null, $this->translate(
                'The contribution is already validated and status cannot be changed.' // @translate
            ), HttpResponse::STATUS_CODE_401);
        }

        // No check for validity, since this is a message in admin.
        // Furthermore, the csrf is not updated for each post and may be false.
        // Anyway, this is just pure text sent by admin.

        // TODO Fill message sent?

        $body = (string) $params->fromPost('body');
        $body = trim((string) $body);

        if (!strlen($body)) {
            return $this->jSend()->fail(null, $this->translate(
                'Empty message.' // @translate
            ));
        }

        if (mb_strlen($body) > 10000) {
            return $this->jSend()->fail(null, $this->translate(
                'Too long message.' // @translate
            ));
        }

        $subject = $params->fromPost('subject');
        $subject = trim((string) $subject);
        if (!strlen($subject)) {
            $subject = $this->settings()->get('contribute_message_author_mail_subject')
                ?: $this->translate($this->defaultMessages['contribute_message_author_mail_subject']);
            $subject = $this->fillMessage($subject);
        }

        if (mb_strlen($subject) > 190) {
            return $this->jSend()->fail(null, $this->translate(
                'Too long subject.' // @translate
            ));
        }

        $owner = $contribution->owner();
        $toEmail = $contribution->email() ?: ($owner ? $owner->email() : null);
        if (!$toEmail) {
            return $this->jSend()->fail(null, $this->translate(
                'No email defined for this contribution.' // @translate
            ));
        }

        $post = $params->fromPost();
        // Skip subject and body that are checked early.
        $post['subject'] = '-';
        $post['body'] = '-';

        /** @var \Contribute\Form\SendMessageForm $form */
        $form = $this->getForm(SendMessageForm::class);
        $form->setData($post);
        if (!$form->isValid()) {
            // $this->messenger()->addFormErrors($form);
            return $this->jSend()->fail([
                'form' => $form->getMessages(),
            ]);
        }

        $data = $form->getData();

        $to = [$toEmail => $owner ? $owner->name() : ''];
        $cc = $data['cc'] ?? [];
        $bcc = $data['bcc'] ?? [];
        $replyTo = $data['reply'] ?? [];
        $myself = $data['myself'] ?? [];

        if (in_array('cc', $myself)) {
            $cc[$user->getEmail()] = $user->getName();
        }
        if (in_array('bcc', $myself)) {
            $bcc[$user->getEmail()] = $user->getName();
        }
        if (in_array('reply', $myself)) {
            $replyTo[$user->getEmail()] = $user->getName();
        }

        $cc = array_filter($cc);
        $bcc = array_filter($bcc);
        $replyTo = array_filter($replyTo);

        /** @see \Common\Mvc\Controller\Plugin\SendEmail */
        $result = $this->sendEmail($body, $subject, $to, null, $cc, $bcc, $replyTo);
        if (!$result) {
            return $this->jSend()->error(null, $this->translate(
                'Sorry, the message cannot be sent. Contact the administrator.' // @translate
            ));
        }

        if ($updateContribution) {
            $data = [];
            $data['o-module-contribute:submitted'] = false;
            // $data['o-module-contribute:undertaken'] = true;
            // $data['o-module-contribute:validated'] = null;
            $response = $this->api()
                ->update('contributions', $id, $data, [], ['isPartial' => true]);
            // Normally, there is never an issue here.
            if (!$response) {
                return $this->jSend()->error(null, $this->translate(
                    'An internal error occurred.' // @translate
                ));
            }
        }

        $message = new PsrMessage(
            'Message successfully sent to {email}.', // @translate
            ['email' => $toEmail]
        );
        return $this->jSend()->success([
            'contribution' => $contribution,
        ], $message->setTranslator($this->translator()));
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
