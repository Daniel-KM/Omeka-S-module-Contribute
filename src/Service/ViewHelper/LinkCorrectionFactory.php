<?php
namespace Correction\Service\ViewHelper;

use Correction\View\Helper\LinkCorrection;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class LinkCorrectionFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $checkToken = $services->get('ControllerPluginManager')->get('checkToken');
        return new LinkCorrection(
            $checkToken
        );
    }
}
