<?php
namespace Contribute;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Omeka\Settings\SettingsInterface;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\MvcEvent;
use Zend\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $this->addAclRules();
    }

    protected function postInstall()
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $resourceTemplate = $services->get('Omeka\ApiManager')->read('resource_templates', ['label' => 'Contribution'])->getContent();
        $templateData = $settings->get('contribute_resource_template_data', []);
        $templateData['editable'][(string) $resourceTemplate->id()] = ['dcterms:title', 'dcterms:description'];
        $templateData['fillable'][(string) $resourceTemplate->id()] = ['dcterms:title', 'dcterms:description'];
        $settings->set('contribute_resource_template_data', $templateData);
        $settings->set('contribute_template_editable', $resourceTemplate->id());

        // Upgrade from old module Correction if any.
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');

        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Correction');
        if ($module) {
            // Check if Correction was really installed.
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

    protected function postUninstall()
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
    protected function addAclRules()
    {
        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');

        // Users who can edit resources can update contributions.
        // A check is done on the specific resource for some roles.
        $roles = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
        ];

        $acl
            ->allow(
                null,
                ['Contribute\Controller\Site\Contribute'],
                ['edit']
            )
            ->allow(
                $roles,
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
            );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
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

        // Manage resource template.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ResourceTemplate',
            'view.layout',
            [$this, 'addHeadersAdminResourceTemplate']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ResourceTemplateAdapter::class,
            'api.create.post',
            [$this, 'handleResourceTemplateCreateOrUpdatePost']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ResourceTemplateAdapter::class,
            'api.update.post',
            [$this, 'handleResourceTemplateCreateOrUpdatePost']
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
    }

    protected function prepareDataToPopulate(SettingsInterface $settings, $settingsType)
    {
        $data = parent::prepareDataToPopulate($settings, $settingsType);
        if (in_array($settingsType, ['settings'])) {
            if (isset($data['contribute_notify']) && is_array($data['contribute_notify'])) {
                $data['contribute_notify'] = implode("\n", $data['contribute_notify']);
            }
        }
        return $data;
    }

    public function handleViewShowAfter(Event $event)
    {
        echo $event->getTarget()->linkContribute();
    }

    public function handleResourceTemplateCreateOrUpdatePost(Event $event)
    {
        // The acl are already checked via the api.
        $request = $event->getParam('request');
        $response = $event->getParam('response');
        $services = $this->getServiceLocator();

        $viewHelpers = $services->get('ViewHelperManager');
        $api = $viewHelpers->get('api');

        $requestContent = $request->getContent();
        $requestResourceProperties = isset($requestContent['o:resource_template_property']) ? $requestContent['o:resource_template_property'] : [];

        $contributives = ['editable' => [], 'fillable' => []];
        foreach (['editable' => 'contribution_editable_part', 'fillable' => 'contribution_fillable_part'] as $editableKey => $part) {
            foreach ($requestResourceProperties as $propertyId => $requestResourceProperty) {
                if (!isset($requestResourceProperty['data'][$part]) || $requestResourceProperty['data'][$part] != 1) {
                    continue;
                }
                try {
                    /** @var \Omeka\Api\Representation\PropertyRepresentation $property */
                    $property = $api->read('properties', $propertyId)->getContent();
                    // $term = $api->searchOne('properties', ['id' => $propertyId])->getContent()->term();
                } catch (\Omeka\Api\Exception\NotFoundException $e) {
                    continue;
                }
                $contributives[$editableKey][] = $property->term();
            }
        }

        $resourceTemplateId = $response->getContent()->getId();
        $settings = $services->get('Omeka\Settings');
        $resourceTemplateData = $settings->get('contribute_resource_template_data', []);
        $resourceTemplateData['editable'][$resourceTemplateId] = $contributives['editable'];
        $resourceTemplateData['fillable'][$resourceTemplateId] = $contributives['fillable'];

        $settings->set('contribute_resource_template_data', $resourceTemplateData);
    }

    public function addHeadersAdmin(Event $event)
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/contribute-admin.css', 'Contribute'));
        $view->headScript()
            ->appendFile($assetUrl('js/contribute-admin.js', 'Contribute'), 'text/javascript', ['defer' => 'defer']);
    }

    public function addHeadersAdminResourceTemplate(Event $event)
    {
        $view = $event->getTarget();
        $view->headScript()
            ->appendFile($view->assetUrl('js/contribute-admin-resource-template.js', 'Contribute'), 'text/javascript', ['defer' => 'defer']);
    }

    public function adminViewShowSidebar(Event $event)
    {
        $view = $event->getTarget();
        $resource = $view->resource;
        $query = [];
        $query['resource_type'] = $resource->resourceName();
        $query['resource_ids'] = [$resource->id()];
        $query['redirect'] = $this->getCurrentUrl($view);
        $translate = $view->plugin('translate');
        $escapeAttr = $view->plugin('escapeHtmlAttr');
        $link = $view->hyperlink(
            $translate('Create contribution token'), // @translate
            $view->url('admin/contribution/default', ['action' => 'create-token'], ['query' => $query])
        );
        $output =  '<div class="meta-group create_contribution_token">'
            . '<h4>' . $translate('Contribute') . '</h4>'
            . '<div class="value" id="create_contribution_token">' . $link . '</div>'
            . '<div id="create_contribution_token_dialog" class="modal" style="display:none;">'
            . '<div class="modal-content">'
            . '<span class="close" id="create_contribution_token_dialog_close">&times;</span>'
            . '<input type="text" value="" placeholder="' . $escapeAttr($translate('Please input optional email…')) . '" id="create_contribution_token_dialog_email"/>'
            . '<input type="button" value="' . $escapeAttr($translate('Create token')) . '" id="create_contribution_token_dialog_go"/>'
            . '</div>'
            . '</div>'
            . '</div>';
        echo $output;
    }

    /**
     * Add a tab to section navigation.
     *
     * @param Event $event
     */
    public function appendTab(Event $event)
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
    public function displayTab(Event $event)
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
    public function viewDetails(Event $event)
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

        // TODO
        echo '<div class="meta-group"><h4>'
            . $translate('Contributions') // @translate
            . '</h4><div class="value">';
        if ($total) {
            echo sprintf($translate('%d contributions (%d not reviewed)'), $total, $totalNotReviewed); // @translate
        } else {
            echo '<em>'
                . $translate('No contribution') // @translate
                . '</em>';
        }
        echo '</div></div>';
    }

    public function handleMainSettings(Event $event)
    {
        parent::handleMainSettings($event);

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $fieldset = $event
            ->getTarget()
            ->get('contribute');

        $queries = $settings->get('contribute_property_queries') ?: [];
        $value = '';
        if (is_array($queries)) {
            foreach ($queries as $term => $query) {
                $value .= $term . ' = ' . urldecode(http_build_query($query, null, '&', PHP_QUERY_RFC3986)) . "\n";
            }
        }
        $fieldset
            ->get('contribute_property_queries')
            ->setValue($value);
    }

    public function handleMainSettingsFilters(Event $event)
    {
        $event->getParam('inputFilter')
            ->get('contribute')
            ->add([
                'name' => 'contribute_notify',
                'required' => false,
                'filters' => [
                    [
                        'name' => \Zend\Filter\Callback::class,
                        'options' => [
                            'callback' => [$this, 'stringToList'],
                        ],
                    ],
                ],
            ])
            ->add([
                'name' => 'contribute_template_editable',
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
                'filters' => [
                    [
                        'name' => \Zend\Filter\Callback::class,
                        'options' => [
                            'callback' => function ($v) {
                                $result = [];
                                $q = [];
                                $w = $this->stringToList($v);
                                foreach ($w as $vv) {
                                    list($term, $query) = array_map('trim', explode('=', $vv, 2));
                                    if ($term) {
                                        parse_str($query, $q);
                                        $result[$term] = array_filter($q);
                                    }
                                }
                                return array_filter($result);
                            },
                        ],
                    ],
                ],
            ])
        ;
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
        $query = http_build_query($view->params()->fromQuery());
        return $query
            ? $url . '?' . $query
            : $url;
    }
}
