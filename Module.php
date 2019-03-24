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
        $acl = $this->getServiceLocator()->get('Omeka\Acl')
            ->allow(
                null,
                ['Correction\Controller\Site\Correction'],
                ['edit']
            )
            ->allow(
                null,
                [\Correction\Api\Adapter\CorrectionTokenAdapter::class],
                ['search', 'read', 'update']
            )
            ->allow(
                null,
                [\Correction\Entity\CorrectionToken::class],
                ['update']
            );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Display a link to create a token in the sidebar.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.sidebar',
            [$this, 'adminViewShowSidebar']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.show.sidebar',
            [$this, 'adminViewShowSidebar']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.show.sidebar',
            [$this, 'adminViewShowSidebar']
        );
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
