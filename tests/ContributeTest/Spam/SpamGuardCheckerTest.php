<?php declare(strict_types=1);

namespace ContributeTest\Spam;

use Contribute\Spam\SpamGuardChecker;
use PHPUnit\Framework\TestCase;

class SpamGuardCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\SpamGuard\SpamResult::class)) {
            $this->markTestSkipped('Requires SpamGuard module.');
        }
    }

    public function testDelegatesContextAndReturnsReasons(): void
    {
        $spamChecker = new class {
            public ?\SpamGuard\SpamContext $received = null;
            public function check(\SpamGuard\SpamContext $context): \SpamGuard\SpamResult
            {
                $this->received = $context;
                return new \SpamGuard\SpamResult(['dnsbl', 'bannedIp']);
            }
        };

        $reasons = (new SpamGuardChecker($spamChecker))->check([
            'ip' => '1.2.3.4',
            'userAgent' => 'Bot/1.0',
            'body' => 'spam body',
        ]);

        $this->assertSame(['dnsbl', 'bannedIp'], $reasons);
        $this->assertSame('1.2.3.4', $spamChecker->received->ip);
        $this->assertSame('Bot/1.0', $spamChecker->received->userAgent);
        $this->assertSame('spam body', $spamChecker->received->body);
    }

    public function testCleanSubmissionReturnsNoReason(): void
    {
        $spamChecker = new class {
            public function check(\SpamGuard\SpamContext $context): \SpamGuard\SpamResult
            {
                return new \SpamGuard\SpamResult([]);
            }
        };

        $this->assertSame([], (new SpamGuardChecker($spamChecker))->check([
            'ip' => '1.2.3.4',
            'body' => 'a legitimate contribution',
        ]));
    }
}
