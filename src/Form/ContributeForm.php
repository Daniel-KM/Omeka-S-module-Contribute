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

    public function init(): void
    {
        // Steps are "template", "add", "edit", "show".
        // Next can be "add", "edit", "show" + an optional query for complex
        // workflow. It should be set inside theme.
        // The "next" post argument can be bypassed by a query argument in order
        // to manage multiple ways.

        // When the template is not set, it is the default one in backend.
        if (count($this->templates)) {
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

        return $this;
    }

    public function setTemplates(array $templates): self
    {
        $this->templates = $templates;
        return $this;
    }
}
