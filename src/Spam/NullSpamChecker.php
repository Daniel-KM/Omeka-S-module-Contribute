<?php declare(strict_types=1);

namespace Contribute\Spam;

/**
 * No-op checker used when the SpamGuard module is not active.
 *
 * Contribute has no built-in spam engine, so every submission passes.
 */
class NullSpamChecker implements SpamCheckerInterface
{
    public function check(array $context): array
    {
        return [];
    }
}
