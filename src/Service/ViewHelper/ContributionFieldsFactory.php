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
        return new ContributionFields(
            $plugins->get('propertyIdsByTerms')(),
            $plugins->get('contributiveData'),
            // In Omeka, it is simpler to check the class than the module.
            class_exists(\AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyDataRepresentation::class)
        );
    }
}
