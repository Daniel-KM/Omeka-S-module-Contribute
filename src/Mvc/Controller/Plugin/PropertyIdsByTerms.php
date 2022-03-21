<?php declare(strict_types=1);

namespace Contribute\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class PropertyIdsByTerms extends AbstractPlugin
{
    /**
     * @var array
     */
    protected $properties;

    public function __construct(array $properties)
    {
        $this->properties = $properties;
    }

    /**
     * Get all property ids by terms.
     */
    public function __invoke(): array
    {
        return $this->properties;
    }
}
