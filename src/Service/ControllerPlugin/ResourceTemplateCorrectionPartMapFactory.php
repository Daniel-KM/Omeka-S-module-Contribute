<?php
namespace Correction\Service\ControllerPlugin;

use Correction\Mvc\Controller\Plugin\ResourceTemplateCorrectionPartMap;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class ResourceTemplateCorrectionPartMapFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ResourceTemplateCorrectionPartMap(
            $services->get('ControllerPluginManager')->get('settings')
        );
    }
}
