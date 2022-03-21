<?php declare(strict_types=1);

namespace Contribute\Service\Media\Ingester;

use Contribute\Media\Ingester\Contribution;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ContributionFactory implements FactoryInterface
{
    /**
     * Create the Contribution media ingester service.
     *
     * @return Contribution
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new Contribution(
            $services->get(\Contribute\File\Contribution::class)
        );
    }
}
