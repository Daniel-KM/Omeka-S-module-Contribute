<?php declare(strict_types=1);

namespace Contribute\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Contribute'; // @translate

    public function init(): void
    {
        $this
            ->add([
                'name' => 'contribute_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Contribution mode',
                    'value_options' => [
                        'user_token' => 'Authenticated users with token', // @translate
                        'user' => 'Authenticated users', // @translate
                        'token' => 'With token', // @translate
                        'open' => 'Open to any visitor', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'contribute_mode',
                    'value' => 'user_token',
                ],
            ])

            ->add([
                'name' => 'contribute_notify',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Emails to notify contributions', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_notify',
                    'required' => false,
                    'placeholder' => 'contact@example.org
info@example2.org',
                ],
            ])

            ->add([
                'name' => 'contribute_templates',
                'type' => OmekaElement\ResourceTemplateSelect::class,
                'options' => [
                    'label' => 'Resource templates allowed for contribution', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'contribute_templates',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select resources templates…', // @translate
                ],
            ])

            ->add([
                'name' => 'contribute_templates_media',
                'type' => OmekaElement\ResourceTemplateSelect::class,
                'options' => [
                    'label' => 'Resource templates allowed for media (linked contribution)', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'contribute_templates_media',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select resources templates…', // @translate
                ],
            ])

            ->add([
                'name' => 'contribute_token_duration',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Days for token to expire', // @translate
                    'info' => 'Allow to set the default expiration date of a token. Let empty to remove expiration.', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_token_duration',
                    'min' => '0',
                    'step' => '1',
                    'data-placeholder' => '90', // @translate
                ],
            ])
        ;
    }
}
