<?php declare(strict_types=1);

namespace Contribute\Service\ControllerPlugin;

use Contribute\Mvc\Controller\Plugin\SendContributionEmail;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SendContributionEmailFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new SendContributionEmail(
            $services->get('Omeka\Mailer'),
            $services->get('Omeka\Logger')
        );
    }
}
