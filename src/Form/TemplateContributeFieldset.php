<?php declare(strict_types=1);

namespace Contribute\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Fieldset;

class TemplateContributeFieldset extends Fieldset
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

            ->add([
                'name' => 'contribute_template_contributable',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Can be used for contribution', // @translate
                    'value_options' => [
                        '' => 'No', // @translate
                        'global' => 'With options defined in main settings', // @translate
                        'specific' => 'With options defined below', // @translate
                    ],
                ],
                'attributes' => [
                    // 'id' => 'contribute_template_contributable',
                    'data-setting-key' => 'contribute_template_contributable',
                ],
            ])

            ->add([
                'name' => 'contribute_template_convert',
                'type' => CommonElement\OptionalResourceTemplateSelect::class,
                'options' => [
                    'element_group' => 'contribution',
                    'label' => 'Convert template when the contribution is validated', // @translate
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
                    'element_group' => 'contribution',
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

            ->appendGlobalAndTemplateCommonSettings()

            ->appendMessagesSettings()
        ;
    }
}
