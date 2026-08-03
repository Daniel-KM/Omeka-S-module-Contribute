<?php declare(strict_types=1);

namespace ContributeTest\Service;

use Contribute\Service\SpamCheckerFactory;
use Contribute\Spam\NullSpamChecker;
use Contribute\Spam\SpamGuardChecker;
use Interop\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

class SpamCheckerFactoryTest extends TestCase
{
    public function testNullCheckerWhenSpamGuardMissing(): void
    {
        $checker = (new SpamCheckerFactory())($this->container(null, false), 'Contribute\SpamChecker');
        $this->assertInstanceOf(NullSpamChecker::class, $checker);
    }

    public function testNullCheckerWhenSpamGuardInactive(): void
    {
        $module = $this->module('not-installed');
        $checker = (new SpamCheckerFactory())($this->container($module, true), 'Contribute\SpamChecker');
        $this->assertInstanceOf(NullSpamChecker::class, $checker);
    }

    public function testSpamGuardCheckerWhenActive(): void
    {
        $module = $this->module(\Omeka\Module\Manager::STATE_ACTIVE);
        $checker = (new SpamCheckerFactory())($this->container($module, true), 'Contribute\SpamChecker');
        $this->assertInstanceOf(SpamGuardChecker::class, $checker);
    }

    private function module(string $state): object
    {
        return new class($state) {
            public function __construct(private string $state)
            {
            }
            public function getState(): string
            {
                return $this->state;
            }
        };
    }

    private function container(?object $spamGuardModule, bool $hasSpamChecker): ContainerInterface
    {
        $moduleManager = new class($spamGuardModule) {
            public function __construct(private ?object $spamGuardModule)
            {
            }
            public function getModule($id): ?object
            {
                return $id === 'SpamGuard' ? $this->spamGuardModule : null;
            }
        };

        return new class($moduleManager, $hasSpamChecker) implements ContainerInterface {
            public function __construct(private object $moduleManager, private bool $hasSpamChecker)
            {
            }
            public function get(string $id)
            {
                if ($id === 'Omeka\ModuleManager') {
                    return $this->moduleManager;
                }
                if ($id === 'SpamGuard\SpamChecker') {
                    return new \stdClass();
                }
                return null;
            }
            public function has(string $id): bool
            {
                return $id === 'SpamGuard\SpamChecker' ? $this->hasSpamChecker : false;
            }
        };
    }
}
