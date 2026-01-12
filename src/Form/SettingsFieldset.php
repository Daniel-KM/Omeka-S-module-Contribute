<?php declare(strict_types=1);

namespace Contribute\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    use TraitSettingsFieldset;

    protected $label = 'Contribute'; // @translate

    protected $elementGroups = [
        'contribution' => 'Contribution', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'contribute')
            ->setOption('element_groups', $this->elementGroups)

            ->appendGlobalAndTemplateCommonSettings()

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

            ->appendMessagesSettings()

            ->appendMailSettings()
        ;
    }

    protected function appendMailSettings()
    {
        $this
            ->add([
                'name' => 'contribute_message_author_mail_subject',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Subject of the default email to the author', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_message_author_mail_subject',
                ],
            ])
            ->add([
                'name' => 'contribute_message_author_mail_body',
                'type' => Element\Textarea::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Default message to the author', // @translate
                    'info' => 'Placeholders: {main_title}, {main_url}.', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_message_author_mail_body',
                    'rows' => 5,
                ],
            ])

            ->add([
                'name' => 'contribute_send_message_recipient_myself',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Default options to send a message: myself as recipient', // @translate
                    'value_options' => [
                        'cc' => 'cc', // @translate
                        'bcc' => 'bcc', // @translate
                        'reply' => 'Reply to', // @translate'
                    ],
                ],
                'attributes' => [
                    'id' => 'contribute_send_message_recipient_myself',
                ],
            ])
            ->add([
                'name' => 'contribute_send_message_recipients_cc',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Default options to send a message: cc emails', // @translate
                    'info' => 'Use "=" to separate multiple emails.', // @translate
                    'value_separator' => '=',
                ],
                'attributes' => [
                    'id' => 'contribute_send_message_recipients_cc',
                ],
            ])
            ->add([
                'name' => 'contribute_send_message_recipients_bcc',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Default options to send a message: bcc emails', // @translate
                    'value_separator' => '=',
                ],
                'attributes' => [
                    'id' => 'contribute_send_message_recipients_bcc',
                ],
            ])
            ->add([
                'name' => 'contribute_send_message_recipients_reply',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Default options to send a message: reply-to emails', // @translate
                    'value_separator' => '=',
                ],
                'attributes' => [
                    'id' => 'contribute_send_message_recipients_reply',
                ],
            ])
        ;
        return $this;

    }
}
