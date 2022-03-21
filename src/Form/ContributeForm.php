<?php declare(strict_types=1);

namespace Contribute\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ContributeForm extends Form
{
    /**
     * @var array
     */
    protected $templates = [];

    /**
     * @var bool
     */
    protected $displaySelectTemplate = false;

    public function __construct($name = null, ?array $options = null)
    {
        // TODO (but working for now inside module) Omeka inverts the options and the name when using getForm().
        is_array($name)
            ? parent::__construct(null, $name)
            : parent::__construct($name, $options);
    }

    public function init(): void
    {
        // Steps are "template", "add", "edit", "show".
        // Next can be "add", "edit", "show" + an optional query for complex
        // workflow. It should be set inside theme.
        // The "next" post argument can be bypassed by a query argument in order
        // to manage multiple ways.
        // Mode can be "read" or "write" (default).

        if ($this->displaySelectTemplate) {
            $this
                ->add([
                    'name' => 'template',
                    'type' => Element\Radio::class,
                    'options' => [
                        // End user doesn't know what is a "resource template".
                        'label' => 'Type of item', // @translate
                        'value_options' => $this->templates,
                        'label_attributes' => [
                            'class' => 'required',
                        ],
                    ],
                    'attributes' => [
                        'id' => 'template',
                        'required' => true,
                    ],
                ])
                ->add([
                    'name' => 'step',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'id' => 'step',
                        'value' => 'template',
                    ],
                ])
                ->add([
                    'name' => 'next',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'id' => 'next',
                        'value' => 'add',
                    ],
                ])
                ->add([
                    'name' => 'mode',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'id' => 'mode',
                        'value' => 'write',
                    ],
                ])
            ;
        } else {
            $this
                ->add([
                    'name' => 'template',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'id' => 'template',
                    ],
                ])
                ->add([
                    'name' => 'step',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'id' => 'step',
                        'value' => 'add',
                    ],
                ])
                ->add([
                    'name' => 'next',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'id' => 'next',
                        'value' => 'show',
                    ],
                ])
                ->add([
                    'name' => 'mode',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'id' => 'mode',
                        'value' => 'write',
                    ],
                ])
            ;
        }

        $this
            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'value' => 'Edit', // @translate
                ],
            ])
        ;
    }

    public function setOptions($options)
    {
        parent::setOptions($options);

        if (isset($options['templates'])) {
            $this->setTemplates($options['templates']);
        }
        if (isset($options['display_select_template'])) {
            $this->setDisplayTemplateSelect((bool) $options['display_select_template']);
        }

        return $this;
    }

    public function setDisplayTemplateSelect(bool $displayTemplateSelect): self
    {
        $this->displaySelectTemplate = $displayTemplateSelect;
        return $this;
    }

    public function getDisplayTemplateSelect(): bool
    {
        return $this->displaySelectTemplate;
    }

    public function setTemplates(array $templates): self
    {
        $this->templates = $templates;
        return $this;
    }

    public function getTemplates(): array
    {
        return $this->templates;
    }
}
