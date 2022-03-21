<?php declare(strict_types=1);

namespace Contribute\Form;

use AdvancedResourceTemplate\Form\Element as AdvancedResourceTemplateElement;
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
                'name' => 'contribute_notify_recipients',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Emails to notify contributions', // @translate
                    'info' => 'A query can be appended to limit notifications to specific contributions.'
                ],
                'attributes' => [
                    'id' => 'contribute_notify_recipients',
                    'required' => false,
                    'placeholder' => 'contact@example.org
info@example2.org resource_template_id[]=2&property[0][property]=dcterms:provenance&property[0][type]=eq&property[0][text]=ut2j
',
                ],
            ])

            ->add([
                'name' => 'contribute_author_emails',
                'type' => AdvancedResourceTemplateElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Emails of the author', // @translate
                    'empty_option' => '',
                    'prepend_value_options' => [
                        'owner' => 'Contributor', // @translate
                    ],
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'contribute_author_emails',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a property…', // @translate
                ],
            ])

            ->add([
                'name' => 'contribute_author_confirmations',
                'type' => AdvancedResourceTemplateElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Confirmations to author', // @translate
                    'value_options' => [
                        // 'prepare' => 'On prepare', // @translate
                        // 'update' => 'On update', // @translate
                        'submit' => 'On submit', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'contribute_author_confirmations',
                    'required' => false,
                ],
            ])

            ->add([
                'name' => 'contribute_templates',
                'type' => AdvancedResourceTemplateElement\OptionalResourceTemplateSelect::class,
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
                'type' => AdvancedResourceTemplateElement\OptionalResourceTemplateSelect::class,
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
