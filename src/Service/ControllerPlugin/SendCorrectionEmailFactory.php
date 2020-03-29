<?php
namespace Correction\Service\ControllerPlugin;

use Correction\Mvc\Controller\Plugin\SendCorrectionEmail;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class SendCorrectionEmailFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedNamed, array $options = null)
    {
        return new SendCorrectionEmail(
            $services->get('Omeka\Mailer'),
            $services->get('Omeka\Logger')
        );
    }
}
