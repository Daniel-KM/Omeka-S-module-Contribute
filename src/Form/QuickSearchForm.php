<?php declare(strict_types=1);

namespace Contribute\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\View\Helper\Url;

class QuickSearchForm extends Form
{
    /**
     * @var array
     */
    protected $resourceTemplates = [];

    /**
     * @var bool
     */
    protected $hasBothPatchTypes = true;

    /**
     * @var Url
     */
    protected $urlHelper;

    public function init(): void
    {
        $this->setAttribute('method', 'get');
        $this->setAttribute('id', 'quick-search-form');

        // No csrf: see main search form.
        $this->remove('csrf');

        // $urlHelper = $this->getUrlHelper();

        $this
            ->add([
                'name' => 'resource_template_id',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Template', // @translate
                    'empty_option' => '',
                    'value_options' => $this->resourceTemplates,
                ],
                'attributes' => [
                    'id' => 'resource_template_id',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a template…', // @translate
                ],
            ])

            ->add([
                'name' => 'fulltext_search',
                'type' => Element\Search::class,
                'options' => [
                    'label' => 'Text', // @translate
                ],
                'attributes' => [
                    'id' => 'fulltext_search',
                ],
            ])

            ->add([
                'name' => 'created',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Date', // @translate
                ],
                'attributes' => [
                    'id' => 'created',
                    'placeholder' => 'Set a date with optional comparator…', // @translate
                ],
            ])

        ;

        if ($this->hasBothPatchTypes) {
            $this->add([
                'name' => 'patch',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Type of contribution', // @translate
                    'label_attributes' => [
                        'style' => 'display: inline; margin-right: 1em;',
                    ],
                    'value_options' => [
                        '' => 'All', // @translate
                        '0' => 'Full contribution', // @translate
                        '1' => 'Correction', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'patch',
                    'value' => '',
                ],
            ]);
        }

        $this->add([
                'name' => 'submitted',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Submitted', // @translate
                    'label_attributes' => [
                        'style' => 'display: inline; margin-right: 1em;',
                    ],
                    'value_options' => [
                        '' => 'All', // @translate
                        '0' => 'No', // @translate
                        '1' => 'Yes', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'submitted',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'undertaken',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Undertaken', // @translate
                    'label_attributes' => [
                        'style' => 'display: inline; margin-right: 1em;',
                    ],
                    'value_options' => [
                        '' => 'All', // @translate
                        '0' => 'No', // @translate
                        '1' => 'Yes', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'undertaken',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'validated',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Validated', // @translate
                    'label_attributes' => [
                        'style' => 'display: inline; margin-right: 1em;',
                    ],
                    'value_options' => [
                        '' => 'All', // @translate
                        '0' => 'No', // @translate
                        '1' => 'Yes', // @translate
                        'null' => 'Undetermined', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'validated',
                    'value' => '',
                ],
            ])

            /*
            ->add([
                'name' => 'owner_id',
                'type' => OmekaElement\ResourceSelect::class,
                'options' => [
                    'label' => 'Owner', // @ translate
                    'resource_value_options' => [
                        'resource' => 'users',
                        'query' => [],
                        'option_text_callback' => function ($user) {
                            return $user->name();
                        },
                    ],
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'owner_id',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a user…', // @ translate
                    'data-api-base-url' => $urlHelper('api/default', ['resource' => 'users']),
                ],
            ])
            */
            // TODO Fix issue when the number of users is too big to allow to keep the selector.
            ->add([
                'name' => 'owner_id',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'User by id', // @translate
                ],
                'attributes' => [
                    'id' => 'owner_id',
                ],
            ])
            ->add([
                'name' => 'email',
                'type' => Element\Email::class,
                'options' => [
                    'label' => 'User by email', // @translate
                ],
                'attributes' => [
                    'id' => 'email',
                ],
            ])

            ->add([
                'name' => 'missing_files',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Missing files', // @translate
                ],
                'attributes' => [
                    'id' => 'missing_files',
                ],
            ])

            ->add([
                'name' => 'submit',
                'type' => Element\Button::class,
                'options' => [
                    'label' => 'Search', // @translate
                ],
                'attributes' => [
                    'id' => 'submit',
                    'type' => 'submit',
                    'class' => 'button',
                ],
            ]);

        $this->getInputFilter()
            ->add([
                'name' => 'resource_template_id',
                'required' => false,
            ]);
    }

    public function setResourceTemplates(array $resourceTemplates): self
    {
        $this->resourceTemplates = $resourceTemplates;
        return $this;
    }

    public function setUrlHelper(Url $urlHelper): self
    {
        $this->urlHelper = $urlHelper;
        return $this;
    }

    public function setHasBothPatchTypes(bool $hasBothPatchTypes): self
    {
        $this->hasBothPatchTypes = $hasBothPatchTypes;
        return $this;
    }
}
