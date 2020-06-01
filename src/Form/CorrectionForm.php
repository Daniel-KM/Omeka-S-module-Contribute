<?php
namespace Contribute\Form;

use Zend\Form\Element;
use Zend\Form\Form;

class ContributeForm extends Form
{
    public function init()
    {
        $this
            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'value' => 'Correct', // @translate
                ],
            ])
        ;
    }
}
