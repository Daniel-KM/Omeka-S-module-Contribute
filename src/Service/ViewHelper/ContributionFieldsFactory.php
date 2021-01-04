<?php declare(strict_types=1);
namespace Contribute\Service\ViewHelper;

use Contribute\View\Helper\ContributionFields;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ContributionFieldsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        $propertyIds = $plugins->get('propertyIdsByTerms');
        return new ContributionFields(
            $propertyIds(),
            $plugins->get('contributiveData')
        );
    }
}
