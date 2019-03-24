<?php
namespace Correction\Form;

use Omeka\Form\Element\PropertySelect;
use Zend\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Correction'; // @translate

    public function init()
    {
        $this->add([
            'name' => 'correction_properties',
            'type' => PropertySelect::class,
            'options' => [
                'label' => 'Corrigible properties', // @translate
                'info' => 'Only the selected properties will be proposed for public correction. Let empty to select all. It’s not recommended to allow to correct identifiers.', // @translate
                'empty_option' => '', // @translate
                'term_as_value' => true,
            ],
            'attributes' => [
                'id' => 'correction_properties',
                'multiple' => true,
                'required' => false,
                'class' => 'chosen-select',
                'data-placeholder' => 'Select properties…', // @translate
            ],
        ]);
    }
}
