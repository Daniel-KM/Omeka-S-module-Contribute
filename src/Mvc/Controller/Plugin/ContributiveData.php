<?php declare(strict_types=1);

namespace Contribute\Mvc\Controller\Plugin;

use ArrayObject;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\ResourceTemplateRepresentation;
use Omeka\Stdlib\Message;

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
     *  list of the first allowed resource template is used.
     *
     * The template can contain a sub-template for files. It is set in the main
     * resource template too (one level recursivity).
     *
     * The input for a file is specific and not managed here.
     *
     * @todo Remove code that set fields or use default datatypes without resource template.
     *
     * @param \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation|\Omeka\Api\Representation\ResourceTemplateRepresentation|string|int|null $template
     */
    public function __invoke($resourceTemplate = null, ?bool $isSubTemplate = false): self
    {
        $isSubTemplate = (bool) $isSubTemplate;
        $this->data = new ArrayObject([
            'is_contributive' => false,
            'template' => null,
            'required' => false,
            'min_values' => 0,
            'max_values' => 0,
            'editable_mode' => 'whitelist',
            'editable' => [],
            'fillable_mode' => 'whitelist',
            'fillable' => [],
            'datatype' => [],
            'datatypes_default' => [],
            'template_media' => null,
            'is_sub_template' => $isSubTemplate,
            // Keep false when not checked, then sub contributive data or null.
            'contributive_media' => false,
        ]);

        $controller = $this->getController();
        $settings = $controller->settings();
        $this->data['datatypes_default'] = ['literal', 'resource', 'uri'];

        // TODO Manage valuesuggest and custom vocab differently, because it is not a single datatype.
        // TODO Remove default data types (or limit it to literal) (currently hard coded like omeka, so useless).
        // TODO Check if these check are useless, since a resource template is required.
        if (($has = array_search('valuesuggest', $this->data['datatypes_default'])) !== false) {
            unset($this->data['datatypes_default'][$has]);
        }
        if (($has = array_search('customvocab', $this->data['datatypes_default'])) !== false) {
            unset($this->data['datatypes_default'][$has]);
        }

        $resourceTemplate = $this->resourceTemplate($resourceTemplate);
        $allowedResourceTemplates = $settings->get($isSubTemplate ? 'contribute_templates_media' : 'contribute_templates', []) ?: [];

        // When a resource template is set, it should be allowed too.
        // Anyway, if it is not prepared, it won't be editable/fillable (below).
        // Else first resource template.
        if ($resourceTemplate) {
            $resourceTemplateId = $resourceTemplate->id();
            if (!in_array($resourceTemplateId, $allowedResourceTemplates)) {
                $controller->logger()->err(new Message(
                    $isSubTemplate
                        ? 'The resource template "%s" is not in the list of allowed contribution templates for media.' // @translate
                        : 'The resource template "%s" is not in the list of allowed contribution templates.', // @translate
                    $resourceTemplateId
                ));
                return $this;
            }
        } else {
            $resourceTemplate = reset($allowedResourceTemplates);
            if ($resourceTemplate) {
                $resourceTemplate = $this->resourceTemplate($resourceTemplate);
            }
            if (!$resourceTemplate) {
                $controller->logger()->err('A resource template must be set to allow to contribute'); // @translate
                return $this;
            }
        }

        $this->data['template'] = $resourceTemplate;

        if (!method_exists($resourceTemplate, 'data')) {
            $controller->logger()->err('The module Advanced Resource Template is not available.'); // @translate
            return $this;
        }

        /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $resourceTemplateProperty */
        foreach ($resourceTemplate->resourceTemplateProperties() as $resourceTemplateProperty) {
            $property = $resourceTemplateProperty->property();
            $propertyId = $property->id();
            $term = $property->term();
            $this->data['datatype'][$term] = $resourceTemplateProperty->dataTypes() ?: $this->data['datatypes_default'];
            $this->data['required'] = $resourceTemplateProperty->isRequired();
            $rtpData = $resourceTemplateProperty->mainData();
            if (!$rtpData) {
                continue;
            }
            // TODO Manage repeatable property.
            $this->data['min_values'] = (int) $rtpData->dataValue('min_values');
            $this->data['max_values'] = (int) $rtpData->dataValue('max_values');
            if ($rtpData->dataValue('editable')) {
                $this->data['editable'][$term] = $propertyId;
            }
            if ($rtpData->dataValue('fillable')) {
                $this->data['fillable'][$term] = $propertyId;
            }
        }

        if (!$isSubTemplate) {
            /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $resourceTemplateMedia */
            $resourceTemplateMedia = $this->resourceTemplate($resourceTemplate->dataValue('contribute_template_media'));
            $resourceTemplateMediaId = $resourceTemplateMedia ? $resourceTemplateMedia->id() : null;
            $allowedResourceTemplatesMedia = $settings->get('contribute_templates_media') ?: [];
            if (in_array($resourceTemplateMediaId, $allowedResourceTemplatesMedia)) {
                $this->data['template_media'] = $resourceTemplateMedia;
            } elseif ($resourceTemplateMediaId) {
                $controller->logger()->err(new Message(
                    'The resource template "%s" is not in the list of allowed contribution templates for media.', // @translate
                    $resourceTemplateMediaId
                ));
                // No break: allow to submit partially.
            }
        }

        // The resource template is checked above.
        $this->data['is_contributive'] = count($this->data['datatypes_default'])
            || count($this->data['editable'])
            || count($this->data['fillable'])
            // TODO Remove editable mode / fillable mode since a template is required now.
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

    public function minValues(): int
    {
        return $this->data['min_values'];
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

    public function isSubTemplate(): bool
    {
        return $this->data['is_sub_template'];
    }

    /**
     * Get the contributive data for the media sub-template.
     *
     * Like main template, the media template should have at least one property.
     */
    public function contributiveMedia(): ?\Contribute\Mvc\Controller\Plugin\ContributiveData
    {
        if ($this->data['contributive_media'] === null
            || !$this->isContributive()
            || empty($this->data['template_media'])
            || $this->isSubTemplate()
        ) {
            return null;
        }

        // Clone() allows to get to contributive data with a different config.
        /** @var \Contribute\Mvc\Controller\Plugin\ContributiveData $contributiveMedia */
        $contributiveMedia = clone $this->getController()->plugin('contributiveData');
        $contributiveMedia = $contributiveMedia($this->data['template_media'], true);
        if (!$contributiveMedia->isContributive()) {
            $contributiveMedia = null;
        }
        $this->data['contributive_media'] = $contributiveMedia;
        return $this->data['contributive_media'];
    }

    /**
     * Get the resource template.
     *
     * @var \Omeka\Api\Representation\ResourceTemplateRepresentation|int|string|null $resourceTemplate
     *
     * @return \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation|ResourceTemplateRepresentation|
     */
    protected function resourceTemplate($template): ?ResourceTemplateRepresentation
    {
        if (empty($template) || is_object($template)) {
            return $template ?: null;
        }

        try {
            return $this->getController()->api()->read('resource_templates', is_numeric($template) ? ['id' => $template] : ['label' => $template])->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            $this->getController()->logger()->warn(new Message('The template "%s" does not exist and cannot be used for contribution.', $template)); // @translate
            return null;
        }
    }
}
