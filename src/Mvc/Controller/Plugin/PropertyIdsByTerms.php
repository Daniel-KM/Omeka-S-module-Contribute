<?php
namespace Correction\Mvc\Controller\Plugin;

use Omeka\Mvc\Controller\Plugin\Settings;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;

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
     *
     * @return array
     */
    public function __invoke()
    {
        return $this->properties;
    }
}
