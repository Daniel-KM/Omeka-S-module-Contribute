<?php declare(strict_types=1);

namespace Contribute\Spam;

/**
 * Adapter delegating the spam check to the SpamGuard module.
 *
 * Instantiated only when SpamGuard is active. SpamGuard runs the enabled
 * strategies (dnsbl, bannedIp, keyword, url count, dns mx…) on the context.
 */
class SpamGuardChecker implements SpamCheckerInterface
{
    /**
     * @var \SpamGuard\SpamChecker
     */
    protected $spamChecker;

    /**
     * @param \SpamGuard\SpamChecker $spamChecker
     */
    public function __construct($spamChecker)
    {
        $this->spamChecker = $spamChecker;
    }

    public function check(array $context): array
    {
        $ctx = new \SpamGuard\SpamContext(
            ($context['ip'] ?? '') ?: null,
            (string) ($context['userAgent'] ?? ''),
            ($context['email'] ?? '') ?: null,
            ($context['subject'] ?? '') ?: null,
            ($context['body'] ?? '') ?: null,
            ((int) ($context['formLoadedAt'] ?? 0)) ?: null,
            ($context['honeypot'] ?? '') ?: null,
            null,
            [
                'powSalt' => ($context['powSalt'] ?? '') ?: null,
                'powNonce' => ($context['powNonce'] ?? '') ?: null,
                'lastSubmitAt' => ((int) ($context['prevSubmitAt'] ?? 0)) ?: null,
                'lastSubmitIp' => ($context['prevSubmitIp'] ?? '') ?: null,
            ]
        );
        $result = $this->spamChecker->check($ctx);
        return $result->isSpam() ? $result->reasons : [];
    }
}
