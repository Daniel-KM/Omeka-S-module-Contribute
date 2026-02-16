<?php declare(strict_types=1);

namespace Contribute;

if (!class_exists('Common\TraitModule', false)) {
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
 * Contribute.
 *
 * @copyright Daniel Berthereau, 2019-2026
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

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.79')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.79'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        if (!$this->checkModuleActiveVersion('AdvancedResourceTemplate', '3.4.47')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Advanced Resource Template', '3.4.47'
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
        /**
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Settings\Settings $settings
         * @var \Doctrine\DBAL\Connection $connection
         */
        $services = $this->getServiceLocator();

        $api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');
        $connection = $services->get('Omeka\Connection');

        // Get all the templates ids and labels.
        // Don't use EasyMeta here.
        $qb = $connection->createQueryBuilder();
        $qb
            ->select(
                '`resource_template`.`label` AS label',
                '`resource_template`.`id` AS id'
            )
            ->from('resource_template', 'resource_template')
            ->groupBy('`resource_template`.`id`')
            ->orderBy('`resource_template`.`label`', 'asc')
        ;
        $templateIdsByLabels = $connection->executeQuery($qb)->fetchAllKeyValue();

        $templateFileIds = array_intersect_key($templateIdsByLabels, ['Contribution File']);
        $templateItemIds = array_intersect_key($templateIdsByLabels, ['Contribution']);

        // Set the template Contribution File (template for media) in main
        // template Contribution.
        $templateFile = $templateFileIds['Contribution File'] ?? null;
        $templateItem = $templateItemIds['Contribution'] ?? null;
        if ($templateItem && $templateFile) {
            /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
            $template = $api->read('resource_templates', ['id' => $templateItem])->getContent();
            $templateData = $template->data();
            $templateData['contribute_templates_media'] = [$templateFile];
            $api->update('resource_templates', $templateItem, ['o:data' => $templateData], [], ['isPartial' => true]);
        }

        $this->mergeMainAndTemplateSettings();
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

        $contributeConfig = $settings->get('contribute_config') ?: [];
        $contributeModes = $contributeConfig['modes'] ?? [];

        $isOpenContribution = in_array('open', $contributeModes)
            || in_array('token', $contributeModes);

        $contributeRoles = in_array('user_role', $contributeModes)
            ? $contributeConfig['filter_user_roles'] ?? []
            : null;

        $allowEditUntil = $contributeConfig['allow_edit_until'] ?? 'undertaking';

        /**
         * For default rights:
         * @see \Omeka\Service\AclFactory
         *
         * @var \Omeka\Permissions\Acl $acl
         */
        $acl = $services->get('Omeka\Acl');

        // Since Omeka 1.4, modules are ordered, so Guest comes after Access.
        // See \Guest\Module::onBootstrap(). Manage other roles too: contributor, etc.
        /** @see https://github.com/omeka/omeka-s/pull/2241 */
        $hasGuest = class_exists('Guest\Module', false) || class_exists('GuestRole\Module', false);
        if ($hasGuest) {
            if (!$acl->hasRole('guest')) {
                $acl->addRole('guest');
            }
        }
        $hasGuestPrivate = class_exists('GuestPrivate\Module', false);
        if (class_exists('GuestPrivate\Module', false)) {
            if (!$acl->hasRole('guest_private')) {
                $acl->addRole('guest_private');
            }
            if (!$acl->hasRole('guest_private_site')) {
                $acl->addRole('guest_private_site');
            }
        }

        $roles = $acl->getRoles();

        $contributors = $isOpenContribution
            ? []
            : ($contributeRoles ?? $roles);

        // Open rights for guests for other modes.
        // The real check is done in controller anyway via CanContribute().
        if (!$isOpenContribution) {
            if ($hasGuest) {
                $contributors[] = 'guest';
            }
            if ($hasGuestPrivate) {
                $contributors[] = 'guest_private';
                $contributors[] = 'guest_private_site';
            }
        }

        $contributors = array_intersect($contributors, $acl->getRoles());

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
        // except with option "contribute_allow_edit_until".
        // Once validated, the contribution can be viewed like the resource.

        // Contribution.
        // Of course, if there is no contributors, the module is useless.
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
                    ->addAssertion(new \Contribute\Permissions\Assertion\IsFullContributed())
            )
        ;
        if (in_array($allowEditUntil, ['submission', 'undertaking', 'validation'])) {
            $acl
                ->allow(
                    $contributors,
                    [\Contribute\Entity\Contribution::class],
                    ['update', 'delete'],
                    (new \Laminas\Permissions\Acl\Assertion\AssertionAggregate)
                        ->addAssertion(new \Omeka\Permissions\Assertion\OwnsEntityAssertion)
                        ->addAssertion($allowEditUntil === 'submission'
                            ? new \Contribute\Permissions\Assertion\IsNotSubmitted()
                            : ($allowEditUntil === 'undertaking'
                                ? new \Contribute\Permissions\Assertion\IsNotUndertaken()
                                : new \Contribute\Permissions\Assertion\IsNotValidated())
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
                ['Contribute\Controller\Site\Guest'],
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
        // The validation must not hydrate the resource.
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
            // Add a tab to the resource show admin pages to manage
            // contributions.
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
        $sharedEventManager->attach(
            \AdvancedResourceTemplate\Api\Adapter\ResourceTemplateAdapter::class,
            'api.create.post',
            [$this, 'handleApiUpdatePostResourceTemplate']
        );
        $sharedEventManager->attach(
            \AdvancedResourceTemplate\Api\Adapter\ResourceTemplateAdapter::class,
            'api.update.post',
            [$this, 'handleApiUpdatePostResourceTemplate']
        );

        // Add JS for contribution options visibility in resource template forms.
        // Use both identifiers to support with and without AdvancedResourceTemplate delegator.
        $resourceTemplateControllers = [
            'Omeka\Controller\Admin\ResourceTemplate',
            \AdvancedResourceTemplate\Controller\Admin\ResourceTemplateControllerDelegator::class,
        ];
        foreach ($resourceTemplateControllers as $controller) {
            $sharedEventManager->attach(
                $controller,
                'view.edit.form.after',
                [$this, 'addResourceTemplateFormJs']
            );
            $sharedEventManager->attach(
                $controller,
                'view.add.form.after',
                [$this, 'addResourceTemplateFormJs']
            );
        }
    }

    public function handleMainSettings(Event $event): void
    {
        $this->handleAnySettings($event, 'settings');

        $this->mergeMainAndTemplateSettings();
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
     *
     * Context: When a contribution is converted into an item, it should be
     * checked first. Some checks are done via events in api and hydration.
     * So the process requires options "isContribution" and"validateOnly"
     * At the end, an error is added to the error store to avoid to save the
     * resource.
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
        $widgets['contribute'] = $widget;

        $event->setParam('widgets', $widgets);
    }

    public function addHeadersAdmin(Event $event): void
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/common-dialog.css', 'Common'))
            ->appendStylesheet($assetUrl('css/contribute-admin.css', 'Contribute'));
        $view->headScript()
            ->appendFile($assetUrl('js/common-dialog.js', 'Common'), 'text/javascript', ['defer' => 'defer'])
            ->appendFile($assetUrl('js/contribute-admin.js', 'Contribute'), 'text/javascript', ['defer' => 'defer']);
    }

    public function addHeadersAdminBrowse(Event $event): void
    {
        // Don't display the token form if it is not used.
        $contributeConfig = $this->getServiceLocator()->get('Omeka\Settings')->get('contribute_config') ?: [];
        if (empty($contributeConfig['use_token'])) {
            return;
        }

        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/common-dialog.css', 'Common'))
            ->appendStylesheet($assetUrl('css/contribute-admin.css', 'Contribute'));
        $view->headScript()
            ->appendFile($assetUrl('js/common-dialog.js', 'Common'), 'text/javascript', ['defer' => 'defer'])
            ->appendFile($assetUrl('js/contribute-admin.js', 'Contribute'), 'text/javascript', ['defer' => 'defer']);
    }

    public function adminViewShowSidebar(Event $event): void
    {
        /**
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         * @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template
         */
        $view = $event->getTarget();
        $resource = $view->resource;

        $template = $resource->resourceTemplate();
        if (!$template) {
            return;
        }

        // Check the mode for this template (none, global or specific).
        $contributable = $template->dataValue('contribute_template_contributable');
        if ($contributable === 'global') {
            $contributeModes = $this->getServiceLocator()->get('Omeka\Settings')->get('contribute_modes') ?: [];
        } elseif ($contributable === 'specific') {
            $contributeModes = $template->dataValue('contribute_modes') ?: [];
        } else {
            return;
        }

        $useToken = in_array('user_token', $contributeModes) || in_array('token', $contributeModes);
        if (!$useToken) {
            return;
        }

        $plugins = $view->getHelperPluginManager();
        $url = $plugins->get('url');
        $translate = $plugins->get('translate');
        $hyperlink = $plugins->get('hyperlink');
        $escapeAttr = $plugins->get('escapeHtmlAttr');

        $query = [
            'resource_type' => $resource->resourceName(),
            'resource_ids' => [$resource->id()],
            'redirect' => $this->getCurrentUrl($view),
        ];
        $link = $hyperlink(
            $translate('Create contribution token'), // @translate
            $url('admin/contribution/default', ['action' => 'create-token'], ['query' => $query])
        );
        $htmlText = [
            'contribute' => $translate('Contribute'), // @translate
            'email' => $escapeAttr($translate('Please input optional email…')), // @translate
            'token' => $escapeAttr($translate('Create token')), // @translate
        ];
        echo <<<HTML
            <div class="meta-group create_contribution_token">
                <h4>{$htmlText['contribute']}</h4>
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
                'limit' => 0,
            ])
            ->getTotalResults();
        $totalNotValidated = $view->api()
            ->search('contributions', [
                'resource_id' => $resource->id(),
                'validated' => '0',
                'limit' => 0,
            ])
            ->getTotalResults();
        $heading = $translate('Contributions'); // @translate
        $message = $total
            ? new PsrMessage(
                '{total} contributions ({count} not validated)', // @translate
                ['total' => $total, 'count' => $totalNotValidated]
            )
            : new PsrMessage('No contribution'); // @translate
        $message->setTranslator($translator);
        echo <<<HTML
            <div class="meta-group">
                <h4>$heading</h4>
                <div class="value">
                    $message
                </div>
            </div>
            HTML;
    }

    public function addResourceTemplateFormElements(Event $event): void
    {
        /**
         * @var \Omeka\Form\ResourceTemplateForm $form
         * @var \AdvancedResourceTemplate\Form\ResourceTemplateDataFieldset $fieldset
         * @var \Contribute\Form\TemplateContributeFieldset $fieldsetContribute
         * @var \Laminas\Form\Element $element
         */
        $services = $this->getServiceLocator();
        $formManager = $services->get('FormElementManager');

        $form = $event->getTarget();

        $elementGroups = $form->getOption('element_groups') ?: [];
        $elementGroups['contribution'] = 'Contribution'; // @translate
        $form->setOption('element_groups', $elementGroups);

        $fieldset = $form->get('o:data');

        $specificLabels = [
            'contribute_modes' => 'Contribution modes', // @translate
        ];

        $fieldsetContribute = $formManager
            ->get(\Contribute\Form\TemplateContributeFieldset::class);
        foreach ($fieldsetContribute->getElements() as $element) {
            $fieldset->add($element);
            // Specific labels.
            $name = $element->getName();
            if (isset($specificLabels[$name])) {
                $element->setOption('label', $specificLabels[$name]);
            }
        }
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
                    'label' => 'Contribute: Editable by contributor', // @translate
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
                    'label' => 'Contribute: Fillable by contributor', // @translate
                ],
                'attributes' => [
                    // 'id' => 'fillable',
                    'class' => 'setting',
                    'data-setting-key' => 'fillable',
                ],
            ]);
    }

    /**
     * Add JS for contribution options visibility toggle in resource template form.
     */
    public function addResourceTemplateFormJs(Event $event): void
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headScript()
            ->appendFile($assetUrl('js/contribute-resource-template.js', 'Contribute'), 'text/javascript', ['defer' => 'defer']);
    }

    public function handleApiUpdatePostResourceTemplate(Event $event): void
    {
        // This is an api-post event, so id is ready and checks are done.
        $this->mergeMainAndTemplateSettings();
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
        // Files can be stored at two locations in the proposal:
        // - $.media[*].file[*].proposed.store (nested in media for item contributions)
        // - $.file[*].proposed.store (root level for media contributions)
        $sqlNested = <<<SQL
            SELECT
                JSON_EXTRACT( proposal, "$.media[*].file[*].proposed.store" ) AS proposal_json
            FROM contribution
            HAVING proposal_json IS NOT NULL;
            SQL;
        $sqlRoot = <<<SQL
            SELECT
                JSON_EXTRACT( proposal, "$.file[*].proposed.store" ) AS proposal_json
            FROM contribution
            HAVING proposal_json IS NOT NULL;
            SQL;
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        $storedsNested = $connection->executeQuery($sqlNested)->fetchFirstColumn();
        $storedsNested = array_map('json_decode', $storedsNested);
        $storedsNested = $storedsNested ? array_merge(...array_values($storedsNested)) : [];
        $storedsRoot = $connection->executeQuery($sqlRoot)->fetchFirstColumn();
        $storedsRoot = array_map('json_decode', $storedsRoot);
        $storedsRoot = $storedsRoot ? array_merge(...array_values($storedsRoot)) : [];
        $storeds = array_unique(array_merge($storedsNested, $storedsRoot));

        // TODO Scan dir is local store only for now.
        $files = array_diff(scandir($dirPath) ?: [], ['.', '..']);
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

    /**
     * @todo The shortcut "contribute_config" does not seem to be used a lot. Anyway, it is generally preferable to use direct data from the resource template, generally nearly as simple and quick.
     */
    protected function mergeMainAndTemplateSettings(): void
    {
        /**
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Settings\Settings $settings
         * @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation[] $templates
         */
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');

        $config = include __DIR__ . '/config/module.config.php';
        $configKeys = array_keys($config['contribute']['settings']);
        $configKeys = array_diff($configKeys, [
            'contribute_config',
            'contribute_redirect_submit',
            'contribute_message_author_mail_subject',
            'contribute_message_author_mail_body',
            'contribute_send_message_recipient_myself',
            'contribute_send_message_recipients_cc',
            'contribute_send_message_recipients_bcc',
            'contribute_send_message_recipients_reply',
        ]);

        $result = [
            'modes' => [],
            'filter_user_roles' => [],
            'use_token' => false,
            'allow_edit_until' => null,
            'contribute_template_contributable' => [],
        ];

        $templates = $api->search('resource_templates')->getContent();
        foreach ($templates as $template) {
            $templateId = $template->id();
            $contributable = $template->dataValue('contribute_template_contributable');
            $isGlobal = $contributable === 'global';
            $isSpecific = $contributable === 'specific';
            if (!$isGlobal && !$isSpecific) {
                continue;
            }
            $result['contribute_template_contributable'][$templateId] = $contributable;
            if ($isSpecific) {
                foreach ($configKeys as $key) {
                    $result[$key][$templateId] = $template->dataValue($key);
                }
            }
        }

        // Add merged settings for quick bootstrap for some keys.

        // Each specific should be an array, but may be null.
        // Even if empty arrays are ignored, normalize result for consistency
        $global = $settings->get('contribute_modes') ?: [];
        $result['contribute_modes'] = array_map(fn ($v) => $v ?? [], $result['contribute_modes'] ?? []);
        $specifics = $result['contribute_modes'];
        $result['modes'] = count($specifics)
            ? array_values(array_unique(array_merge($global, ...$specifics)))
            : $global;

        $global = $settings->get('contribute_filter_user_roles') ?: [];
        $result['contribute_filter_user_roles'] = array_map(fn ($v) => $v ?? [], $result['contribute_filter_user_roles'] ?? []);
        $specifics = $result['contribute_filter_user_roles'];
        $result['filter_user_roles'] = count($specifics)
            ? array_values(array_unique(array_merge($global, ...$specifics)))
            : $global;

        // This value is a single string and specific is a list of strings.
        // Get the latest allow edit.
        $global = $settings->get('contribute_allow_edit_until') ?: 'undertaking';
        $specifics = $result['contribute_allow_edit_until'] ?? [];
        $allowEditUntil = count($specifics)
            ? array_values(array_unique(array_merge([$global], $specifics)))
            : [$global];
        if (in_array('validation', $allowEditUntil)) {
            $result['allow_edit_until'] = 'validation';
        } elseif (in_array('undertaking', $allowEditUntil)) {
            $result['allow_edit_until'] = 'undertaking';
        } elseif (in_array('submission', $allowEditUntil)) {
            $result['allow_edit_until'] = 'submission';
        } else {
            $result['allow_edit_until'] = 'no';
        }

        $contributeModes = $result['modes'];
        $result['use_token'] = in_array('user_token', $contributeModes)
            || in_array('token', $contributeModes);

        $settings->set('contribute_config', $result);
    }
}
