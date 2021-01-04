<?php declare(strict_types=1);
namespace Contribute\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ContributeForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'value' => 'Edit', // @translate
                ],
            ])
        ;
    }
}
