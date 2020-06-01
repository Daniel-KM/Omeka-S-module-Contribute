<?php
namespace Contribute\Service\ViewHelper;

use Contribute\View\Helper\LinkContribute;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class LinkContributeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $checkToken = $services->get('ControllerPluginManager')->get('checkToken');
        return new LinkContribute(
            $checkToken
        );
    }
}