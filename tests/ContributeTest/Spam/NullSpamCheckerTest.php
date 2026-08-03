<?php declare(strict_types=1);

namespace ContributeTest\Spam;

use Contribute\Spam\NullSpamChecker;
use PHPUnit\Framework\TestCase;

class NullSpamCheckerTest extends TestCase
{
    public function testAlwaysReturnsNoReason(): void
    {
        $checker = new NullSpamChecker();
        $this->assertSame([], $checker->check([]));
        $this->assertSame([], $checker->check([
            'ip' => '1.2.3.4',
            'userAgent' => 'Bot/1.0',
            'body' => 'Buy now http://spam.example',
        ]));
    }
}
