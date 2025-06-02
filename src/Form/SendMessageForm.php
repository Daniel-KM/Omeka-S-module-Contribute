<?php declare(strict_types=1);

namespace Contribute\Form;

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
                'name' => 'bcc',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Add myself as bcc', // @translate
                ],
                'attributes' => [
                    'id' => 'bcc',
                    'value' => '1',
                ],
            ])

            ->add([
                'name' => 'reply_to',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Add myself as reply-to', // @translate
                ],
                'attributes' => [
                    'id' => 'reply_to',
                    'value' => '1',
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

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        $this->get('subject')->setAttribute('value', $subject);
        return $this;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        $this->get('body')->setAttribute('value', $body);
        return $this;
    }
}
