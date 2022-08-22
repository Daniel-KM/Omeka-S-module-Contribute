<?php declare(strict_types=1);

namespace Contribute\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\View\Helper\Url;
use Omeka\Form\Element as OmekaElement;

class QuickSearchForm extends Form
{
    /**
     * @var array
     */
    protected $contributeTemplates = [];

    /**
     * @var Url
     */
    protected $urlHelper;

    public function init(): void
    {
        $this->setAttribute('method', 'get');

        // No csrf: see main search form.
        $this->remove('csrf');

        // $urlHelper = $this->getUrlHelper();

        $this
            ->add([
                'name' => 'resource_template_id',
                'type' => OmekaElement\ResourceTemplateSelect::class,
                'options' => [
                    'label' => 'Template', // @translate
                    'disable_group_by_owner' => true,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'resource_template_id',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a template…', // @translate
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

            ->add([
                'name' => 'title',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Title', // @translate
                ],
                'attributes' => [
                    'id' => 'title',
                    'placeholder' => 'Set a title…', // @translate
                ],
            ])

            ->add([
                'name' => 'patch',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Type of contribution', // @translate
                    'value_options' => [
                        '' => 'Any', // @translate
                        '1' => 'Correction', // @translate
                        '00' => 'Full contribution', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'submitted',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'submitted',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Submitted', // @translate
                    'value_options' => [
                        '' => 'Any', // @translate
                        '1' => 'Yes', // @translate
                        '00' => 'No', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'submitted',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'reviewed',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Reviewed', // @translate
                    'value_options' => [
                        '' => 'Any', // @translate
                        '1' => 'Yes', // @translate
                        '00' => 'No', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'reviewed',
                    'value' => '',
                ],
            ])

            /*
            ->add([
                'name' => 'owner_id',
                'type' => OmekaElement\ResourceSelect::class,
                'options' => [
                    'label' => 'Owner', // @translate
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
                    'data-placeholder' => 'Select a user…', // @translate
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
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'id' => 'submit',
                    'value' => 'Search', // @translate
                    'type' => 'submit',
                ],
            ]);
    }

    public function setContributeTemplates(array $contributeTemplates): self
    {
        $this->contributeTemplates = $contributeTemplates;
        return $this;
    }

    public function setUrlHelper(Url $urlHelper): self
    {
        $this->urlHelper = $urlHelper;
        return $this;
    }
}
