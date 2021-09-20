<?php declare(strict_types=1);
namespace Contribute;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected $dependency = 'AdvancedResourceTemplate';

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        $this->addAclRules();
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $module = $services->get('Omeka\ModuleManager')->getModule('Generic');
        if ($module && version_compare($module->getIni('version') ?? '', '3.3.30', '<')) {
            $translator = $services->get('MvcTranslator');
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('This module requires the module "%s", version %s or above.'), // @translate
                'Generic', '3.3.30'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    protected function postInstall(): void
    {
        // Upgrade from old module Correction if any.
        $services = $this->getServiceLocator();

        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Correction');
        if ($module) {
            // Check if Correction was really installed.
            $connection = $services->get('Omeka\Connection');
            try {
                $connection->fetchAll('SELECT id FROM correction LIMIT 1;');
                // So upgrade Correction.
                $filepath = $this->modulePath() . '/data/scripts/upgrade_from_correction.php';
                require_once $filepath;
                return;
            } catch (\Exception $e) {
            }
        }
    }

    protected function postUninstall(): void
    {
        if (!class_exists(\Generic\InstallResources::class)) {
            require_once file_exists(dirname(__DIR__) . '/Generic/InstallResources.php')
                ? dirname(__DIR__) . '/Generic/InstallResources.php'
                : __DIR__ . '/src/Generic/InstallResources.php';
        }

        $services = $this->getServiceLocator();
        $installResources = new \Generic\InstallResources($services);
        $installResources = $installResources();

        $installResources->removeResourceTemplate('Contribution');
    }

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules(): void
    {
        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');

        // Since Omeka 1.4, modules are ordered, so Guest come after Contribute.
        // See \Guest\Module::onBootstrap().
        if (!$acl->hasRole('guest')) {
            $acl->addRole('guest');
        }

        // Users who can edit resources can update contributions.
        // A check is done on the specific resource for some roles.
        $validators = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
        ];

        $roles = $acl->getRoles();

        // TODO Limit rights to self contribution (IsSelfAssertion).
        $acl
            ->allow(
                null,
                ['Contribute\Controller\Site\Contribute'],
                ['edit']
            )
            ->allow(
                $validators,
                ['Contribute\Controller\Admin\Contribution']
            )

            ->allow(
                null,
                [\Contribute\Api\Adapter\ContributionAdapter::class],
                ['search', 'create', 'read', 'update']
            )
            ->allow(
                null,
                [\Contribute\Entity\Contribution::class],
                ['create', 'read', 'update']
            )

            ->allow(
                null,
                [\Contribute\Api\Adapter\TokenAdapter::class],
                ['search', 'read', 'update']
            )
            ->allow(
                null,
                [\Contribute\Entity\Token::class],
                ['update']
            )
            ->allow(
                $roles,
                [
                    'Contribute\Controller\Site\GuestBoard',
                ]
            )
       ;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
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

        $controllers = [
            'Omeka\Controller\Admin\Item',
            'Omeka\Controller\Admin\ItemSet',
            'Omeka\Controller\Admin\Media',
        ];
        foreach ($controllers as $controller) {
            // Append a bulk process to create tokens in bulk.
            $sharedEventManager->attach(
                $controller,
                'view.browse.before',
                [$this, 'addHeadersAdmin']
            );
            // Display a link to create a token in the sidebar.
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

        // Handle main settings.
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_input_filters',
            [$this, 'handleMainSettingsFilters']
        );

        $sharedEventManager->attach(
            // \Omeka\Form\ResourceTemplatePropertyFieldset::class,
            \AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset::class,
            'form.add_elements',
            [$this, 'addResourceTemplatePropertyFieldsetElements']
        );
    }

    public function handleViewShowAfter(Event $event): void
    {
        echo $event->getTarget()->linkContribute();
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

    public function adminViewShowSidebar(Event $event): void
    {
        $view = $event->getTarget();
        $resource = $view->resource;
        $query = [
            'resource_type' => $resource->resourceName(),
            'resource_ids' => [$resource->id()],
            'redirect' => $this->getCurrentUrl($view),
        ];
        $translate = $view->plugin('translate');
        $escapeAttr = $view->plugin('escapeHtmlAttr');
        $link = $view->hyperlink(
            $translate('Create contribution token'), // @translate
            $view->url('admin/contribution/default', ['action' => 'create-token'], ['query' => $query])
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

        $plugins = $services->get('ControllerPluginManager');
        $siteSlug = $plugins->get('defaultSiteSlug');
        $siteSlug = $siteSlug();

        echo '<div id="contribution" class="section">';
        echo $view->partial('common/admin/contribute-list', [
            'resource' => $view->resource,
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
        $translate = $view->plugin('translate');
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
            ? sprintf($translate('%d contributions (%d not reviewed)'), $total, $totalNotReviewed) // @translate
            : 'No contribution'; // @translate
        echo <<<HTML
<div class="meta-group">
    <h4>$contributions</h4>
    <div class="value">
        $message
    </div>
</div>

HTML;
    }

    public function handleMainSettingsFilters(Event $event): void
    {
        $event->getParam('inputFilter')
            ->get('contribute')
            ->add([
                'name' => 'contribute_template_default',
                'required' => false,
            ])
            ->add([
                'name' => 'contribute_properties_editable_mode',
                'required' => false,
            ])
            ->add([
                'name' => 'contribute_properties_editable',
                'required' => false,
            ])
            ->add([
                'name' => 'contribute_properties_fillable_mode',
                'required' => false,
            ])
            ->add([
                'name' => 'contribute_properties_fillable',
                'required' => false,
            ])
            ->add([
                'name' => 'contribute_properties_datatype',
                'required' => false,
            ])
            ->add([
                'name' => 'contribute_property_queries',
                'required' => false,
            ])
        ;
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
