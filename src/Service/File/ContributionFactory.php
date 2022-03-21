<?php declare(strict_types=1);

namespace Contribute\Service\File;

use Contribute\File\Contribution;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ContributionFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new Contribution(
            $services->get(\Omeka\File\TempFileFactory::class),
            $services->get('Omeka\Logger'),
            $services->get('Omeka\File\Store'),
            $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files')
        );
    }
}
