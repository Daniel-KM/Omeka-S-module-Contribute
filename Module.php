<?php declare(strict_types=1);

namespace Contribute;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;

/**
 * Contribute
 *
 * @copyright Daniel Berthereau, 2019-2024
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected $dependencies = [
        'Common',
        'AdvancedResourceTemplate',
    ];

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        $this->addAclRules();
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $translate = $plugins->get('translate');
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.55')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.55'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        if (!$this->checkDestinationDir($basePath . '/contribution')) {
            $message = new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $basePath . '/contribution']
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
        }
    }

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();

        $api = $services->get('ControllerPluginManager')->get('api');
        $settings = $services->get('Omeka\Settings');

        // Store the ids of the resource templates for medias.
        $templateNames = $settings->get('contribute_templates_media', []);
        $templateIds = [];
        foreach ($templateNames as $templateName) {
            $templateIds[$templateName] = $api
                ->searchOne('resource_templates', is_numeric($templateName) ? ['id' => $templateName] : ['label' => $templateName], ['returnScalar' => 'id'])->getContent();
        }
        $templateFileIds = array_filter($templateIds);
        $settings->set('contribute_templates_media', array_values($templateFileIds));

        // Store the ids of the resource templates for items.
        $templateNames = $settings->get('contribute_templates', []);
        $templateIds = [];
        foreach ($templateNames as $templateName) {
            $templateIds[$templateName] = $api
                ->searchOne('resource_templates', is_numeric($templateName) ? ['id' => $templateName] : ['label' => $templateName], ['returnScalar' => 'id'])->getContent();
        }
        $templateItemIds = array_filter($templateIds);
        $settings->set('contribute_templates', array_values($templateItemIds));

        // Set the tempalte Contribution File the template for media in main
        // template Contribution.
        $templateFile = $templateFileIds['Contribution File'] ?? null;
        $templateItem = $templateItemIds['Contribution'] ?? null;
        if ($templateItem && $templateFile) {
            /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
            $template = $api->read('resource_templates', ['id' => $templateItem])->getContent();
            $templateData = $template->data();
            $templateData['contribute_template_media'] = $templateFile;
            $api->update('resource_templates', $templateItem, ['o:data' => $templateData], [], ['isPartial' => true]);
        }
    }

    protected function postUninstall(): void
    {
        // Don't remove templates.

        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $this->rmDir($basePath . '/contribution');
    }

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $contributeMode = $settings->get('contribute_mode', 'user');
        $isOpenContribution = $contributeMode === 'open' || $contributeMode === 'token';

        $contributeRoles = $contributeMode === 'role'
            ? $settings->get('contribute_roles', [])
            : null;

        $allowUpdateMode = $settings->get('contribute_allow_update', 'submission');

        /**
         * For default rights:
         * @see \Omeka\Service\AclFactory
         *
         * @var \Omeka\Permissions\Acl $acl
         */
        $acl = $services->get('Omeka\Acl');

        // Since Omeka 1.4, modules are ordered so Guest comes after Contribute.
        // See \Guest\Module::onBootstrap().
        if (!$acl->hasRole('guest')) {
            $acl->addRole('guest');
        }
        if (!$acl->hasRole('guest_private')) {
            $acl->addRole('guest_private');
        }

        $roles = $acl->getRoles();

        $contributors = $isOpenContribution
            ? null
            : ($contributeRoles ?? $roles);

        // Users who can edit resources can update contributions.
        // A check is done on the specific resource for some roles.
        $validators = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
        ];

        // Only admins can delete a contribution.
        $simpleValidators = [
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
        ];
        $adminValidators = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
        ];

        // Nobody can view contributions except owner and admins.
        // So anonymous contributor cannot view or edit a contribution.
        // Once submitted, the contribution cannot be updated by the owner,
        // except with option "contribute_allow_update".
        // Once reviewed, the contribution can be viewed like the resource.

        // Contribution.
        $acl
            ->allow(
                $contributors,
                ['Contribute\Controller\Site\Contribution'],
                // TODO "view" is forwarded to "show" internally (will be removed).
                ['show', 'view', 'add', 'edit', 'delete', 'delete-confirm', 'submit']
            )
            ->allow(
                $contributors,
                [\Contribute\Api\Adapter\ContributionAdapter::class],
                ['search', 'read', 'create', 'update', 'delete']
            )
            ->allow(
                $contributors,
                [\Contribute\Entity\Contribution::class],
                [
                    'create',
                    // TODO Remove right to change owner of the contribution (only set it first time).
                    'change-owner',
                ]
            )
            ->allow(
                $contributors,
                [\Contribute\Entity\Contribution::class],
                ['read'],
                (new \Laminas\Permissions\Acl\Assertion\AssertionAggregate)
                    ->setMode(\Laminas\Permissions\Acl\Assertion\AssertionAggregate::MODE_AT_LEAST_ONE)
                    ->addAssertion(new \Omeka\Permissions\Assertion\OwnsEntityAssertion)
                    ->addAssertion(new \Contribute\Permissions\Assertion\IsSubmittedAndReviewedAndHasPublicResource)
            )
        ;
        if ($allowUpdateMode === 'submission' || $allowUpdateMode === 'validation') {
            $acl
                ->allow(
                    $contributors,
                    [\Contribute\Entity\Contribution::class],
                    ['update', 'delete'],
                    (new \Laminas\Permissions\Acl\Assertion\AssertionAggregate)
                        ->addAssertion(new \Omeka\Permissions\Assertion\OwnsEntityAssertion)
                        ->addAssertion($allowUpdateMode === 'submission'
                            ? new \Contribute\Permissions\Assertion\IsNotSubmitted()
                            : new \Contribute\Permissions\Assertion\IsNotReviewed()
                        )
                );
        }

        // Token.
        $acl
            ->allow(
                $contributors,
                [\Contribute\Api\Adapter\TokenAdapter::class],
                ['search', 'read', 'update']
            )
            ->allow(
                $contributors,
                [\Contribute\Entity\Token::class],
                ['update']
            )

            // Administration in public side (module Guest).
            ->allow(
                $roles,
                ['Contribute\Controller\Site\GuestBoard'],
                ['browse', 'show', 'view', 'add', 'edit', 'delete', 'delete-confirm', 'submit']
            )

            // Administration.
            ->allow(
                $validators,
                ['Contribute\Controller\Admin\Contribution']
            )
            ->allow(
                $validators,
                [\Contribute\Api\Adapter\ContributionAdapter::class]
            )
            // TODO Give right to deletion to reviewer?
            ->allow(
                $simpleValidators,
                [\Contribute\Entity\Contribution::class],
                ['read', 'update']
            )
            ->allow(
                $adminValidators,
                [\Contribute\Entity\Contribution::class],
                ['read', 'update', 'delete']
            )

            //  TODO Remove this hack to allow validators to change owner.
            ->allow(
                $validators,
                [\Omeka\Entity\Item::class],
                ['create', 'read', 'update', 'change-owner']
            )
        ;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Media\Ingester\Manager::class,
            'service.registered_names',
            [$this, 'handleMediaIngesterRegisteredNames']
        );

        // Process validation only with api create/update, after all processes.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.hydrate.post',
            [$this, 'handleValidateContribution'],
            -1000
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.hydrate.post',
            [$this, 'handleValidateContribution'],
            -1000
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.hydrate.post',
            [$this, 'handleValidateContribution'],
            -1000
        );

        // Link to edit form on item/show page.
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.after',
            [$this, 'handleViewShowAfter']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.browse.after',
            [$this, 'handleViewShowAfter']
        );

        // Guest integration.
        $sharedEventManager->attach(
            \Guest\Controller\Site\GuestController::class,
            'guest.widgets',
            [$this, 'handleGuestWidgets']
        );

        // Admin management.
        $controllers = [
            'Omeka\Controller\Admin\Item',
            'Omeka\Controller\Admin\ItemSet',
            'Omeka\Controller\Admin\Media',
        ];
        foreach ($controllers as $controller) {
            // Append a bulk process to batch create tokens when enabled.
            $sharedEventManager->attach(
                $controller,
                'view.browse.before',
                [$this, 'addHeadersAdminBrowse']
            );
            // Display a link to create a token in the sidebar when enabled.
            $sharedEventManager->attach(
                $controller,
                'view.show.sidebar',
                [$this, 'adminViewShowSidebar']
            );
            // Add a tab to the resource show admin pages.
            $sharedEventManager->attach(
                $controller,
                // There is no "view.show.before".
                'view.show.after',
                [$this, 'addHeadersAdmin']
            );
            $sharedEventManager->attach(
                $controller,
                'view.show.section_nav',
                [$this, 'appendTab']
            );
            $sharedEventManager->attach(
                $controller,
                'view.show.after',
                [$this, 'displayTab']
            );

            // Add the details to the resource browse admin pages.
            $sharedEventManager->attach(
                $controller,
                'view.details',
                [$this, 'viewDetails']
            );
        }

        $sharedEventManager->attach(
            'Contribute\Controller\Admin\Contribution',
            'view.browse.before',
            [$this, 'addHeadersAdmin']
        );

        $sharedEventManager->attach(
            \Contribute\Entity\Contribution::class,
            'entity.remove.post',
            [$this, 'deleteContributionFiles']
        );

        // Handle main settings.
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );

        $sharedEventManager->attach(
            // \Omeka\Form\ResourceTemplateForm::class,
            \AdvancedResourceTemplate\Form\ResourceTemplateForm::class,
            'form.add_elements',
            [$this, 'addResourceTemplateFormElements']
        );
        $sharedEventManager->attach(
            // \Omeka\Form\ResourceTemplatePropertyFieldset::class,
            \AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset::class,
            'form.add_elements',
            [$this, 'addResourceTemplatePropertyFieldsetElements']
        );
    }

    /**
     * Avoid to display ingester in item edit, because it's an internal one.
     */
    public function handleMediaIngesterRegisteredNames(Event $event): void
    {
        $names = $event->getParam('registered_names');
        $key = array_search('contribution', $names);
        unset($names[$key]);
        $event->setParam('registered_names', $names);
    }

    /**
     * Add an error during hydration to avoid to save a resource to validate.
     */
    public function handleValidateContribution(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        if (!$request->getOption('isContribution')
            || !$request->getOption('validateOnly')
            || $request->getOption('flushEntityManager')
        ) {
            return;
        }
        $entity = $event->getParam('entity');
        if (!$entity instanceof \Omeka\Entity\Resource) {
            return;
        }
        // Don't add an error if there is already one.
        /** @var \Omeka\Stdlib\ErrorStore $errorStore */
        $errorStore = $event->getParam('errorStore');
        if ($errorStore->hasErrors()) {
            return;
        }
        // The validation of the entity in the adapter is processed after event,
        // so trigger it here with a new error store.
        $validateErrorStore = new \Omeka\Stdlib\ErrorStore;
        $adapter = $event->getTarget();
        $adapter->validateEntity($entity, $validateErrorStore);
        if ($validateErrorStore->hasErrors()) {
            return;
        }
        $errorStore->addError('validateOnly', 'No error');
    }

    public function handleViewShowAfter(Event $event): void
    {
        echo $event->getTarget()->contributionLink();
    }

    public function handleGuestWidgets(Event $event): void
    {
        $widgets = $event->getParam('widgets');
        $helpers = $this->getServiceLocator()->get('ViewHelperManager');
        $translate = $helpers->get('translate');
        $partial = $helpers->get('partial');

        $widget = [];
        $widget['label'] = $translate('Contributions'); // @translate
        $widget['content'] = $partial('guest/site/guest/widget/contribution');
        $widgets['selection'] = $widget;

        $event->setParam('widgets', $widgets);
    }

    public function addHeadersAdmin(Event $event): void
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/contribute-admin.css', 'Contribute'));
        $view->headScript()
            ->appendFile($assetUrl('js/contribute-admin.js', 'Contribute'), 'text/javascript', ['defer' => 'defer']);
    }

    public function addHeadersAdminBrowse(Event $event): void
    {
        // Don't display the token form if it is not used.
        $contributeMode = $this->getServiceLocator()->get('Omeka\Settings')->get('contribute_mode');
        if ($contributeMode !== 'user_token' && $contributeMode !== 'token') {
            return;
        }
        $this->addHeadersAdmin($event);
    }

    public function adminViewShowSidebar(Event $event): void
    {
        $view = $event->getTarget();
        $plugins = $view->getHelperPluginManager();
        $setting = $plugins->get('setting');
        if (!in_array($setting('contribute_mode'), ['user_token', 'token'])) {
            return;
        }

        $url = $plugins->get('url');
        $translate = $plugins->get('translate');
        $escapeAttr = $plugins->get('escapeHtmlAttr');

        $resource = $view->resource;
        $query = [
            'resource_type' => $resource->resourceName(),
            'resource_ids' => [$resource->id()],
            'redirect' => $this->getCurrentUrl($view),
        ];
        $link = $view->hyperlink(
            $translate('Create contribution token'), // @translate
            $url('admin/contribution/default', ['action' => 'create-token'], ['query' => $query])
        );
        $htmlText = [
            'contritube' => $translate('Contribute'), // @translate
            'email' => $escapeAttr($translate('Please input optional email…')), // @translate
            'token' => $escapeAttr($translate('Create token')), // @translate
        ];
        echo <<<HTML
<div class="meta-group create_contribution_token">
    <h4>{$htmlText['contritube']}</h4>
    <div class="value" id="create_contribution_token">$link</div>
    <div id="create_contribution_token_dialog" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close" id="create_contribution_token_dialog_close">&times;</span>
            <input type="text" value="" placeholder="{$htmlText['email']}" id="create_contribution_token_dialog_email"/>
            <input type="button" value="{$htmlText['token']}" id="create_contribution_token_dialog_go"/>
        </div>
    </div>
</div>

HTML;
    }

    /**
     * Add a tab to section navigation.
     *
     * @param Event $event
     */
    public function appendTab(Event $event): void
    {
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['contribution'] = 'Contributions'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    /**
     * Display a partial for a resource.
     *
     * @param Event $event
     */
    public function displayTab(Event $event): void
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $view = $event->getTarget();

        $resource = $view->resource;

        $contributions = $api
            ->search('contributions', [
                'resource_id' => $resource->id(),
                'sort_by' => 'modified',
                'sort_order' => 'DESC',
            ])
            ->getContent();

        $unusedTokens = $api
            ->search('contribution_tokens', [
                'resource_id' => $resource->id(),
                'used' => false,
            ])
            ->getContent();

        $plugins = $services->get('ViewHelperManager');
        $defaultSite = $plugins->get('defaultSite');
        $siteSlug = $defaultSite('slug');

        echo '<div id="contribution" class="section">';
        echo $view->partial('common/admin/contribute-list', [
            'resource' => $resource,
            'contributions' => $contributions,
            'unusedTokens' => $unusedTokens,
            'siteSlug' => $siteSlug,
        ]);
        echo '</div>';
    }

    /**
     * Display the details for a resource.
     *
     * @param Event $event
     */
    public function viewDetails(Event $event): void
    {
        $view = $event->getTarget();
        $services = $this->getServiceLocator();
        $translate = $view->plugin('translate');
        $translator = $services->get('MvcTranslator');

        $resource = $event->getParam('entity');
        $total = $view->api()
            ->search('contributions', [
                'resource_id' => $resource->id(),
            ])
            ->getTotalResults();
        $totalNotReviewed = $view->api()
            ->search('contributions', [
                'resource_id' => $resource->id(),
                'reviewed' => '0',
            ])
            ->getTotalResults();
        $contributions = $translate('Contributions'); // @translat
        $message = $total
            ? new PsrMessage(
                '{total} contributions ({count} not reviewed)', // @translate
                ['total' => $total, 'count' => $totalNotReviewed]
            )
            : new PsrMessage('No contribution'); // @translate
        $message->setTranslator($translator);
        echo <<<HTML
<div class="meta-group">
    <h4>$contributions</h4>
    <div class="value">
        $message
    </div>
</div>

HTML;
    }

    public function addResourceTemplateFormElements(Event $event): void
    {
        /** @var \Omeka\Form\ResourceTemplateForm $form */
        /** @var \AdvancedResourceTemplate\Form\ResourceTemplateDataFieldset $form */
        $form = $event->getTarget();
        $fieldset = $form->get('o:data');
        $fieldset
            ->add([
                'name' => 'contribute_template_media',
                // Advanced Resource Template is a required dependency.
                'type' => \Common\Form\Element\OptionalResourceTemplateSelect::class,
                'options' => [
                    'label' => 'Media template for contribution', // @translate
                    'info' => 'If any, the template should be in the list of allowed templates for contribution of a media', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    // 'id' => 'contribute_template_media',
                    'class' => 'setting chosen-select',
                    'multiple' => false,
                    'data-setting-key' => 'contribute_template_media',
                    'data-placeholder' => 'Select resource template for media…', // @translate
                ],
            ])
            // Specific messages for the contributor.
            ->add([
                'name' => 'contribute_author_confirmation_subject',
                'type' => \Laminas\Form\Element\Text::class,
                'options' => [
                    'label' => 'Specific confirmation subject to the contributor', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_author_confirmation_subject',
                    'data-setting-key' => 'contribute_author_confirmation_subject',
                ],
            ])
            ->add([
                'name' => 'contribute_author_confirmation_body',
                'type' => \Laminas\Form\Element\Textarea::class,
                'options' => [
                    'label' => 'Specific confirmation message to the contributor', // @translate
                    'info' => 'Placeholders: wrap properties with "{}", for example "{dcterms:title}".', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_author_confirmation_body',
                    'rows' => 5,
                    'data-setting-key' => 'contribute_author_confirmation_body',
                ],
            ])
            // Specific messages for the reviewer.
            ->add([
                'name' => 'contribute_reviewer_confirmation_subject',
                'type' => \Laminas\Form\Element\Text::class,
                'options' => [
                    'label' => 'Specific confirmation subject to the reviewer', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_reviewer_confirmation_subject',
                    'data-setting-key' => 'contribute_reviewer_confirmation_subject',
                ],
            ])
            ->add([
                'name' => 'contribute_reviewer_confirmation_body',
                'type' => \Laminas\Form\Element\Textarea::class,
                'options' => [
                    'label' => 'Specific confirmation message to the reviewer', // @translate
                    'info' => 'Placeholders: wrap properties with "{}", for example "{dcterms:title}".', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_reviewer_confirmation_body',
                    'rows' => 5,
                    'data-setting-key' => 'contribute_reviewer_confirmation_body',
                ],
            ]);
    }

    public function addResourceTemplatePropertyFieldsetElements(Event $event): void
    {
        /** @var \AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset $fieldset */
        $fieldset = $event->getTarget();
        $fieldset
            ->add([
                'name' => 'editable',
                'type' => \Laminas\Form\Element\Checkbox::class,
                'options' => [
                    'label' => 'Editable by contributor', // @translate
                ],
                'attributes' => [
                    // 'id' => 'editable',
                    'class' => 'setting',
                    'data-setting-key' => 'editable',
                ],
            ])
            ->add([
                'name' => 'fillable',
                'type' => \Laminas\Form\Element\Checkbox::class,
                'options' => [
                    'label' => 'Fillable by contributor', // @translate
                ],
                'attributes' => [
                    // 'id' => 'fillable',
                    'class' => 'setting',
                    'data-setting-key' => 'fillable',
                ],
            ]);
    }

    /**
     * Delete all files associated with a removed Contribution entity.
     *
     * Processed via an event to be sure that the contribution is removed.
     */
    public function deleteContributionFiles(Event $event): void
    {
        $services = $this->getServiceLocator();

        // Fix issue when there is no path.
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $dirPath = rtrim($basePath, '/') . '/contribution';
        if (!$this->checkDestinationDir($dirPath)) {
            $translator = $services->get('MvcTranslator');
            $message = new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $basePath . '/contribution']
            );
            throw new \Omeka\File\Exception\RuntimeException((string) $message->setTranslator($translator));
        }

        $store = $services->get('Omeka\File\Store');
        $entity = $event->getTarget();
        $proposal = $entity->getProposal();
        foreach ($proposal['media'] ?? [] as $mediaFiles) {
            foreach ($mediaFiles['file'] ?? [] as $mediaFile) {
                if (isset($mediaFile['proposed']['store'])) {
                    $storagePath = 'contribution/' . $mediaFile['proposed']['store'];
                    $store->delete($storagePath);
                }
            }
        }

        // The entity is flushed, so it is possible to remove all remaining
        // files (after update or deletion of a proposal).
        // It is simpler to manage globally than individually because the
        // storage reference is removed currently.
        // TODO Add a column for files.
        $sql = <<<SQL
SELECT
    JSON_EXTRACT( proposal, "$.media[*].file[*].proposed.store" ) AS proposal_json
FROM contribution
HAVING proposal_json IS NOT NULL;
SQL;
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        $storeds = $connection->executeQuery($sql)->fetchFirstColumn();
        $storeds = array_map('json_decode', $storeds);
        $storeds = $storeds ? array_unique(array_merge(...array_values($storeds))) : [];

        // TODO Scan dir is local store only for now.
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            $path = $dirPath . '/' . $file;
            if (!is_dir($path)
                && is_file($path)
                && is_writeable($path)
                && !in_array($file, $storeds)
            ) {
                @unlink($path);
            }
        }
    }

    /**
     * Get the current url with query string if any.
     *
     * @param PhpRenderer $view
     * @return string
     */
    protected function getCurrentUrl(PhpRenderer $view)
    {
        $url = $view->url(null, [], true);
        $query = http_build_query($view->params()->fromQuery(), '', '&', PHP_QUERY_RFC3986);
        return $query
            ? $url . '?' . $query
            : $url;
    }
}
