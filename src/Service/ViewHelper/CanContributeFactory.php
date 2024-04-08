<?php declare(strict_types=1);

namespace Contribute\Service\ViewHelper;

use Contribute\View\Helper\CanContribute;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class CanContributeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        return new CanContribute(
            $plugins->has('isCasUser') ? $plugins->get('isCasUser') : null,
            $plugins->has('isLdapUser') ? $plugins->get('isLdapUser') : null,
            $plugins->has('isSsoUser') ? $plugins->get('isSsoUser') : null,
        );
    }
}
