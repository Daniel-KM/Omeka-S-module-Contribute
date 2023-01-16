<?php declare(strict_types=1);

namespace Contribute\Controller\Site;

use Contribute\Api\Representation\ContributionRepresentation;
use Contribute\Controller\ContributionTrait;
use Contribute\Form\ContributeForm;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
// TODO Use the admin resource form, but there are some differences in features (validation by field, possibility to update the item before validate correction, anonymous, fields is more end user friendly and enough in most of the case), themes and security issues, so not sure it is simpler.
// use Omeka\Form\ResourceForm;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\File\TempFileFactory;
use Omeka\File\Uploader;
use Omeka\Stdlib\ErrorStore;
use Omeka\Stdlib\Message;

class ContributionController extends AbstractActionController
{
    use ContributionTrait;

    /**
     * @var \Omeka\File\Uploader
     */
    protected $uploader;

    /**
     * @var \Omeka\File\TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var string
     */
    protected $string;

    /**
     * @var array
     */
    protected $config;

    public function __construct(
        Uploader $uploader,
        TempFileFactory $tempFileFactory,
        EntityManager $entityManager,
        $basePath,
        array $config
    ) {
        $this->uploader = $uploader;
        $this->tempFileFactory = $tempFileFactory;
        $this->entityManager = $entityManager;
        $this->basePath = $basePath;
        $this->config = $config;
    }

    public function showAction()
    {
        $site = $this->currentSite();
        $resourceType = $this->params('resource');
        $resourceId = $this->params('id');

        $resourceTypeMap = [
            'contribution' => 'Contribute\Controller\Site\Contribution',
            'item' => 'Omeka\Controller\Site\Item',
            'media' => 'Omeka\Controller\Site\Media',
            'item-set' => 'Omeka\Controller\Site\ItemSet',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }

        if ($resourceType !== 'contribution') {
            $this->forward()->dispatch($resourceTypeMap[$resourceType], [
                'site-slug' => $this->currentSite()->slug(),
                'controller' => $resourceType,
                'action' => 'show',
                'id' => $resourceId,
            ]);
        }

        // Rights are automatically checked.
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $contribution = $this->api()->read('contributions', ['id' => $resourceId])->getContent();

        $space = $this->params('space', 'default');

        $view = new ViewModel([
            'site' => $site,
            'resource' => $contribution->resource(),
            'contribution' => $contribution,
            'space' => $space,
        ]);
        return $view
            ->setTemplate($space === 'guest'
                ? 'guest/site/guest/contribution-show'
                : 'contribute/site/contribution/show'
            );
    }

    /**
     * The action "view" is a proxy to "show", that cannot be used because it is
     * used by the resources.
     * @deprecated Use show.
     */
    public function viewAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'show';
        return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
    }

    public function addAction()
    {
        $site = $this->currentSite();
        $resourceType = $this->params('resource');

        $resourceTypeMap = [
            'contribution' => 'items',
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }

        if ($resourceType === 'contribution') {
            $resourceType = 'item';
        }
        // TODO Use the resource name to store the contribution (always items here for now).
        $resourceName = $resourceTypeMap[$resourceType];

        $user = $this->identity();
        $settings = $this->settings();
        $contributeMode = $settings->get('contribute_mode');
        $contributeRoles = $settings->get('contribute_roles', []) ?: [];
        $canEditWithoutToken = $contributeMode === 'open'
            || ($user && $contributeMode === 'user')
            || ($user && $contributeMode === 'role' && in_array($user->getRole(), $contributeRoles));

        // TODO Allow to use a token to add a resource.
        // $token = $this->checkToken($resource);
        $token = null;
        // Check rights to edit without token.
        if (!$token && !$canEditWithoutToken) {
            return $this->viewError403();
        }

        // Prepare the resource template. Use the first if not queryied.

        /** @var \Contribute\Mvc\Controller\Plugin\ContributiveData $contributiveData */
        $contributiveData = $this->getPluginManager()->get('contributiveData');
        $allowedResourceTemplates = $this->settings()->get('contribute_templates', []);
        $templates = [];
        $templateLabels = [];
        // Remove non-contributive templates.
        foreach ($this->api()->search('resource_templates', ['id' => $allowedResourceTemplates])->getContent() as $template) {
            $contributive = $contributiveData($template);
            if ($contributive->isContributive()) {
                $templates[$template->id()] = $template;
                $templateLabels[$template->id()] = $template->label();
            }
        }

        $params = $this->params();

        // When there is an id, it means to show template readonly, else forward
        // to edit.
        $resourceId = $params->fromRoute('id') ?? null;
        if ($resourceId) {
            // Edition is always the right contribution or resource.
            $resourceTypeMap['contribution'] = 'contributions';
            $resourceName = $resourceTypeMap[$this->params('resource')];
            // Rights are automatically checked.
            /** @var \Contribute\Api\Representation\ContributionRepresentation|\Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
            $resource = $this->api()->read($resourceName, ['id' => $resourceId])->getContent();
            $resourceTemplate = $resource->resourceTemplate();
            if ($resourceTemplate) {
                $contributive = clone $contributiveData($resourceTemplate);
                $resourceTemplate = $contributive->template();
            }
            $template = $resourceTemplate ? $resourceTemplate->id() : -1;
        } else {
            // A template is required to contribute: set by query or previous form.
            $template = $params->fromQuery('template') ?: $params->fromPost('template');
            /** @var \Contribute\Mvc\Controller\Plugin\ContributiveData $contributive */
            if ($template) {
                $contributive = clone $contributiveData($template);
                $resourceTemplate = $contributive->template();
            }
        }

        $space = $this->params('space', 'default');

        if (!count($templates) || ($template && !$resourceTemplate)) {
            $this->logger()->err('A template is required to add a resource. Ask the administrator for more information.'); // @translate
            $view = new ViewModel([
                'site' => $site,
                'user' => $user,
                'form' => null,
                'contribution' => null,
                'resource' => null,
                'fields' => [],
                'fieldsByMedia' => [],
                'fieldsMediaBase' => [],
                'action' => 'add',
                'mode' => 'read',
                'space' => $space,
            ]);
            return $view
                ->setTemplate($space === 'guest'
                    ? 'guest/site/guest/contribution-edit'
                    : 'contribute/site/contribution/edit'
                );
        }

        if (!$template) {
            if (count($templates) === 1) {
                $resourceTemplate = reset($templates);
                $contributive = clone $contributiveData($template);
            } else {
                $resourceTemplate = null;
            }
        }

        $mode = $resourceId || $params->fromPost('mode', 'write') === 'read' ? 'read' : 'write';

        /** @var \Contribute\Form\ContributeForm $form */
        $formOptions = [
            'templates' => $templateLabels,
            'display_select_template' => $mode === 'read' || $resourceId || !$resourceTemplate,
        ];
        $form = $this->getForm(ContributeForm::class, $formOptions)
            // Use setOptions() + init(), not getForm(), because of the bug in csrf / getForm().
            // ->setOptions($formOptions)
            ->setAttribute('enctype', 'multipart/form-data')
            ->setAttribute('id', 'edit-resource');

        if ($mode === 'read') {
            $form->setDisplayTemplateSelect(true);
            $form->setAttribute('class', 'readonly');
            $form->get('template')->setAttribute('readonly', 'readonly');
            $form->get('submit')->setAttribute('disabled', 'disabled');
            $form->get('mode')->setValue('read');
        }
        if ($resourceTemplate) {
            $form->get('template')->setValue($resourceTemplate->id());
        }

        // First step: select a template if not set. Else mode is read only.
        // The read-only allows to use multi-steps form.
        if (!$resourceTemplate || $mode === 'read') {
            $view = new ViewModel([
                'site' => $site,
                'user' => $user,
                'form' => $form,
                'contribution' => $resourceId && $resource && $resource instanceof ContributionRepresentation ? $resource : null,
                'resource' => $resourceId && $resource && $resource instanceof AbstractResourceEntityRepresentation ? $resource : null,
                'fields' => [],
                'fieldsByMedia' => [],
                'fieldsMediaBase' => [],
                'action' => 'add',
                'mode' => $resourceId ? 'read' : $mode,
                'space' => $space,
            ]);
            return $view
                ->setTemplate($space === 'guest'
                    ? 'guest/site/guest/contribution-edit'
                    : 'contribute/site/contribution/edit'
                );
        }

        // In all other cases (second step), the mode is write, else the called
        // method would be edit.
        if ($resourceId) {
            $params = $this->params()->fromRoute();
            $params['action'] = 'edit';
            return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
        }

        $step = $params->fromPost('step');

        // Second step: fill the template and create a contribution, even partial.
        $hasError = false;
        if ($this->getRequest()->isPost() && $step !== 'template') {
            $post = $params->fromPost();
            // The template cannot be changed once set.
            $post['template'] = $resourceTemplate->id();
            $form->setData($post);
            // TODO There is no check currently (html form), except the csrf.
            if ($form->isValid()) {
                // TODO There is no validation by the form, except csrf, since elements are added through views. So use form (but includes non-updatable values, etc.).
                // $data = $form->getData();
                $data = array_diff_key($post, ['csrf' => null, 'edit-resource-submit' => null]);
                $data = $this->checkAndIncludeFileData($data);
                // To simplify process, a direct submission is made with a
                // create then an update.
                $allowUpdate = $this->settings()->get('contribute_allow_update');
                $isDirectSubmission = $allowUpdate === 'no';
                if (empty($data['has_error'])) {
                    $proposal = $this->prepareProposal($data);
                    if ($proposal) {
                        // When there is a resource, it isn’t updated, but the
                        // proposition of contribution is saved for moderation.
                        $data = [
                            'o:resource' => null,
                            'o:owner' => $user ? ['o:id' => $user->getId()] : null,
                            'o-module-contribute:token' => $token ? ['o:id' => $token->id()] : null,
                            'o:email' => $token ? $token->email() : ($user ? $user->getEmail() : null),
                            'o-module-contribute:patch' => false,
                            'o-module-contribute:submitted' => false,
                            'o-module-contribute:reviewed' => false,
                            'o-module-contribute:proposal' => $proposal,
                        ];
                        $response = $this->api($form)->create('contributions', $data);
                        if ($response) {
                            /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution $content */
                            $contribution = $response->getContent();
                            // $this->prepareContributionEmail($response->getContent(), 'prepare');
                            $eventManager = $this->getEventManager();
                            $eventManager->trigger('contribute.submit', $this, [
                                'contribution' => $contribution,
                                'resource' => null,
                                'data' => $data,
                            ]);
                            // For a direct submission, process via the normal
                            // submission.
                            // Note that the submission may be invalid for now.
                            // TODO Process a direct submission without full validation.
                            if ($isDirectSubmission) {
                                $params = $this->params()->fromRoute();
                                $params['controller'] = 'Contribute\Controller\Site\Contribution';
                                $params['__CONTROLLER__'] = 'contribution';
                                $params['action'] = 'submit';
                                $params['resource'] = 'contribution';
                                $params['id'] = $contribution->id();
                                $params['space'] = $space;
                                return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
                            }
                            $message = $this->settings()->get('contribute_message_add');
                            if ($message) {
                                $this->messenger()->addSuccess($message);
                            } else {
                                $this->messenger()->addSuccess('Contribution successfully saved!'); // @translate
                                $this->messenger()->addWarning('Review it before its submission.'); // @translate
                            }
                            return $contribution->resource()
                                ? $this->redirect()->toUrl($contribution->resource()->siteUrl())
                                : $this->redirectContribution($contribution);
                        }
                    }
                }
            }
            $hasError = true;
        }

        if ($hasError) {
            // TODO Currently, the form has no element, so no validation and no automatic filling.
            $this->messenger()->addError('An error occurred: check your input.'); // @translate
            $this->messenger()->addFormErrors($form);
            // So create a fake contribution to fill form.
            $contribution = $this->fakeContribution($post);
        } else {
            $contribution = null;
        }

        /** @var \Contribute\View\Helper\ContributionFields $contributionFields */
        $contributionFields = $this->viewHelpers()->get('contributionFields');
        $fields = $contributionFields(null, $contribution, $resourceTemplate);

        // Only items can have a sub resource template for medias.
        // A media template may have no fields but it should be prepared anyway.
        if (in_array($resourceName, ['contributions', 'items']) && $contributive->contributiveMedia()) {
            $resourceTemplateMedia = $contributive->contributiveMedia()->template();
            $fieldsByMedia = [];
            foreach ($contribution ? array_keys($contribution->proposalMedias()) : [] as $indexProposalMedia) {
                // TODO Match resource medias and contribution (for now only allowed until submission).
                $indexProposalMedia = (int) $indexProposalMedia;
                $fieldsByMedia[] = $contributionFields(null, $contribution, $resourceTemplateMedia, true, $indexProposalMedia);
            }
            // Add a list of fields without values for new media.
            $fieldsMediaBase = $contributionFields(null, $contribution, $contributive->contributiveMedia()->template(), true);
        } else {
            $resourceTemplateMedia = null;
            $fieldsByMedia = [];
            $fieldsMediaBase = [];
        }

        $view = new ViewModel([
            'site' => $site,
            'user' => $user,
            'form' => $form,
            'contribution' => null,
            'resource' => null,
            'fields' => $fields,
            'templateMedia' => $resourceTemplateMedia,
            'fieldsByMedia' => $fieldsByMedia,
            'fieldsMediaBase' => $fieldsMediaBase,
            'action' => 'add',
            'mode' => 'write',
            'space' => $space,
        ]);
        return $view
            ->setTemplate($space === 'guest'
                ? 'guest/site/guest/contribution-edit'
                : 'contribute/site/contribution/edit'
            );
    }

    /**
     * Edit a new contribution or an existing item.
     *
     * Indeed, there are two types of edition:
     * - edit a contribution not yet approved, so the user is editing his
     *   contribution, one or multiple times;
     * - edit an existing item or resource, so this is a correction and each
     *   correction is a new correction (or a patch).
     *
     * It is always possible to correct an item, but a new contribution cannot
     * cannot be modified after validation.
     *
     * Furthermore, the edition of a new contribution can be done in multi-steps
     * (template choice, metadata, files and medatada of files).
     *
     * @todo Separate all possible workflows.
     * @todo Move all the process to prepare data to view helper conributionForm().
     *
     * @return mixed|\Laminas\View\Model\ViewModel|\Laminas\Http\Response
     */
    public function editAction()
    {
        $params = $this->params();
        $mode = ($params->fromPost('mode') ?? $params->fromQuery('mode', 'write')) === 'read' ? 'read' : 'write';
        $isModeRead = $mode === 'read';
        $isModeWrite = !$isModeRead;
        $next = $params->fromQuery('next') ?? $params->fromPost('next') ?? '';
        if ($isModeRead && strpos($next, 'template') !== false) {
            $params = $params->fromRoute();
            $params['action'] = 'add';
            return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
        }

        $site = $this->currentSite();
        $api = $this->api();
        $resourceType = $params->fromRoute('resource');
        $resourceId = $params->fromRoute('id');

        // Unlike addAction(), edition is always the right contribution or
        // resource.
        $resourceTypeMap = [
            'contribution' => 'contributions',
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }

        $resourceName = $resourceTypeMap[$resourceType];

        // Rights are automatically checked.
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $resource = $api->read($resourceName, ['id' => $resourceId])->getContent();

        $user = $this->identity();
        $settings = $this->settings();
        $contributeMode = $settings->get('contribute_mode');
        $contributeRoles = $settings->get('contribute_roles', []) ?: [];
        $canEditWithoutToken = $contributeMode === 'open'
            || ($user && $contributeMode === 'user')
            || ($user && $contributeMode === 'role' && in_array($user->getRole(), $contributeRoles));

        // This is a contribution or a correction.
        $isContribution = $resourceName === 'contributions';
        if ($isContribution) {
            /**
             * @var \Contribute\Api\Representation\ContributionRepresentation|null $contribution
             * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation|null $resource
             */
            $contribution = $resource;
            $resource = $contribution->resource();
            $resourceTemplate = $contribution->resourceTemplate();
            $currentUrl = $this->url()->fromRoute(null, [], true);
        } else {
            $contribution = null;
            $token = $this->checkToken($resource);
            if (!$token && !$canEditWithoutToken) {
                return $this->viewError403();
            }

            // There may be no contribution when it is a correction.
            // But if a user edit a resource, the last contribution is used.
            // Nevertheless, the user should be able to see previous corrections
            // and to do a new correction.
            if ($token) {
                $contribution = $api
                    ->searchOne('contributions', ['resource_id' => $resourceId, 'token_id' => $token->id(), 'sort_by' => 'id', 'sort_order' => 'desc'])
                    ->getContent();
                $currentUrl = $this->url()->fromRoute(null, [], ['query' => ['token' => $token->token()]], true);
            } elseif ($user) {
                $contribution = $api
                    ->searchOne('contributions', ['resource_id' => $resourceId, 'email' => $user->getEmail(), 'sort_by' => 'id', 'sort_order' => 'desc'])
                    ->getContent();
                $currentUrl = $this->url()->fromRoute(null, [], true);
            } else {
                // An anonymous user cannot see existing contributions.
                $contribution = null;
                $currentUrl = $this->url()->fromRoute(null, [], true);
            }

            $resourceTemplate = $resource->resourceTemplate();
        }

        $space = $this->params('space', 'default');

        /** @var \Contribute\Mvc\Controller\Plugin\ContributiveData $contributive */
        $contributive = clone $this->contributiveData($resourceTemplate);
        if (!$resourceTemplate || !$contributive->isContributive()) {
            $this->logger()->warn('This resource cannot be edited: no resource template, no fields, or not allowed.'); // @translate
            $view = new ViewModel([
                'site' => $site,
                'user' => $user,
                'form' => null,
                'contribution' => $contribution,
                'resource' => $resource,
                'fields' => [],
                'templateMedia' => null,
                'fieldsByMedia' => [],
                'fieldsMediaBase' => [],
                'action' => 'edit',
                'mode' => 'read',
                'space' => $space,
            ]);
            return $view
                ->setTemplate($space === 'guest'
                    ? 'guest/site/guest/contribution-edit'
                    : 'contribute/site/contribution/edit'
                );
        }

        // $formOptions = [
        // ];

        /** @var \Contribute\Form\ContributeForm $form */
        $form = $this->getForm(ContributeForm::class)
            ->setAttribute('action', $currentUrl)
            ->setAttribute('enctype', 'multipart/form-data')
            ->setAttribute('id', 'edit-resource');

        if ($isModeRead) {
            $form->setAttribute('class', 'readonly');
            $form->get('template')->setAttribute('readonly', 'readonly');
            $form->get('submit')->setAttribute('disabled', 'disabled');
            $form->get('mode')->setValue('read');
        }

        $allowUpdate = $this->settings()->get('contribute_allow_update');
        $allowUpdateUntilValidation = $allowUpdate === 'validation';
        $isCorrection = !$contribution || $contribution->isPatch();

        if (!$isCorrection
            && $isModeWrite
            && !$allowUpdateUntilValidation
            && $contribution
            && $contribution->isSubmitted()
        ) {
            $this->messenger()->addWarning('This contribution has been submitted and cannot be edited.'); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/contribution-id' : 'site/contribution-id', ['action' => 'view'], true);
        }

        // When a user wants to edit a resource, create a new correction.
        if ($isCorrection
            && $isModeWrite
            && !$allowUpdateUntilValidation
            && $contribution
            && $contribution->isSubmitted()
        ) {
            $contribution = null;
        } elseif ($isCorrection
            && $isModeWrite
            && $allowUpdateUntilValidation
            && $contribution
            && $contribution->isReviewed()
        ) {
            $contribution = null;
        }

        // No need to set the template, but simplify view for form.
        $form->get('template')->setValue($resourceTemplate->id());

        // There is no step for edition: the resource template is always set.

        $hasError = false;
        if ($this->getRequest()->isPost()) {
            $post = $params->fromPost();
            // The template cannot be changed once set.
            $post['template'] = $resourceTemplate->id();
            $form->setData($post);
            // TODO There is no check currently (html form), except the csrf.
            if ($isModeWrite && $form->isValid()) {
                // $data = $form->getData();
                $data = array_diff_key($post, ['csrf' => null, 'edit-resource-submit' => null]);
                $data = $this->checkAndIncludeFileData($data);
                if (empty($data['has_error'])) {
                    $proposal = $this->prepareProposal($data, $resource);
                    if ($proposal) {
                        // The resource isn’t updated, but the proposition of
                        // contribute is saved for moderation.
                        $response = null;
                        if (empty($contribution)) {
                            $data = [
                                'o:resource' => $resourceId ? ['o:id' => $resourceId] : null,
                                'o:owner' => $user ? ['o:id' => $user->getId()] : null,
                                'o-module-contribute:token' => $token ? ['o:id' => $token->id()] : null,
                                'o:email' => $token ? $token->email() : ($user ? $user->getEmail() : null),
                                // Here, it's always a patch, else use "add".
                                'o-module-contribute:patch' => true,
                                // A patch is always a submission.
                                'o-module-contribute:submitted' => true,
                                'o-module-contribute:reviewed' => false,
                                'o-module-contribute:proposal' => $proposal,
                            ];
                            $response = $this->api($form)->create('contributions', $data);
                            if ($response) {
                                $this->messenger()->addSuccess('Contribution successfully submitted!'); // @translate
                                // $this->prepareContributionEmail($response->getContent(), 'submit');
                            }
                        } elseif ($contribution->isSubmitted() && !$allowUpdateUntilValidation) {
                            $this->messenger()->addWarning('This contribution is already submitted and cannot be updated.'); // @translate
                            $response = $this->api()->read('contributions', $contribution->id());
                        } elseif ($proposal === $contribution->proposal()) {
                            $this->messenger()->addWarning('No change.'); // @translate
                            $response = $this->api()->read('contributions', $contribution->id());
                        } else {
                            $data = [
                                'o-module-contribute:reviewed' => false,
                                'o-module-contribute:proposal' => $proposal,
                            ];
                            $response = $this->api($form)->update('contributions', $contribution->id(), $data, [], ['isPartial' => true]);
                            if ($response) {
                                $message = $this->settings()->get('contribute_message_edit');
                                if ($message) {
                                    $this->messenger()->addSuccess($message);
                                } else {
                                    $this->messenger()->addSuccess('Contribution successfully updated!'); // @translate
                                }
                                // $this->prepareContributionEmail($response->getContent(), 'update');
                            }
                        }
                        if ($response) {
                            $eventManager = $this->getEventManager();
                            $eventManager->trigger('contribute.submit', $this, [
                                'contribution' => $contribution,
                                'resource' => $resource,
                                'data' => $data,
                            ]);
                            /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution $content */
                            $contribution = $response->getContent();
                            return $contribution->resource()
                                ? $this->redirect()->toUrl($contribution->resource()->siteUrl())
                                : $this->redirectContribution($contribution);
                        }
                    }
                }
            }
            $hasError = $isModeWrite;
        }

        if (strpos($next, 'template') !== false) {
            $params = $params->fromRoute();
            $params['action'] = 'add';
            return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
        }

        if ($hasError) {
            // TODO Currently, the form has no element, so no validation and no automatic filling.
            $this->messenger()->addError('An error occurred: check your input.'); // @translate
            $this->messenger()->addFormErrors($form);
            // So create a fake contribution to fill form.
            $contribution = $this->fakeContribution($post, $contribution);
        }

        /** @var \Contribute\View\Helper\ContributionFields $contributionFields */
        $contributionFields = $this->viewHelpers()->get('contributionFields');
        $fields = $contributionFields($resource, $contribution);

        // Only items can have a sub resource template for medias.
        // A media template may have no fields but it should be prepared anyway.
        if (in_array($resourceName, ['contributions', 'items']) && $contributive->contributiveMedia()) {
            $resourceTemplateMedia = $contributive->contributiveMedia()->template();
            $fieldsByMedia = [];
            foreach ($contribution ? array_keys($contribution->proposalMedias()) : [] as $indexProposalMedia) {
                // TODO Match resource medias and contribution (for now only allowed until submission).
                $indexProposalMedia = (int) $indexProposalMedia;
                $fieldsByMedia[] = $contributionFields(null, $contribution, $resourceTemplateMedia, true, $indexProposalMedia);
            }
            // Add a list of fields without values for new media.
            $fieldsMediaBase = $contributionFields(null, null, $contributive->contributiveMedia()->template(), true);
        } else {
            $resourceTemplateMedia = null;
            $fieldsByMedia = [];
            $fieldsMediaBase = [];
        }

        $view = new ViewModel([
            'site' => $site,
            'user' => $user,
            'form' => $form,
            'contribution' => $contribution,
            'resource' => $resource,
            'fields' => $fields,
            'templateMedia' => $resourceTemplateMedia,
            'fieldsByMedia' => $fieldsByMedia,
            'fieldsMediaBase' => $fieldsMediaBase,
            'action' => 'edit',
            'mode' => $mode,
            'space' => $space,
        ]);
        return $view
            ->setTemplate($space === 'guest'
                ? 'guest/site/guest/contribution-edit'
                : 'contribute/site/contribution/edit'
            );
    }

    public function deleteConfirmAction(): void
    {
        throw new \Omeka\Mvc\Exception\PermissionDeniedException('The delete confirm action is currently unavailable'); // @translate
    }

    public function deleteAction()
    {
        $id = $this->params('id');
        $space = $this->params('space', 'default');

        if (!$this->getRequest()->isPost()) {
            $this->messenger()->addError(new Message('Deletion can be processed only with a post.')); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/contribution-id' : 'site/contribution-id', ['action' => 'show'], true);
        }

        $resource = $this->api()->read('contributions', $id)->getContent();
        if ($resource->isSubmitted()) {
            $this->messenger()->addWarning('This contribution has been submitted and cannot be deleted.'); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/contribution-id' : 'site/contribution-id', ['action' => 'view'], true);
        }

        $response = $this->api()->delete('contributions', $id);
        if ($response) {
            $this->messenger()->addSuccess('Contribution successfully deleted'); // @translate
        }

        // TODO Update route when a main public browse of contributions will be available.
        return $this->redirect()->toRoute('site/guest/contribution', ['action' => 'browse'], true);
    }

    public function submitAction()
    {
        $resourceType = $this->params('resource');
        $resourceId = $this->params('id');
        $space = $this->params('space', 'default');

        // Unlike addAction(), submission is always the right contribution or
        // resource.
        $resourceTypeMap = [
            'contribution' => 'contributions',
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }

        $resourceName = $resourceTypeMap[$resourceType];

        // Only whole contribution can be submitted: a patch is always submitted
        // directly.
        if ($resourceName !== 'contributions') {
            // TODO The user won't see this warning.
            $this->messenger()->addWarning('Only a whole contribution can be submitted.'); // @translate
            return $this->redirect()->toRoute('site/resource-id', ['action' => 'show'], true);
        }

        $api = $this->api();

        // Rights are automatically checked.
        /** @var \Contribute\Api\Representation\ContributionRepresentation $contribution */
        $contribution = $api->read('contributions', ['id' => $resourceId])->getContent();

        if ($contribution->isSubmitted()) {
            $this->messenger()->addWarning('This contribution has already been submitted.'); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/contribution-id' : 'site/contribution-id', ['action' => 'view'], true);
        }

        if (!$contribution->userIsAllowed('update')) {
            $this->messenger()->addError('Only the contributor can submit the contribution.'); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/contribution-id' : 'site/contribution-id', ['action' => 'view'], true);
        }

        // Validate the contribution with the contribution process.
        $resourceData = $contribution->proposalToResourceData();
        if (!$resourceData) {
            $message = new Message(
                'Contribution is not valid: check template.' // @translate
            );
            $this->messenger()->addError($message); // @translate
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/contribution-id' : 'site/contribution-id', ['action' => 'view'], true);
        }

        // Validate the contribution with the api process.
        $errorStore = new ErrorStore();
        $this->validateOrCreateOrUpdate($contribution, $resourceData, $errorStore, false, true, true);
        if ($errorStore->hasErrors()) {
            return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/contribution-id' : 'site/contribution-id', ['action' => 'view'], true);
        }

        $data = [];
        $data['o-module-contribute:submitted'] = true;
        $response = $api
            ->update('contributions', $resourceId, $data, [], ['isPartial' => true]);
        if (!$response) {
            $this->messenger()->addError('An error occurred: check your submission or ask an administrator.'); // @translate
            return $this->jsonErrorUpdate();
        }

        $this->messenger()->addSuccess('Contribution successfully submitted!'); // @translate
        $contribution = $response->getContent();
        $this
            ->notifyContribution($contribution, 'submit')
            ->confirmContribution($contribution, 'submit');

        return $this->redirect()->toRoute($space === 'guest' ? 'site/guest/contribution-id' : 'site/contribution-id', ['action' => 'view'], true);
    }

    /**
     * Manage a special redirection in order to manage complex form workflow.
     *
     * It uses the post value "next" (generally hidden), that can be overridden
     * by the query key "next".
     *
     * It allows to add files when separated in the form or to use a specific
     * show view to confirm.
     * Next step can be another "add", "edit" or "show" (default).
     * A query can be appended, separated with a "-", to be used in theme.
     */
    protected function redirectContribution(ContributionRepresentation $contribution)
    {
        $params = $this->params();
        $next = $params->fromQuery('next') ?? $params->fromPost('next') ?? '';
        $space = $this->params('space', 'default');
        if (!$next) {
            return $this->redirect()->toUrl($contribution->siteUrl(null, false, 'view', $space === 'guest'));
        }
        [$nextAction, $nextQuery] = strpos($next, '-') === false ? [$next, null] : explode('-', $next, 2);
        if (!$nextAction || $nextAction === 'show' || $nextAction === 'view') {
            $nextAction = null;
        }
        if ($nextQuery) {
            $nextQuery = '?next=' . rawurlencode($next);
        }
        return $this->redirect()->toUrl($contribution->siteUrl(null, false, $nextAction, $space === 'guest') . $nextQuery);
    }

    /**
     * Create a fake contribution with data proposal.
     *
     * Should be used only for post issue: only data proposal are set and should
     * be used.
     *
     * @todo Remove fake contribution with a real form.
     */
    protected function fakeContribution(array $data, ?ContributionRepresentation $contribution = null): ContributionRepresentation
    {
        $adapterManager = $this->currentSite()->getServiceLocator()->get('Omeka\ApiAdapterManager');
        $contributionAdapter = $adapterManager->get('contributions');

        $entity = new \Contribute\Entity\Contribution();
        if ($contribution) {
            if ($resource = $contribution->resource()) {
                $entity->setResource($this->api()->read('resources', ['id' => $resource->id()], ['responseContent' => 'resource'])->getContent());
            }
            $entity->setReviewed($contribution->isReviewed());
        }

        unset($data['csrf'], $data['edit-resource-submit']);
        $proposal = $this->prepareProposal($data) ?: [];
        $entity->setProposal($proposal);

        return new ContributionRepresentation($entity, $contributionAdapter);
    }

    protected function notifyContribution(ContributionRepresentation $contribution, string $action = 'update'): self
    {
        $emails = $this->filterEmails($contribution);
        if (empty($emails)) {
            return $this;
        }

        $translate = $this->getPluginManager()->get('translate');
        $actions = [
            'prepare' => $translate('prepare'), // @translate
            'update' => $translate('update'), // @translate
            'submit' => $translate('submit'), // @translate
        ];

        $action = isset($actions[$action]) ? $action : 'update';
        $actionMsg = $actions[$action];
        $contributionResource = $contribution->resource();
        $user = $this->identity();

        $settings = $this->settings();
        $subject = $settings->get('contribute_reviewer_confirmation_subject') ?: sprintf($translate('[Omeka] Contribution %s'), $action);
        $message = $settings->get('contribute_reviewer_confirmation_body');

        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
        $template = $contribution->resourceTemplate();
        if ($template) {
            $subject = $template->dataValue('contribute_reviewer_confirmation_subject') ?: $subject;
            $message = $template->dataValue('contribute_reviewer_confirmation_body') ?: $message;
        }

        if ($message) {
            $message = $this->replacePlaceholders($message, $contribution);
            $this->sendContributionEmail($emails, $subject, $message); // @translate
            return $this;
        }

        // Default message.
        switch (true) {
            case $contributionResource && $user:
                $message = '<p>' . new Message(
                    'User %1$s has made a contribution for resource #%2$s (%3$s) (action: %4$s).', // @translate
                    '<a href="' . $this->url()->fromRoute('admin/id', ['controller' => 'user', 'id' => $user->getId()], ['force_canonical' => true]) . '">' . $user->getName() . '</a>',
                    '<a href="' . $contributionResource->adminUrl('show', true) . '#contribution">' . $contributionResource->id() . '</a>',
                    $contributionResource->displayTitle(),
                    $actionMsg
                ) . '</p>';
                break;
            case $contributionResource:
                $message = '<p>' . new Message(
                    'An anonymous user has made a contribution for resource #%1$s (%2$s) (action: %3$s).', // @translate
                    '<a href="' . $contributionResource->adminUrl('show', true) . '#contribution">' . $contributionResource->id() . '</a>',
                    $contributionResource->displayTitle(),
                    $actionMsg
                ) . '</p>';
                break;
            case $user:
                $message = '<p>' . new Message(
                    'User %1$s has made a contribution (action: %2$s).', // @translate
                    '<a href="' . $this->url()->fromRoute('admin/id', ['controller' => 'user', 'id' => $user->getId()], ['force_canonical' => true]) . '">' . $user->getName() . '</a>',
                    $actionMsg
                ) . '</p>';
                break;
            default:
                $message = '<p>' . new Message(
                    'An anonymous user has made a contribution (action: %1$s).', // @translate
                    $actionMsg
                ) . '</p>';
                break;
        }

        $this->sendContributionEmail($emails, $subject, $message); // @translate
        return $this;
    }

    protected function confirmContribution(ContributionRepresentation $contribution, string $action = 'update'): self
    {
        $settings = $this->settings();
        $confirms = $settings->get('contribute_author_confirmations', []);
        if (empty($confirms) || !in_array($action, $confirms)) {
            return $this;
        }

        $emails = $this->authorEmails($contribution);
        if (empty($emails)) {
            $this->messenger()->err('The author of this contribution has no valid email. Check it or check the config.'); // @translate
            return $this;
        }

        $translate = $this->getPluginManager()->get('translate');

        $subject = $settings->get('contribute_author_confirmation_subject') ?: $translate('[Omeka] Contribution');
        $message = $settings->get('contribute_author_confirmation_body') ?: new Message(
            "Hi,\nThanks for your contribution.\n\nThe administrators will validate it as soon as possible.\n\nSincerely," // @translate
        );

        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
        $template = $contribution->resourceTemplate();
        if ($template) {
            $subject = $template->dataValue('contribute_author_confirmation_subject') ?: $subject;
            $message = $template->dataValue('contribute_author_confirmation_body') ?: $message;
        }

        $message = $this->replacePlaceholders($message, $contribution);

        $message = '<p>' . $message . '</p>';

        $name = count($emails) === 1 && $contribution->owner() ? $contribution->owner()->name() : null;

        $this->sendContributionEmail($emails, $subject, $message, $name); // @translate
        return $this;
    }

    protected function replacePlaceholders($message, ?ContributionRepresentation $contribution): string
    {
        if (strpos($message, '{') === false || !$contribution) {
            return (string) $message;
        }

        $url = $this->viewHelpers()->get('url');
        $api = $this->api();
        $settings = $this->settings();

        $replace = [];
        foreach ($contribution->proposalToResourceData() as $term => $value) {
            if (!is_array($value) || empty($value) || !isset(reset($value)['type'])) {
                continue;
            }
            $first = reset($value);
            if (!empty($first['@id'])) {
                $replace['{' . $term . '}'] = $first['@id'];
            } elseif (!empty($first['value_resource_id'])) {
                try {
                    $replace['{' . $term . '}'] = $api->read('resources', ['id' => $first['value_resource_id']], [], ['initialize' => false, 'finalize' => false])->getContent()->getTitle();
                } catch (\Exception $e) {
                    $replace['{' . $term . '}'] = $this->translate('[Unknown resource]'); // @translate
                }
            } elseif (isset($first['@value']) && strlen((string) $first['@value'])) {
                $replace['{' . $term . '}'] = $first['@value'];
            }
        }

        if ($contribution) {
            $replace['{resource_id}'] = $contribution->id();
            $owner = $contribution->owner();
            $replace['{user_name}'] = $owner ? $owner->name() : $this->translate('[Anonymous]'); // @translate
            $replace['{user_id}'] = $owner ? $owner->id() : 0;
            $replace['{user_email}'] = $contribution->email();
            // Like module Contact Us.
            $replace['{email}'] = $contribution->email();
        }

        $replace['{main_title}'] = $settings->get('installation_title', 'Omeka S');
        $replace['{main_url}'] = $url('top', [], ['force_canonical' => true]);
        // TODO Currently, the site is not stored, so use main title and main url.
        $replace['{site_title}'] = $replace['{main_title}'];
        $replace['{site_url}'] = $replace['{main_url}'];

        // TODO Store and add ip.

        return str_replace(array_keys($replace), array_values($replace), $message);
    }

    protected function filterEmails(?ContributionRepresentation $contribution = null): array
    {
        $emails = $this->settings()->get('contribute_notify_recipients', []);
        if (empty($emails)) {
            return [];
        }

        if (!$contribution) {
            return $emails;
        }

        $result = [];
        foreach ($emails as $email) {
            [$email, $query] = explode(' ', $email . ' ', 2);
            if ($email
                && filter_var($email, FILTER_VALIDATE_EMAIL)
                && $contribution->match($query)
            ) {
                $result[] = $email;
            }
        }

        return $result;
    }

    protected function authorEmails(?ContributionRepresentation $contribution = null): array
    {
        $emails = [];
        $propertyEmails = $this->settings()->get('contribute_author_emails', ['owner'])  ?: ['owner'];

        /*
        if ($contribution && !in_array('owner', $propertyEmails)) {
            $propertyEmails[] = 'owner';
        }
        */

        $resourceData = $contribution ? $contribution->proposalToResourceData() : [];

        foreach ($propertyEmails as $propertyEmail) {
            if ($propertyEmail === 'owner') {
                $owner = $contribution ? $contribution->owner() : null;
                if ($owner) {
                    $emails[] = $owner->email();
                }
            } elseif (strpos($propertyEmail, ':') && !empty($resourceData[$propertyEmail])) {
                foreach ($resourceData[$propertyEmail] as $resourceValue) {
                    if (isset($resourceValue['@value'])) {
                        $emails[] = $resourceValue['@value'];
                    }
                }
            }
        }

        foreach ($emails as $key => $email) {
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                unset($emails[$key]);
            }
        }

        return $emails;
    }

    /**
     * Prepare the proposal for saving.
     *
     * The check is done comparing the keys of original values and the new ones.
     *
     * @todo Factorize with \Contribute\View\Helper\ContributionFields
     * @todo Factorize with \Contribute\Api\Representation\ContributionRepresentation::proposalNormalizeForValidation()
     * @todo Factorize with \Contribute\Api\Representation\ContributionRepresentation::proposalToResourceData()
     *
     * @todo Simplify when the status "is patch" or "new resource" (at least remove all original data).
     */
    protected function prepareProposal(
        array $proposal,
        ?AbstractResourceEntityRepresentation $resource = null,
        ?bool $isSubTemplate = false
    ): ?array {
        $isSubTemplate = (bool) $isSubTemplate;

        // It's not possible to change the resource template of a resource in
        // public side.
        // A resource can be corrected only with a resource template (require
        // editable or fillable keys).
        if ($resource) {
            $resourceTemplate = $resource->resourceTemplate();
        } elseif (isset($proposal['template'])) {
            $resourceTemplate = $proposal['template'] ?? null;
            $resourceTemplate = $this->api()->searchOne('resource_templates', is_numeric($resourceTemplate) ? ['id' => $resourceTemplate] : ['label' => $resourceTemplate])->getContent();
        } else {
            $resourceTemplate = null;
        }
        if (!$resourceTemplate) {
            return null;
        }

        // The contribution requires a resource template in allowed templates.
        /** @var \Contribute\Mvc\Controller\Plugin\ContributiveData $contributive */
        $contributive = clone $this->contributiveData($resourceTemplate, $isSubTemplate);
        if (!$contributive->isContributive()) {
            return null;
        }

        $resourceTemplate = $contributive->template();
        $result = [
            'template' => $resourceTemplate->id(),
            'media' => [],
        ];

        // File is specific: for media only, one value only, not updatable,
        // not a property and not in resource template.
        if (isset($proposal['file'][0]['@value']) && $proposal['file'][0]['@value'] !== '') {
            $store = $proposal['file'][0]['store'] ?? null;
            $result['file'] = [];
            $result['file'][0] = [
                'original' => [
                    '@value' => null,
                ],
                'proposed' => [
                    '@value' => $proposal['file'][0]['@value'],
                    $store ? 'store' : 'file' => $store ?? $proposal['file'][0]['file'],
                ],
            ];
        }

        // Clean data for the special keys.
        $proposalMedias = $isSubTemplate ? [] : ($proposal['media'] ?? []);
        unset($proposal['template'], $proposal['media']);

        foreach ($proposal as &$values) {
            // Manage specific posts.
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as &$value) {
                if (isset($value['@value'])) {
                    $value['@value'] = $this->cleanString($value['@value']);
                }
                if (isset($value['@resource'])) {
                    $value['@resource'] = (int) $value['@resource'];
                }
                if (isset($value['@uri'])) {
                    $value['@uri'] = $this->cleanString($value['@uri']);
                }
                if (isset($value['@label'])) {
                    $value['@label'] = $this->cleanString($value['@label']);
                }
                if (isset($value['@language'])) {
                    $value['@language'] = $this->cleanString($value['@language']);
                }
            }
        }
        unset($values, $value);

        $propertyIds = $this->propertyIdsByTerms();
        $customVocabBaseTypes = $this->viewHelpers()->get('customVocabBaseType')();

        // Process only editable keys.

        // Process editable properties first.
        // TODO Remove whitelist/blacklist since a resource template is required (but take care of updated template).
        $matches = [];
        switch ($contributive->editableMode()) {
            case 'whitelist':
                $proposalEditableTerms = array_keys(array_intersect_key($proposal, $contributive->editableProperties()));
                break;
            case 'blacklist':
                $proposalEditableTerms = array_keys(array_diff_key($proposal, $contributive->editableProperties()));
                break;
            case 'all':
            default:
                $proposalEditableTerms = array_keys($proposal);
                break;
        }

        foreach ($proposalEditableTerms as $term) {
            // File is a special type: for media only, single value only, not updatable.
            if ($term === 'file') {
                continue;
            }

            /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
            $values = $resource ? $resource->value($term, ['all' => true]) : [];
            foreach ($values as $index => $value) {
                if (!isset($proposal[$term][$index])) {
                    continue;
                }
                $type = $value->type();
                if (!$contributive->isTermDatatype($term, $type)) {
                    continue;
                }

                $typeColon = strtok($type, ':');
                $baseType = null;
                $uriLabels = [];
                if ($typeColon === 'customvocab') {
                    $customVocabId = (int) substr($type, 12);
                    $baseType = $customVocabBaseTypes[$customVocabId] ?? 'literal';
                    $uriLabels = $this->customVocabUriLabels($customVocabId);
                }

                // If a lang was set in the original value, it is kept, else use
                // the posted one, else use the default one of the template.
                $lang = $value->lang() ?: null;
                if (!$lang) {
                    if (!empty($proposal[$term][$index]['@language'])) {
                        $lang = $proposal[$term][$index]['@language'];
                    } else {
                        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $templateProperty */
                        $templateProperty = $resourceTemplate->resourceTemplateProperty($value->property()->id());
                        if ($templateProperty) {
                            $lang = $templateProperty->mainDataValue('default_language') ?: null;
                        }
                    }
                }

                switch ($type) {
                    case 'literal':
                    case 'boolean':
                    case 'html':
                    case 'xml':
                    case $typeColon === 'numeric':
                    case $typeColon === 'customvocab' && $baseType === 'literal':
                        if (!isset($proposal[$term][$index]['@value'])) {
                            continue 2;
                        }
                        $prop = [
                            'original' => [
                                '@value' => $value->value(),
                            ],
                            'proposed' => [
                                '@value' => $proposal[$term][$index]['@value'],
                            ],
                        ];
                        break;
                    case $typeColon === 'resource':
                    case $typeColon === 'customvocab' && $baseType === 'resource':
                        if (!isset($proposal[$term][$index]['@resource'])) {
                            continue 2;
                        }
                        $vr = $value->valueResource();
                        $prop = [
                            'original' => [
                                '@resource' => $vr ? $vr->id() : null,
                            ],
                            'proposed' => [
                                '@resource' => (int) $proposal[$term][$index]['@resource'] ?: null,
                            ],
                        ];
                        break;
                    case $typeColon === 'customvocab' && $baseType === 'uri':
                        $proposedValue['@label'] = $uriLabels[$proposal[$term][$index]['@uri'] ?? ''] ?? '';
                        // no break.
                    case 'uri':
                        if (!isset($proposal[$term][$index]['@uri'])) {
                            continue 2;
                        }
                        $proposal[$term][$index] += ['@label' => ''];
                        $prop = [
                            'original' => [
                                '@uri' => $value->uri(),
                                '@label' => $value->value(),
                            ],
                            'proposed' => [
                                '@uri' => $proposal[$term][$index]['@uri'],
                                '@label' => $proposal[$term][$index]['@label'],
                            ],
                        ];
                        break;
                    case $typeColon === 'valuesuggest':
                    case $typeColon === 'valuesuggestall':
                        if (!isset($proposal[$term][$index]['@uri'])) {
                            continue 2;
                        }
                        if (!preg_match('~^<a href="(.+)" target="_blank">\s*(.+)\s*</a>$~', $proposal[$term][$index]['@uri'], $matches)) {
                            continue 2;
                        }
                        if (!filter_var($matches[1], FILTER_VALIDATE_URL)) {
                            continue 2;
                        }
                        $proposal[$term][$index]['@uri'] = $matches[1];
                        $proposal[$term][$index]['@label'] = $matches[2];
                        $prop = [
                            'original' => [
                                '@uri' => $value->uri(),
                                '@label' => $value->value(),
                            ],
                            'proposed' => [
                                '@uri' => $proposal[$term][$index]['@uri'],
                                '@label' => $proposal[$term][$index]['@label'],
                            ],
                        ];
                        break;
                    default:
                        // Nothing to do.
                        continue 2;
                }
                if ($lang) {
                    $prop['proposed']['@language'] = $lang;
                }
                $result[$term][] = $prop;
            }
        }

        // Append fillable properties.
        switch ($contributive->fillableMode()) {
            case 'whitelist':
                $proposalFillableTerms = array_keys(array_intersect_key($proposal, $contributive->fillableProperties()));
                break;
            case 'blacklist':
                $proposalFillableTerms = array_diff_key($proposal, $contributive->fillableProperties());
                break;
            case 'all':
            default:
                $proposalFillableTerms = array_keys($proposal);
                break;
        }

        foreach ($proposalFillableTerms as $term) {
            if (!isset($propertyIds[$term])) {
                continue;
            }

            /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $templateProperty */
            $templateProperty = null;
            $propertyId = $propertyIds[$term];
            $type = null;
            $typeTemplate = null;
            if ($resourceTemplate) {
                $templateProperty = $resourceTemplate->resourceTemplateProperty($propertyId);
                if ($templateProperty) {
                    $typeTemplate = $templateProperty->dataType();
                }
            }

            $baseType = null;
            $uriLabels = [];
            if (substr((string) $typeTemplate, 0, 12) === 'customvocab:') {
                $customVocabId = (int) substr($typeTemplate, 12);
                $baseType = $customVocabBaseTypes[$customVocabId] ?? 'literal';
                $uriLabels = $this->customVocabUriLabels($customVocabId);
            }

            foreach ($proposal[$term] as $index => $proposedValue) {
                /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
                $values = $resource ? $resource->value($term, ['all' => true]) : [];
                if (isset($values[$index])) {
                    continue;
                }

                if ($typeTemplate) {
                    $type = $typeTemplate;
                } elseif (array_key_exists('@uri', $proposedValue)) {
                    $type = 'uri';
                } elseif (array_key_exists('@resource', $proposedValue)) {
                    $type = 'resource';
                } elseif (array_key_exists('@value', $proposedValue)) {
                    $type = 'literal';
                } else {
                    $type = 'unknown';
                }

                if (!$contributive->isTermDatatype($term, $type)) {
                    continue;
                }

                // Use the posted language, else the default one of the template.
                if (!empty($proposedValue['@language'])) {
                    $lang = $proposedValue['@language'];
                } elseif ($templateProperty) {
                    $lang = $templateProperty->mainDataValue('default_language') ?: null;
                } else {
                    $lang = null;
                }

                $typeColon = strtok($type, ':');
                switch ($type) {
                    case 'literal':
                    case 'boolean':
                    case 'html':
                    case 'xml':
                    case $typeColon === 'numeric':
                    case $typeColon === 'customvocab' && $baseType === 'literal':
                        if (!isset($proposedValue['@value']) || $proposedValue['@value'] === '') {
                            continue 2;
                        }
                        $prop = [
                            'original' => [
                                '@value' => null,
                            ],
                            'proposed' => [
                                '@value' => $proposedValue['@value'],
                            ],
                        ];
                        break;
                    case $typeColon === 'resource':
                    case $typeColon === 'customvocab' && $baseType === 'resource':
                        if (!isset($proposedValue['@resource']) || !(int) $proposedValue['@resource']) {
                            continue 2;
                        }
                        $prop = [
                            'original' => [
                                '@resource' => null,
                            ],
                            'proposed' => [
                                '@resource' => (int) $proposedValue['@resource'],
                            ],
                        ];
                        break;
                    case $typeColon === 'customvocab' && $baseType === 'uri':
                        $proposedValue['@label'] = $uriLabels[$proposedValue['@uri'] ?? ''] ?? '';
                        // no break.
                    case 'uri':
                        if (!isset($proposedValue['@uri']) || $proposedValue['@uri'] === '') {
                            continue 2;
                        }
                        $proposedValue += ['@label' => ''];
                        $prop = [
                            'original' => [
                                '@uri' => null,
                                '@label' => null,
                            ],
                            'proposed' => [
                                '@uri' => $proposedValue['@uri'],
                                '@label' => $proposedValue['@label'],
                            ],
                        ];
                        break;
                    case $typeColon === 'valuesuggest':
                    case $typeColon === 'valuesuggestall':
                        if (!isset($proposedValue['@uri']) || $proposedValue['@uri'] === '') {
                            continue 2;
                        }
                        if (!preg_match('~^<a href="(.+)" target="_blank">\s*(.+)\s*</a>$~', $proposal[$term][$index]['@uri'], $matches)) {
                            continue 2;
                        }
                        if (!filter_var($matches[1], FILTER_VALIDATE_URL)) {
                            continue 2;
                        }
                        $proposedValue['@uri'] = $matches[1];
                        $proposedValue['@label'] = $matches[2];
                        $prop = [
                            'original' => [
                                '@uri' => null,
                                '@label' => null,
                            ],
                            'proposed' => [
                                '@uri' => $proposedValue['@uri'],
                                '@label' => $proposedValue['@label'],
                            ],
                        ];
                        break;
                    default:
                        // Nothing to do.
                        continue 2;
                }
                if ($lang) {
                    $prop['proposed']['@language'] = $lang;
                }
                $result[$term][] = $prop;
            }
        }

        if (!$isSubTemplate) {
            $contributiveMedia = $contributive->contributiveMedia();
            if ($contributiveMedia) {
                $templateMedia = $contributiveMedia->template()->id();
                foreach ($proposalMedias ?: [] as $indexProposalMedia => $proposalMedia) {
                    // TODO Currently, only new media are managed as sub-resource: contribution for new resource, not contribution for existing item with media at the same time.
                    $proposalMedia['template'] = $templateMedia;
                    $proposalMediaClean = $this->prepareProposal($proposalMedia, null, true);
                    // Skip empty media (without keys "template" and "media").
                    if (count($proposalMediaClean) > 2) {
                        $result['media'][$indexProposalMedia] = $proposalMediaClean;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Early check files and move data into main data.
     *
     * @todo Use the error store when the form will be ready and use only adapter anyway.
     */
    protected function checkAndIncludeFileData(array $data): array
    {
        $translate = $this->getPluginManager()->get('translate');
        $uploadErrorCodes = [
            UPLOAD_ERR_OK => $translate('File successfuly uploaded.'), // @translate
            UPLOAD_ERR_INI_SIZE => $translate('The total of file sizes exceeds the the server limit directive.'), // @translate
            UPLOAD_ERR_FORM_SIZE => $translate('The file size exceeds the specified limit.'), // @translate
            UPLOAD_ERR_PARTIAL => $translate('The file was only partially uploaded.'), // @translate
            UPLOAD_ERR_NO_FILE => $translate('No file was uploaded.'), // @translate
            UPLOAD_ERR_NO_TMP_DIR => $translate('The temporary folder to store the file is missing.'), // @translate
            UPLOAD_ERR_CANT_WRITE => $translate('Failed to write file to disk.'), // @translate
            UPLOAD_ERR_EXTENSION => $translate('A PHP extension stopped the file upload.'), // @translate
        ];

        // Make format compatible with default Omeka.
        // Only one file by media.
        $uploadeds = $this->getRequest()->getFiles()->toArray();
        $hasError = false;
        // TODO Support edition of a media directly (not in a sub template).
        foreach ($uploadeds['media'] ?? [] as $key => $mediaFiles) {
            $uploadeds['media'][$key]['file'] = empty($mediaFiles['file']) ? [] : array_values($mediaFiles['file']);
            foreach ($uploadeds['media'][$key]['file'] as $mediaFile) {
                $uploaded = $mediaFile['@value'];
                if (empty($uploaded) || $uploaded['error'] == UPLOAD_ERR_NO_FILE) {
                    unset($data['media'][$key]['file']);
                } elseif ($uploaded['error']) {
                    $hasError = true;
                    unset($data['media'][$key]['file']);
                    $this->messenger()->addError(new Message(
                        'File %s: %s', // @translate
                        $key, $uploadErrorCodes[$uploaded['error']]
                    ));
                } elseif (!$uploaded['size']) {
                    $hasError = true;
                    unset($data['media'][$key]['file']);
                    $this->messenger()->addError(new Message(
                        'Empty file for key %s', // @translate
                        $key
                    ));
                } else {
                    // Don't use uploader here, but only in adapter, else
                    // Laminas will believe it's an attack after renaming.
                    $tempFile = $this->tempFileFactory->build();
                    $tempFile->setSourceName($uploaded['name']);
                    $tempFile->setTempPath($uploaded['tmp_name']);
                    if (!(new \Omeka\File\Validator())->validate($tempFile)) {
                        $hasError = true;
                        unset($data['media'][$key]['file']);
                        $this->messenger()->addError(new Message(
                            'Invalid file type for key %s', // @translate
                            $key
                        ));
                    } else {
                        // Take care of automatic rename of uploader (not used).
                        $data['media'][$key]['file'] = [
                            [
                                '@value' => $uploaded['name'],
                                'file' => $uploaded,
                            ],
                        ];
                    }
                }
            }
        }
        if ($hasError) {
            $data['error'] = true;
        }
        return $data;
    }

    /**
     * Trim and normalize end of lines of a string.
     */
    protected function cleanString($string): string
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], trim((string) $string));
    }

    /**
     * Helper to return a message of error as normal view.
     */
    protected function viewError403(): ViewModel
    {
        // TODO Return a normal page instead of an exception.
        // throw new \Omeka\Api\Exception\PermissionDeniedException('Forbidden access.');
        $message = 'Forbidden access.'; // @translate
        $this->getResponse()
            ->setStatusCode(\Laminas\Http\Response::STATUS_CODE_403);
        $view = new ViewModel([
            'message' => $message,
        ]);
        return $view
            ->setTemplate('error/403');
    }
}
