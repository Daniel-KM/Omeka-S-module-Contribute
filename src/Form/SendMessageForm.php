<?php declare(strict_types=1);

namespace Contribute\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;

class SendMessageForm extends Form
{
    protected $subject = '';
    protected $body = '';

    public function init(): void
    {
        $this
            ->setAttribute('id', 'send-message-form')
            ->setAttribute('class', 'send-message-form')
            ->setAttribute('method', 'post')
            ->setName('send-message');

        $this
            ->add([
                'name' => 'subject',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Subject', // @translate
                ],
                'attributes' => [
                    'id' => 'subject',
                    'required' => false,
                    'value' => $this->subject,
                ],
            ])
            ->add([
                'name' => 'body',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Message', // @translate
                    'label_attributes' => [
                        'class' => 'required',
                    ],
                ],
                'attributes' => [
                    'id' => 'body',
                    'rows' => 10,
                    'required' => true,
                    'value' => $this->body,
                ],
            ])

            ->add([
                'name' => 'reject',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Mark unsubmitted', // @translate
                ],
                'attributes' => [
                    'id' => 'reject',
                    // 'value' => '1',
                ],
            ])

            ->add([
                'name' => 'myself',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Add myself as', // @translate
                    'value_options' => [
                        'cc' => 'cc', // @translate
                        'bcc' => 'bcc', // @translate
                        'reply' => 'Reply to', // @translate'
                    ],
                ],
                'attributes' => [
                    'id' => 'myself',
                ],
            ])
            ->add([
                'name' => 'cc',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Add specific emails as cc', // @translate
                    'info' => 'Use "=" to separate multiple emails.', // @translate
                    'value_separator' => '=',
                ],
                'attributes' => [
                    'id' => 'cc',
                ],
            ])
            ->add([
                'name' => 'bcc',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Add specific emails as bcc', // @translate
                    'value_separator' => '=',
                ],
                'attributes' => [
                    'id' => 'bcc',
                ],
            ])
            ->add([
                'name' => 'reply',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Add specific emails as reply-to', // @translate
                    'value_separator' => '=',
                ],
                'attributes' => [
                    'id' => 'reply',
                ],
            ])

            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'id' => 'submit',
                    'class' => 'submit',
                    'value' => 'Send message', // @translate
                ],
            ])
        ;
    }
}
