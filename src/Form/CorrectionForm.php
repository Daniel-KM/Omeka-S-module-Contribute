<?php
namespace Correction\Form;

use Zend\Form\Element;
use Zend\Form\Form;

class CorrectionForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'attributes' => [
                'value' => 'Correct', // @translate
            ],
        ]);
    }
}
