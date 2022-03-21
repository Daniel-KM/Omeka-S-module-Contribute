<?php declare(strict_types=1);

namespace Contribute\Service\Controller;

use Contribute\Controller\Site\ContributionController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SiteContributionControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config = $services->get('Config');
        return new ContributionController(
            $services->get(\Omeka\File\Uploader::class),
            $services->get(\Omeka\File\TempFileFactory::class),
            $services->get('Omeka\EntityManager'),
            $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files'),
            $config
        );
    }
}
