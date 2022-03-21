<?php declare(strict_types=1);

namespace Contribute\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * Get the default site slug, or the first one.
 */
class DefaultSiteSlug extends AbstractPlugin
{
    /**
     * @var string|null
     */
    protected $defaultSiteSlug;

    public function __construct(?string $defaultSiteSlug)
    {
        $this->defaultSiteSlug = $defaultSiteSlug;
    }

    /**
     * Return the default site slug, or the first one.
     */
    public function __invoke(): ?string
    {
        return $this->defaultSiteSlug;
    }
}
