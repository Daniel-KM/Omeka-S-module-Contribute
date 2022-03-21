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

        return new ViewModel([
            'site' => $site,
            'resource' => $contribution,
            'contribution' => $contribution,
        ]);
    }

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

        $settings = $this->settings();
        $user = $this->identity();
        $contributeMode = $settings->get('contribute_mode');

        // TODO Allow to use a token to add a resource.
        // $token = $this->checkToken($resource);
        $token = null;
        if (!$token
            && (
                !in_array($contributeMode, ['user', 'open'])
                || ($contributeMode === 'user' && !$user)
            )
        ) {
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
            /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
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

        if (!count($templates) || ($template && !$resourceTemplate)) {
            $this->logger()->err('A template is required to add a resource. Ask the administrator for more information.'); // @translate
            return new ViewModel([
                'site' => $site,
                'user' => $user,
                'form' => null,
                'resource' => null,
                'contribution' => null,
                'fields' => [],
                'fieldsByMedia' => [],
                'fieldsMediaBase' => [],
                'mode' => 'read',
            ]);
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

        // First step: select a template if not set. Mode read is
        if (!$resourceTemplate || $mode === 'read') {
            return new ViewModel([
                'site' => $site,
                'user' => $user,
                'form' => $form,
                'resource' => $resourceId ? $resource : null,
                'contribution' => $resourceId ? $resource : null,
                'fields' => [],
                'fieldsByMedia' => [],
                'fieldsMediaBase' => [],
                'mode' => $resourceId ? 'read' : $mode,
            ]);
        }

        // In all other cases (second step), the mode is write, else it is edit.
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
                            $this->messenger()->addSuccess('Contribution successfully saved!'); // @translate
                            $this->messenger()->addWarning('Review it before its submission.'); // @translate
                            // $this->prepareContributionEmail($response->getContent(), 'prepared');
                            $eventManager = $this->getEventManager();
                            $eventManager->trigger('contribute.submit', $this, [
                                'contribution' => $response->getContent(),
                                'resource' => null,
                                'data' => $data,
                            ]);
                            $content = $response->getContent();
                            return $content->resource()
                                ? $this->redirect()->toUrl($content->resource()->url())
                                : $this->redirectContribution($content);
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
            $fieldsByMedia = [];
            $fieldsMediaBase = [];
        }

        return new ViewModel([
            'site' => $site,
            'user' => $user,
            'form' => $form,
            'resource' => null,
            'contribution' => null,
            'fields' => $fields,
            'fieldsByMedia' => $fieldsByMedia,
            'fieldsMediaBase' => $fieldsMediaBase,
            'mode' => 'write',
        ]);
    }

    public function editAction()
    {
        $params = $this->params();
        $mode = ($params->fromPost('mode') ?? $params->fromQuery('mode', 'write')) === 'read' ? 'read' : 'write';
        $next = $params->fromQuery('next') ?? $params->fromPost('next') ?? '';
        if ($mode === 'read' && strpos($next, 'template') !== false) {
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

        $settings = $this->settings();
        $user = $this->identity();
        $contributeMode = $settings->get('contribute_mode');

        if ($resourceName === 'contributions') {
            $contribution = $resource;
            $resource = $contribution->resource();
            $resourceTemplate = $contribution->resourceTemplate();
            $currentUrl = $this->url()->fromRoute(null, [], true);
        } else {
            $token = $this->checkToken($resource);
            if (!$token
                && (
                    !in_array($contributeMode, ['user', 'open'])
                    || ($contributeMode === 'user' && !$user)
                )
            ) {
                return $this->viewError403();
            }

            // There may be no contribution when it is a correction.
            if ($token) {
                $contribution = $api
                    ->searchOne('contributions', ['resource_id' => $resourceId, 'token_id' => $token->id()])
                    ->getContent();
                $currentUrl = $this->url()->fromRoute(null, [], ['query' => ['token' => $token->token()]], true);
            } elseif ($user) {
                $contribution = $api
                    ->searchOne('contributions', ['resource_id' => $resourceId, 'email' => $user->getEmail(), 'sort_by' => 'id', 'sort_order' => 'desc'])
                    ->getContent();
                $currentUrl = $this->url()->fromRoute(null, [], true);
            } else {
                $contribution = null;
                $currentUrl = $this->url()->fromRoute(null, [], true);
            }

            $resourceTemplate = $resource->resourceTemplate();
        }

        /** @var \Contribute\Mvc\Controller\Plugin\ContributiveData $contributive */
        $contributive = clone $this->contributiveData($resourceTemplate);
        if (!$resourceTemplate || !$contributive->isContributive()) {
            $this->logger()->warn('This resource cannot be edited: no resource template, no fields, or not allowed.'); // @translate
            return new ViewModel([
                'site' => $site,
                'user' => $user,
                'form' => null,
                'resource' => $contribution,
                'contribution' => $contribution,
                'fields' => [],
                'fieldsByMedia' => [],
                'fieldsMediaBase' => [],
                'mode' => 'read',
            ]);
        }

        // $formOptions = [
        // ];

        /** @var \Contribute\Form\ContributeForm $form */
        $form = $this->getForm(ContributeForm::class)
            ->setAttribute('action', $currentUrl)
            ->setAttribute('enctype', 'multipart/form-data')
            ->setAttribute('id', 'edit-resource');

        if ($mode === 'read') {
            $form->setAttribute('class', 'readonly');
            $form->get('template')->setAttribute('readonly', 'readonly');
            $form->get('submit')->setAttribute('disabled', 'disabled');
            $form->get('mode')->setValue('read');
        }

        if ($contribution && $contribution->isSubmitted() && $mode === 'write') {
            $this->messenger()->addWarning('This contribution has been submitted and cannot be edited.'); // @translate
            return $this->redirect()->toRoute('site/contribution-id', ['action' => 'view'], true);
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
            if ($mode === 'write' && $form->isValid()) {
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
                                'o-module-contribute:patch' => true,
                                // A patch is always a submission.
                                'o-module-contribute:submitted' => true,
                                'o-module-contribute:reviewed' => false,
                                'o-module-contribute:proposal' => $proposal,
                            ];
                            $response = $this->api($form)->create('contributions', $data);
                            if ($response) {
                                $this->messenger()->addSuccess('Contribution successfully submitted!'); // @translate
                                $this->prepareContributionEmail($response->getContent(), 'submitted');
                            }
                        } elseif ($contribution->isSubmitted()) {
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
                                $this->messenger()->addSuccess('Contribution successfully updated!'); // @translate
                                $this->prepareContributionEmail($response->getContent(), 'updated');
                            }
                        }
                        if ($response) {
                            $eventManager = $this->getEventManager();
                            $eventManager->trigger('contribute.submit', $this, [
                                'contribution' => $contribution,
                                'resource' => $resource,
                                'data' => $data,
                            ]);
                            $content = $response->getContent();
                            return $content->resource()
                                ? $this->redirect()->toUrl($content->resource()->url())
                                : $this->redirectContribution($content);
                        }
                    }
                }
            }
            $hasError = $mode === 'write';
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
            $fieldsByMedia = [];
            $fieldsMediaBase = [];
        }

        return new ViewModel([
            'site' => $site,
            'user' => $user,
            'form' => $form,
            'resource' => $contribution,
            'contribution' => $contribution,
            'fields' => $fields,
            'fieldsByMedia' => $fieldsByMedia,
            'fieldsMediaBase' => $fieldsMediaBase,
            'mode' => $mode,
        ]);
    }

    public function deleteConfirmAction()
    {
        throw new \Omeka\Mvc\Exception\PermissionDeniedException('The delete confirm action is currently unavailable');
    }

    public function deleteAction()
    {
        $id = $this->params('id');
        if (!$this->getRequest()->isPost()) {
            $this->messenger()->addError(new Message('Deletion can be processed only with a post.'));
            return $this->redirect()->toRoute('site/contribution-id', ['action' => 'show'], true);
        }
        $resource = $this->api()->read('contributions', $id)->getContent();
        if ($resource->isSubmitted()) {
            $this->messenger()->addWarning('This contribution has been submitted and cannot be deleted.'); // @translate
            return $this->redirect()->toRoute('site/contribution-id', ['action' => 'view'], true);
        }
        $response = $this->api()->delete('contributions', $id);
        if ($response) {
            $this->messenger()->addSuccess('Contribution successfully deleted'); // @translate
        }
        // TODO Update route when a main public browse of contributions will be available.
        return $this->redirect()->toRoute('site/guest/contribution', ['action' => 'show'], true);
    }

    public function submitAction()
    {
        $resourceType = $this->params('resource');
        $resourceId = $this->params('id');

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
            return $this->redirect()->toRoute('site/contribution-id', ['action' => 'view'], true);
        }

        if (!$contribution->userIsAllowed('update')) {
            $this->messenger()->addError('Only the contributor can submit the contribution.'); // @translate
            return $this->redirect()->toRoute('site/contribution-id', ['action' => 'view'], true);
        }

        // Validate the contribution with the contribution process.
        $resourceData = $this->validateContribution($contribution);
        if (!$resourceData) {
            $message = new Message(
                'Contribution is not valid: check template.' // @translate
            );
            $this->messenger()->addError($message); // @translate
            return $this->redirect()->toRoute('site/contribution-id', ['action' => 'view'], true);
        }

        // Validate the contribution with the api process.
        $errorStore = new ErrorStore();
        $this->validateOrCreateOrUpdate($contribution, $resourceData, $errorStore, false, true, true);
        if ($errorStore->hasErrors()) {
            return $this->redirect()->toRoute('site/contribution-id', ['action' => 'view'], true);
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
        $this->prepareContributionEmail($response->getContent(), 'submitted');

        return $this->redirect()->toRoute('site/contribution-id', ['action' => 'view'], true);
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
        if (!$next) {
            return $this->redirect()->toUrl($contribution->url());
        }
        [$nextAction, $nextQuery] = strpos($next, '-') === false ? [$next, null] : explode('-', $next, 2);
        if (!$nextAction || $nextAction === 'show' || $nextAction === 'view') {
            $nextAction = null;
        }
        if ($nextQuery) {
            $nextQuery = '?next=' . rawurlencode($next);
        }
        return $this->redirect()->toUrl($contribution->url($nextAction) . $nextQuery);
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

    protected function prepareContributionEmail(ContributionRepresentation $contribution, string $action = 'updated'): self
    {
        $emails = $this->settings()->get('contribute_notify', []);
        if (empty($emails)) {
            return $this;
        }

        $translate = $this->getPluginManager()->get('translate');
        $actions = [
            'prepared' => $translate('prepared'), // @translate
            'updated' => $translate('updated'), // @translate
            'submitted' => $translate('submitted'), // @translate
        ];

        $action = $actions[$action] ?? $translate('updated');
        $contributionResource = $contribution->resource();
        $user = $this->identity();

        switch (true) {
            case $contributionResource && $user:
                $message = '<p>' . new Message(
                    'User %1$s has made a contribution for resource #%2$s (%3$s) (action: %4$s).', // @translate
                    '<a href="' . $this->url()->fromRoute('admin/id', ['controller' => 'user', 'id' => $user->getId()], ['force_canonical' => true]) . '">' . $user->getName() . '</a>',
                    '<a href="' . $contributionResource->adminUrl('show', true) . '#contribution">' . $contributionResource->id() . '</a>',
                    $contributionResource->displayTitle(),
                    $action
                ) . '</p>';
                break;
            case $contributionResource:
                $message = '<p>' . new Message(
                    'An anonymous user has made a contribution for resource #%1$s (%2$s) (action: %3$s).', // @translate
                    '<a href="' . $contributionResource->adminUrl('show', true) . '#contribution">' . $contributionResource->id() . '</a>',
                    $contributionResource->displayTitle(),
                    $action
                ) . '</p>';
                break;
            case $user:
                $message = '<p>' . new Message(
                    'User %1$s has made a contribution (action: %2$s).', // @translate
                    '<a href="' . $this->url()->fromRoute('admin/id', ['controller' => 'user', 'id' => $user->getId()], ['force_canonical' => true]) . '">' . $user->getName() . '</a>',
                    $action
                ) . '</p>';
                break;
            default:
                $message = '<p>' . new Message(
                    'An anonymous user has made a contribution (action: %1$s).', // @translate
                    $action
                ) . '</p>';
                break;
        }

        $this->sendContributionEmail($emails, sprintf($translate('[Omeka] Contribution %s'), $action), $message); // @translate
        return $this;
    }

    /**
     * Prepare the proposal for saving.
     *
     * The check is done comparing the keys of original values and the new ones.
     *
     * @todo Factorize with \Contribute\Admin\ContributionController::validateContribution()
     * @todo Factorize with \Contribute\View\Helper\ContributionFields
     * @todo Factorize with \Contribute\Api\Representation\ContributionRepresentation::proposalNormalizeForValidation()
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
                        // No break.
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
                        // No break.
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
