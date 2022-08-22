<?php declare(strict_types=1);

namespace Contribute\Service\ViewHelper;

use Contribute\View\Helper\ContributionSearchFilters;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ContributionSearchFiltersFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ContributionSearchFilters(
            $services->get('Omeka\ApiManager')
        );
    }
}
