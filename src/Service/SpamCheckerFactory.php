<?php declare(strict_types=1);

namespace Contribute\Service;

use Contribute\Spam\NullSpamChecker;
use Contribute\Spam\SpamGuardChecker;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SpamCheckerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $moduleManager = $services->get('Omeka\ModuleManager');
        $spamGuard = $moduleManager->getModule('SpamGuard');
        if ($spamGuard
            && $spamGuard->getState() === \Omeka\Module\Manager::STATE_ACTIVE
            && $services->has('SpamGuard\SpamChecker')
        ) {
            return new SpamGuardChecker($services->get('SpamGuard\SpamChecker'));
        }
        return new NullSpamChecker();
    }
}
