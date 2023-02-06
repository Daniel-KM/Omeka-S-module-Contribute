<?php declare(strict_types=1);

namespace Contribute\Form;

use AdvancedResourceTemplate\Form\Element as AdvancedResourceTemplateElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Contribute'; // @translate

    protected $elementGroups = [
        'contribution' => 'Contribution', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'contribute')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'contribute_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Contribution mode', // @translate
                    'value_options' => [
                        'user_token' => 'Authenticated users with token', // @translate
                        'user' => 'Authenticated users', // @translate
                        'role' => 'Roles', // @translate
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
                // This element is a select built with a factory, not a class.
                // Anyway, it cannot be used simply, because it requires a value.
                // 'type' => 'Omeka\Form\Element\RoleSelect',
                'type' => AdvancedResourceTemplateElement\OptionalRoleSelect::class,
                'name' => 'contribute_roles',
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Roles allowed to contribute (option "Roles" above)', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'contribute_roles',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select roles…', // @translate
                ],
            ])

            ->add([
                'name' => 'contribute_templates',
                'type' => AdvancedResourceTemplateElement\OptionalResourceTemplateSelect::class,
                'options' => [
                    'element_group' => 'contribution',
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
                    'element_group' => 'contribution',
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
                    'element_group' => 'contribution',
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

            ->add([
                'name' => 'contribute_allow_update',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Allow to edit a contribution', // @transale
                    'value_options' => [
                        'no' => 'No (directly submitted)', // @translate
                        'submission' => 'Until submission', // @translate
                        'validation' => 'Until validation', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'contribute_allow_update',
                    'value' => 'submit',
                ],
            ])

            ->add([
                'name' => 'contribute_notify_recipients',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Emails to notify contributions', // @translate
                    'info' => 'A query can be appended to limit notifications to specific contributions.', // @translate
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
                    'element_group' => 'contribution',
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
                    'element_group' => 'contribution',
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
                'name' => 'contribute_message_add',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Message displayed when a contribution is added', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_message_add',
                ],
            ])

            ->add([
                'name' => 'contribute_message_edit',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Message displayed when a contribution is edited', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_message_edit',
                ],
            ])

            ->add([
                'name' => 'contribute_author_confirmation_subject',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Subject of the confirmation email to the author', // @translate
                    'info' => 'May be overridden by a specific subject set in the resource template', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_author_confirmation_subject',
                ],
            ])
            ->add([
                'name' => 'contribute_author_confirmation_body',
                'type' => Element\Textarea::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Confirmation message to the author', // @translate
                    'info' => 'Placeholders: wrap properties with "{}", for example "{dcterms:title}".', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_author_confirmation_body',
                    'rows' => 5,
                ],
            ])

            ->add([
                'name' => 'contribute_reviewer_confirmation_subject',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Subject of the confirmation email to the reviewers', // @translate
                    'info' => 'May be overridden by a specific subject set in the resource template', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_reviewer_confirmation_subject',
                ],
            ])
            ->add([
                'name' => 'contribute_reviewer_confirmation_body',
                'type' => Element\Textarea::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Confirmation message to the reviewers', // @translate
                    'info' => 'Placeholders: wrap properties with "{}", for example "{dcterms:title}".', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_author_confirmation_body',
                    'rows' => 5,
                ],
            ])
        ;
    }
}
