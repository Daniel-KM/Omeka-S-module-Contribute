<?php declare(strict_types=1);

namespace Contribute\Service\Controller;

use Contribute\Controller\Admin\ContributionController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AdminContributionControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ContributionController(
            $services->get('Omeka\EntityManager')
        );
    }
}
