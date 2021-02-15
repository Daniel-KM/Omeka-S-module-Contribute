<?php declare(strict_types=1);

namespace Contribute\Form;

use Contribute\Form\Element\ArrayQueryTextarea;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\ArrayTextarea;
use Omeka\Form\Element\PropertySelect;
use Omeka\Form\Element\ResourceTemplateSelect;

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
                'type' => ArrayTextarea::class,
                'options' => [
                    'label' => 'Default emails to notify contributions', // @translate
                    'info' => 'The list of emails to notify when a user edits a resource, one by row.', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_notify',
                    'required' => false,
                    'placeholder' => 'contact@example.org
info@example2.org',
                ],
            ])

            ->add([
                'name' => 'contribute_template_default',
                'type' => ResourceTemplateSelect::class,
                'options' => [
                    'label' => 'Template to use for default edit form', // @translate
                    'info' => 'This template is used only when the current resource has no template or a template without config. If not set, the properties below will be used.', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'contribute_template_default',
                    'multiple' => false,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a resource template…', // @translate
                ],
            ])

            ->add([
                'name' => 'contribute_properties_editable_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Default contribute mode', // @translate
                    'info' => 'This option is used only when the template is not configured.', // @translate
                    'value_options' => [
                        'all' => 'Allow to edit all values', // @translate
                        'whitelist' => 'Only specified properties (whitelist)', // @translate
                        'blacklist' => 'All values except properties (blacklist)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'contribute_properties_editable_mode',
                ],
            ])
            ->add([
                'name' => 'contribute_properties_editable',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Properties to edit or not when no template is available', // @translate
                    'info' => 'Only the selected properties will be proposed for public contribute. This list is used only when the resource template is not configured.', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'contribute_properties_editable',
                    'multiple' => true,
                    // Should be true and without filter, but simpler for user.
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ])

            ->add([
                'name' => 'contribute_properties_fillable_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Default fillable mode', // @translate
                    'info' => 'This option is used only when the template is not configured.', // @translate
                    'value_options' => [
                        'all' => 'Allow to fill any value', // @translate
                        'whitelist' => 'Only specified properties (whitelist)', // @translate
                        'blacklist' => 'Any values except properties (blacklist)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'contribute_properties_fillable_mode',
                ],
            ])
            ->add([
                'name' => 'contribute_properties_fillable',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Properties to fill or not when no template is available', // @translate
                    'info' => 'Allow user to append new values for the selected properties. This list is used only when the resource template is not configured.', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'contribute_properties_fillable',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ])

            ->add([
                'name' => 'contribute_properties_datatype',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Editable datatypes', // @translate
                    'info' => 'Other datatypes are not editable.', // @translate
                    'value_options' => [
                        'literal' => 'Literal', // @translate
                        'resource' => 'Resource', // @translate
                        // 'resource:item' => 'Item', // @translate
                        // 'resource:media' => 'Media', // @translate
                        // 'resource:itemset' => 'Item set', // @translate
                        'uri' => 'Uri', // @translate
                        'valuesuggest' => 'Value suggest', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'contribute_properties_datatype',
                ],
            ])

            ->add([
                'name' => 'contribute_property_queries',
                'type' => ArrayQueryTextarea::class,
                'options' => [
                    'label' => 'Queries for value resources', // @translate
                    'info' => 'Allows to limit options in a resource select.', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'contribute_properties_queries',
                    'rows' => 5,
                    'placeholder' => 'dcterms:subject = item_set_id[]=12
dcterms:creator = resource_class_id[]=94',
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
