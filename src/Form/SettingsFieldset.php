<?php
namespace Correction\Form;

use Omeka\Form\Element\PropertySelect;
use Zend\Form\Element;
use Zend\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Correction'; // @translate

    public function init()
    {
        $this
            ->add([
                'name' => 'correction_properties_corrigible',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Corrigible properties', // @translate
                    'info' => 'Only the selected properties will be proposed for public correction. It’s not recommended to allow to correct identifiers.', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'correction_properties_corrigible',
                    'multiple' => true,
                    // Should be true and without filter, but simpler for user.
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ])

            ->add([
                'name' => 'correction_properties_fillable',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Fillable properties', // @translate
                    'info' => 'Allow user to append new values for the selected properties.', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'correction_properties_fillable',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                ],
            ])

            ->add([
                'name' => 'correction_token_duration',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Days for token to expire', // @translate
                    'info' => 'Allow to set the default expiration date of a token. Let empty to remove expiration.', // @translate
                ],
                'attributes' => [
                    'id' => 'correction_token_duration',
                    'min'  => '0',
                    'step' => '1',
                    'data-placeholder' => '90', // @translate
                ],
            ])
        ;
    }
}
