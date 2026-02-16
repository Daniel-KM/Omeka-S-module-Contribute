'use strict';

/**
 * Manage the visibility of contribution options in resource template form.
 *
 * Show/hide contribution options based on the value of contribute_template_contributable.
 * - "": No contribution, hide all options
 * - "global": Use global settings, hide template-specific options
 * - "specific": Use template-specific options, show all options
 */

(function ($) {
    $(document).ready(function () {

        const contributableSelector = '[data-setting-key="contribute_template_contributable"]';
        const contributableRadios = $(contributableSelector);

        if (!contributableRadios.length) {
            return;
        }

        // List of setting keys that should only be shown when "specific" is selected.
        const specificOnlySettings = [
            'contribute_modes',
            'contribute_filter_user_roles',
            'contribute_filter_user_emails',
            'contribute_filter_user_settings',
            'contribute_token_duration',
            'contribute_allow_edit_until',
            'contribute_sender_email',
            'contribute_sender_name',
            'contribute_notify_recipients',
            'contribute_author_emails',
            'contribute_author_confirmations',
            'contribute_message_add',
            'contribute_message_edit',
            'contribute_message_author_confirmation_subject',
            'contribute_message_author_confirmation_body',
            'contribute_message_reviewer_confirmation_subject',
            'contribute_message_reviewer_confirmation_body',
        ];

        // List of setting keys that should be shown for both "global" and "specific".
        const templateSettings = [
            'contribute_template_convert',
            'contribute_templates_media',
        ];

        /**
         * Toggle visibility of contribution options based on selected value.
         */
        function toggleContributeOptions() {
            const selectedValue = $(contributableSelector + ':checked').val();

            // Get the fieldset container (the contribute fieldset).
            const fieldset = contributableRadios.closest('fieldset');

            // Hide/show template settings (shown for global and specific).
            templateSettings.forEach(function (settingKey) {
                const element = fieldset.find('[data-setting-key="' + settingKey + '"]');
                const field = element.closest('.field');
                if (selectedValue === '' || selectedValue === undefined) {
                    field.hide();
                } else {
                    field.show();
                }
            });

            // Hide/show specific-only settings.
            specificOnlySettings.forEach(function (settingKey) {
                const element = fieldset.find('[data-setting-key="' + settingKey + '"]');
                const field = element.closest('.field');
                if (selectedValue === 'specific') {
                    field.show();
                } else {
                    field.hide();
                }
            });
        }

        // Initial state on page load.
        toggleContributeOptions();

        // Listen for changes on the radio buttons.
        contributableRadios.on('change', toggleContributeOptions);

    });
})(jQuery);
