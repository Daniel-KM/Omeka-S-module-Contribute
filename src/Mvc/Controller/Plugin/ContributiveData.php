<?php declare(strict_types=1);

namespace Contribute\Mvc\Controller\Plugin;

use ArrayObject;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\ResourceTemplateRepresentation;

class ContributiveData extends AbstractPlugin
{
    /**
     * @var \ArrayObject
     */
    protected $data;

    /**
     * Get contributive data (editable, fillable, etc.) of a resource template.
     *
     *  The list comes from the resource template if it is configured, else the
     *  default list is used.
     *
     * @param \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation|\Omeka\Api\Representation\ResourceTemplateRepresentation|string|int|null $template
     */
    public function __invoke($resourceTemplate = null): self
    {
        $this->data = new ArrayObject([
            'is_contributive' => false,
            'template' => null,
            'required' => false,
            'max_values' => 0,
            'editable_mode' => 'whitelist',
            'editable' => [],
            'fillable_mode' => 'whitelist',
            'fillable' => [],
            'datatype' => [],
            'datatypes_default' => [],
        ]);

        $controller = $this->getController();
        $settings = $controller->settings();
        $this->data['datatypes_default'] = ['literal', 'resource', 'uri'];

        // TODO Manage valuesuggest differently, because it is not a single datatype.
        if (($has = array_search('valuesuggest', $this->data['datatypes_default'])) !== false) {
            unset($this->data['datatypes_default'][$has]);
        }

        $resourceTemplate = $this->resourceTemplate($resourceTemplate);

        if (!$resourceTemplate) {
            $resourceTemplateId = $settings->get('contribute_templates', []);
            $resourceTemplateId = reset($resourceTemplateId);
            if ($resourceTemplateId) {
                $resourceTemplate = $controller->api()->searchOne('resource_templates', ['id' => $resourceTemplateId])->getContent();
            }
            if (!$resourceTemplate) {
                $controller->logger()->err('A resource template must be set to allow to contribute'); // @translate
                return $this;
            }
        }

        $this->data['template'] = $resourceTemplate;
        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $resourceTemplateProperty */
        foreach ($resourceTemplate->resourceTemplateProperties() as $resourceTemplateProperty) {
            $property = $resourceTemplateProperty->property();
            $propertyId = $property->id();
            $term = $property->term();
            $datatype = $resourceTemplateProperty->dataType();
            $this->data['datatype'][$term] = $datatype ? [$datatype] : $this->data['datatypes_default'];
            $this->data['required'] = $resourceTemplateProperty->isRequired();
            if (!method_exists($resourceTemplateProperty, 'data')) {
                continue;
            }
            $rtpData = $resourceTemplateProperty->data();
            if (!count($rtpData)) {
                continue;
            }
            // TODO Manage repeatable property.
            $rtpData = reset($rtpData);
            $this->data['max_values'] = (int) $rtpData->dataValue('max_values', 0);
            if ($rtpData->dataValue('editable', false)) {
                $this->data['editable'][$term] = $propertyId;
            }
            if ($rtpData->dataValue('fillable', false)) {
                $this->data['fillable'][$term] = $propertyId;
            }
        }

        $this->data['is_contributive'] = count($this->data['datatypes_default'])
            || count($this->data['editable'])
            || count($this->data['fillable'])
            || in_array($this->data['editable_mode'], ['all', 'blacklist'])
            || in_array($this->data['fillable_mode'], ['all', 'blacklist']);

        return $this;
    }

    public function data(): ArrayObject
    {
        return $this->data;
    }

    public function isContributive(): bool
    {
        return $this->data['is_contributive'];
    }

    /**
     * @todo Always true: remove this method.
     */
    public function hasTemplate(): bool
    {
        return !empty($this->data['template']);
    }

    public function template(): ?ResourceTemplateRepresentation
    {
        return $this->data['template'];
    }

    public function isRequired(): bool
    {
        return $this->data['required'];
    }

    public function maxValues(): int
    {
        return $this->data['max_values'];
    }

    public function editableMode(): string
    {
        return $this->data['editable_mode'];
    }

    public function editableProperties(): array
    {
        return $this->data['editable'];
    }

    public function fillableMode(): string
    {
        return $this->data['fillable_mode'];
    }

    public function fillableProperties(): array
    {
        return $this->data['fillable'];
    }

    public function datatypeProperties(): array
    {
        return $this->data['datatype'];
    }

    public function defaultDatatypes(): array
    {
        return $this->data['datatypes_default'];
    }

    public function datatypeTerm(?string $term): array
    {
        return empty($this->data['datatype'][$term])
            ? $this->data['datatypes_default']
            : $this->data['datatype'][$term];
    }

    public function isTermContributive(?string $term): bool
    {
        return $this->isTermEditable($term)
            || $this->isTermFillable($term);
    }

    public function isTermEditable(?string $term): bool
    {
        if ($this->hasTemplate()) {
            return isset($this->data['editable'][$term])
                && !empty($this->data['datatype'][$term]);
        }
        return count($this->data['datatypes_default'])
            && (
                ($this->data['editable_mode'] === 'all')
                || ($this->data['editable_mode'] === 'whitelist' && isset($this->data['editable'][$term]))
                || ($this->data['editable_mode'] === 'blacklist' && !isset($this->data['editable'][$term]))
            );
    }

    public function isTermFillable(?string $term): bool
    {
        if ($this->hasTemplate()) {
            return isset($this->data['fillable'][$term])
                && !empty($this->data['datatype'][$term]);
        }
        return count($this->data['datatypes_default'])
            && (
                ($this->data['fillable_mode'] === 'all')
                || ($this->data['fillable_mode'] === 'whitelist' && isset($this->data['fillable'][$term]))
                || ($this->data['fillable_mode'] === 'blacklist' && !isset($this->data['fillable'][$term]))
            );
    }

    /**
     * Check if the datatype is managed for the specified term.
     */
    public function isTermDatatype(?string $term, ?string $datatype): bool
    {
        if ($this->hasTemplate()) {
            return !empty($this->data['datatype'][$term])
                && in_array($datatype, $this->data['datatype'][$term]);
        }
        return $this->isDefaultDatatype($datatype);
    }

    public function isDefaultDatatype(?string $datatype): bool
    {
        return in_array($datatype, $this->data['datatypes_default']);
    }

    /**
     * Get the resource template.
     *
     * @var \Omeka\Api\Representation\ResourceTemplateRepresentation|int|string|null $resourceTemplate
     */
    protected function resourceTemplate($resourceTemplate): ?ResourceTemplateRepresentation
    {
        if (empty($resourceTemplate) || is_object($resourceTemplate)) {
            return $resourceTemplate;
        }

        if (is_numeric($resourceTemplate)) {
            try {
                return $this->getController()->api()->read('resource_templates', ['id' => $resourceTemplate])->getContent();
            } catch (\Omeka\Api\Exception\NotFoundException $e) {
                return null;
            }
        }

        if (is_string($resourceTemplate)) {
            return $this->getController()->api()->searchOne('resource_templates', ['label' => $resourceTemplate])->getContent();
        }

        return null;
    }
}
