<?php
namespace Correction;

require_once dirname(__DIR__) . '/Generic/AbstractModule.php';

use Generic\AbstractModule;
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

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules()
    {
        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');

        // Users who can edit resources can update corrections.
        // A check is done on the specific resource for some roles.
        $roles = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
            \Omeka\Permissions\Acl::ROLE_AUTHOR,
            \Omeka\Permissions\Acl::ROLE_RESEARCHER,
        ];

        $acl
            ->allow(
                null,
                ['Correction\Controller\Site\Correction'],
                ['edit']
            )
            ->allow(
                $roles,
                ['Correction\Controller\Admin\Correction']
            )

            ->allow(
                null,
                [\Correction\Api\Adapter\CorrectionAdapter::class],
                ['search', 'create', 'read', 'update']
            )
            ->allow(
                null,
                [\Correction\Entity\Correction::class],
                ['create', 'read', 'update']
            )

            ->allow(
                null,
                [\Correction\Api\Adapter\TokenAdapter::class],
                ['search', 'read', 'update']
            )
            ->allow(
                null,
                [\Correction\Entity\Token::class],
                ['update']
            );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
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

    public function addHeadersAdmin(Event $event)
    {
        $view = $event->getTarget();
        $view->headLink()->appendStylesheet($view->assetUrl('css/correction-admin.css', 'Correction'));
        $view->headScript()->appendFile($view->assetUrl('js/correction-admin.js', 'Correction'));
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
        $link = $view->hyperlink(
            $translate('Create correction token'), // @translate
            $view->url('admin/correction/default', ['action' => 'create-token'], ['query' => $query])
        );
        echo '<div class="meta-group">'
            . '<h4>' . $translate('Correction') . '</h4>'
            . '<div class="value">' . $link . '</div>'
            . '</div>';
    }

    /**
     * Add a tab to section navigation.
     *
     * @param Event $event
     */
    public function appendTab(Event $event)
    {
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['correction'] = 'Corrections'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    /**
     * Display a partial for a resource.
     *
     * @param Event $event
     */
    public function displayTab(Event $event)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $view = $event->getTarget();

        $resource = $view->resource;
        $corrections = $api
            ->search('corrections', [
                'resource_id' => $resource->id(),
                'sort_by' => 'modified',
                'sort_order' => 'DESC',
            ])
            ->getContent();

        echo '<div id="correction" class="section">';
        echo $view->partial('common/admin/correction-list', [
            'resource' => $view->resource,
            'corrections' => $corrections,
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
            ->search('corrections', [
                'resource_id' => $resource->id(),
            ])
            ->getTotalResults();
        $totalNotReviewed = $view->api()
            ->search('corrections', [
                'resource_id' => $resource->id(),
                'reviewed' => '0',
            ])
            ->getTotalResults();

        // TODO
        echo '<div class="meta-group"><h4>'
            . $translate('Correction') // @translate
            . '</h4><div class="value">';
        if ($total) {
            echo sprintf($translate('%d corrections (%d not reviewed)'), $total, $totalNotReviewed); // @translate
        } else {
            echo '<em>'
                . $translate('No correction') // @translate
                . '</em>';
        }
        echo '</div></div>';
    }

    public function handleMainSettingsFilters(Event $event)
    {
        $inputFilter = $event->getParam('inputFilter');
        $inputFilter->get('correction')->add([
            'name' => 'correction_properties',
            'required' => false,
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
        $query = http_build_query($view->params()->fromQuery());
        return $query
            ? $url . '?' . $query
            : $url;
    }
}
