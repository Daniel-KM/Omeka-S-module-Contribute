<?php declare(strict_types=1);

namespace Contribute\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class TemplateContributeFieldset extends Fieldset
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'contribute_template_convert',
                'type' => CommonElement\OptionalResourceTemplateSelect::class,
                'options' => [
                    'label' => 'Template to use when the contribution is validated', // @translate
                    'info' => 'In complex workflow, the template for the user may be different from the template of the item, for example to create a form with different property labels, or a different order, or more restrictive rules of property validation, or different templates for different users. So this option changes the template when the contribution is validated.', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    // 'id' => 'contribute_template_convert',
                    'class' => 'setting chosen-select',
                    'multiple' => false,
                    'data-setting-key' => 'contribute_template_convert',
                    'data-placeholder' => 'Select template…', // @translate
                ],
            ])

            // TODO Move contributive_templates_media to Advanced Resource Template.
            ->add([
                'name' => 'contribute_templates_media',
                // Advanced Resource Template is a required dependency.
                'type' => CommonElement\OptionalResourceTemplateSelect::class,
                'options' => [
                    'label' => 'Media templates for contribution', // @translate
                    'info' => 'If any, the templates should be in the list of allowed templates for contribution of a media. Warning: to use multiple media is supported only with specific themes for now.', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    // 'id' => 'contribute_templates_media',
                    'class' => 'setting chosen-select',
                    'multiple' => true,
                    'data-setting-key' => 'contribute_templates_media',
                    'data-placeholder' => 'Select templates for media…', // @translate
                ],
            ])
            // Specific messages for the contributor.
            ->add([
                'name' => 'contribute_author_confirmation_subject',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Specific confirmation subject to the contributor', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_author_confirmation_subject',
                    'data-setting-key' => 'contribute_author_confirmation_subject',
                ],
            ])
            ->add([
                'name' => 'contribute_author_confirmation_body',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Specific confirmation message to the contributor', // @translate
                    'info' => 'Placeholders: wrap properties with "{}", for example "{dcterms:title}".', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_author_confirmation_body',
                    'rows' => 5,
                    'data-setting-key' => 'contribute_author_confirmation_body',
                ],
            ])
            // Specific messages for the reviewer.
            ->add([
                'name' => 'contribute_reviewer_confirmation_subject',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Specific confirmation subject to the reviewer', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_reviewer_confirmation_subject',
                    'data-setting-key' => 'contribute_reviewer_confirmation_subject',
                ],
            ])
            ->add([
                'name' => 'contribute_reviewer_confirmation_body',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Specific confirmation message to the reviewer', // @translate
                    'info' => 'Placeholders: wrap properties with "{}", for example "{dcterms:title}".', // @translate
                ],
                'attributes' => [
                    'id' => 'contribute_reviewer_confirmation_body',
                    'rows' => 5,
                    'data-setting-key' => 'contribute_reviewer_confirmation_body',
                ],
            ]);
        }
}
