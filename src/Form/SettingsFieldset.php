<?php declare(strict_types=1);

namespace Contribute\Form;

use Common\Form\Element as CommonElement;
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
                'name' => 'contribute_modes',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Contribution modes', // @translate
                    'value_options' => [
                        'open' => 'Open to any visitor', // @translate
                        'token' => 'With token', // @translate
                        'user' => 'Authenticated users', // @translate
                        'user_token' => 'Authenticated users with a token', // @translate
                        'auth_cas' => 'Authenticated users from cas', // @translate
                        'auth_ldap' => 'Authenticated users from ldap', // @translate
                        'auth_sso' => 'Authenticated users from sso', // @translate
                        'user_role' => 'Roles', // @translate
                        'user_email' => 'Authenticated users with defined emails below', // @translate
                        'user_settings' => 'Users filtered on their settings, in particular IdP attributes mapped via Single Sign-On', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'contribute_modes',
                ],
            ])

            ->add([
                // This element is a select built with a factory, not a class.
                // Anyway, it cannot be used simply, because it requires a value.
                // 'type' => 'Omeka\Form\Element\RoleSelect',
                'type' => CommonElement\OptionalRoleSelect::class,
                'name' => 'contribute_filter_user_roles',
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Roles allowed to contribute (option "Roles" above)', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'contribute_filter_user_roles',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select roles…', // @translate
                ],
            ])

            ->add([
                'name' => 'contribute_filter_user_emails',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Filter on emails of users allowed to contribute (option above)', // @translate
                    'info' => 'The filters may be a raw email or a regex wrapped with "~". They are independant: a single rule should be true to allow contribution.', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_filter_user_emails',
                    'placeholder' => <<<'TXT'
                        alpha@beta.com
                        ~^(\w+\.gamma@delta\.com)$~
                        TXT,
                    'rows' => '3',
                ],
            ])

            ->add([
                'name' => 'contribute_filter_user_settings',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Filters on settings of users allowed to contribute (option above)', // @translate
                    'info' => 'The filters may be a raw string or a regex wrapped with "~". They are cumulative: all filters should be true.', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'contribute_filter_user_settings',
                    'placeholder' => <<<'TXT'
                        connection_idp = https://idp.example.org/idp/shibboleth
                        singlesignon_person_affiliation = student
                        singlesignon_entite_affectation = ~^(RAN|PUR|MMP|DEN|PHA)$~
                        TXT,
                    'rows' => '3',
                ],
            ])

            ->add([
                'name' => 'contribute_templates',
                'type' => CommonElement\OptionalResourceTemplateSelect::class,
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
                'type' => CommonElement\OptionalResourceTemplateSelect::class,
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
                'name' => 'contribute_sender_email',
                'type' => CommonElement\OptionalEmail::class,
                'options' => [
                    'element_group' => 'contact',
                    'label' => 'Email of the sender (else no-reply user or administrator)', // @translate
                    'info' => 'The no-reply email can be set via module EasyAdmin. The administrator email can be set above.', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_sender_email',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'contribute_sender_name',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'contact',
                    'label' => 'Name of the sender when email above is set', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_sender_name',
                    'required' => false,
                ],
            ])

            ->add([
                'name' => 'contribute_notify_recipients',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Emails to notify contributions', // @translate
                    'info' => 'A query can be appended to limit notifications to specific contributions.', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'contribute_notify_recipients',
                    'required' => false,
                    'placeholder' => <<<'TXT'
                        contact@example.org
                        info@example2.org = resource_template_id[]=2&property[0][property]=dcterms:provenance&property[0][type]=eq&property[0][text]=ut2j
                        TXT,
                ],
            ])

            ->add([
                'name' => 'contribute_author_emails',
                'type' => CommonElement\OptionalPropertySelect::class,
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
                'type' => CommonElement\OptionalMultiCheckbox::class,
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
                'name' => 'contribute_redirect_submit',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Redirection after submission', // @translate
                    'info' => 'Default is the page of contributions for guest users. Set "home" for home page (admin or public), "site" for the current site home, "top" for main public page, "me" for guest account, or any path starting with "/", including "/" itself for main home page.', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_redirect_submit',
                ],
            ])

            ->add([
                'name' => 'contribute_author_confirmation_subject',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Subject of the email of confirmation to the author', // @translate
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
                    'label' => 'Message of confirmation to the author', // @translate
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
                    'label' => 'Subject of the email of confirmation to the reviewers', // @translate
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
                    'label' => 'Message of confirmation to the reviewers', // @translate
                    'info' => 'Placeholders: wrap properties with "{}", for example "{dcterms:title}".', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_author_confirmation_body',
                    'rows' => 5,
                ],
            ])

            ->add([
                'name' => 'contribute_author_message_subject',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Subject of the default email to the author', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_author_message_subject',
                ],
            ])
            ->add([
                'name' => 'contribute_author_message_body',
                'type' => Element\Textarea::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Default message to the author', // @translate
                    'info' => 'Placeholders: {main_title}, {main_url}.', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_author_message_body',
                    'rows' => 5,
                ],
            ])

        ;
    }
}
