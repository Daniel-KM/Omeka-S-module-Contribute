<?php
namespace Correction;

require_once dirname(__DIR__) . '/Generic/AbstractModule.php';

use Generic\AbstractModule;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\MvcEvent;

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
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');
        $acl->allow(null, ['Correction\Controller\Site\Correction']);
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
    }
}
