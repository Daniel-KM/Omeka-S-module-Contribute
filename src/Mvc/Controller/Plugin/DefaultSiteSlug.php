<?php declare(strict_types=1);
namespace Contribute\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * Get the default site slug, or the first one.
 */
class DefaultSiteSlug extends AbstractPlugin
{
    /**
     * @var string
     */
    protected $defaultSiteSlug;

    /**
     * Construct the helper.
     *
     * @param string|null $defaultSiteSlug
     */
    public function __construct($defaultSiteSlug)
    {
        $this->defaultSiteSlug = $defaultSiteSlug;
    }

    /**
     * Return the default site slug, or the first one.
     *
     * @return string|null
     */
    public function __invoke()
    {
        return $this->defaultSiteSlug;
    }
}
