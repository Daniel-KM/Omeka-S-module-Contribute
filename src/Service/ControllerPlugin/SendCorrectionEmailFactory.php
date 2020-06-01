<?php
namespace Contribute\Service\ControllerPlugin;

use Contribute\Mvc\Controller\Plugin\SendContributeEmail;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class SendContributeEmailFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedNamed, array $options = null)
    {
        return new SendContributeEmail(
            $services->get('Omeka\Mailer'),
            $services->get('Omeka\Logger')
        );
    }
}
