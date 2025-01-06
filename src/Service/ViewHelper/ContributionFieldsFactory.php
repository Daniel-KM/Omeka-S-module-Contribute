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
        $moduleManager = $services->get('Omeka\ModuleManager');
        return new ContributionFields(
            $plugins->get('contributiveData'),
            $services->get('Common\EasyMeta'),
            // Check if modules are available.
            // Anyway, AdvancedResourceTemplate is a required dependency.
            ($module = $moduleManager->getModule('AdvancedResourceTemplate')) && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE,
            ($module = $moduleManager->getModule('CustomVocab')) && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE,
            ($module = $moduleManager->getModule('NumericDataTypes')) && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE,
            ($module = $moduleManager->getModule('ValueSuggest')) && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE
        );
    }
}
