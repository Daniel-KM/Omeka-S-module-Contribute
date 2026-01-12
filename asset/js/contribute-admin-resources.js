'use strict';

// TODO Remove dead code.

// Kept as long as pull request #1260 is not passed.
Omeka.contributionManageSelectedActions = function() {
    const selectedOptions = $('[value="update-selected"], [value="delete-selected"], #batch-form .batch-inputs .batch-selected');
    if ($('.batch-edit td input[type="checkbox"]:checked').length > 0) {
        selectedOptions.removeAttr('disabled');
    } else {
        selectedOptions.prop('disabled', true);
        $('.batch-actions-select').val('default');
        $('.batch-actions .active').removeClass('active');
        $('.batch-actions .default').addClass('active');
    }
};

(function($, window, document) {

    $(function() {

        // Browse batch actions.

        const batchSelect = $('#batch-form .batch-actions-select');
        batchSelect.attr('name', 'batch_action');

        batchSelect.append(
            $('<option class="batch-selected" disabled></option>').val('contribution-selected').html(Omeka.jsTranslate('Prepare tokens to edit selected'))
        );
        batchSelect.append(
            $('<option></option>').val('contribution-all').html(Omeka.jsTranslate('Prepare tokens to edit all'))
        );

        const batchActions = $('#batch-form .batch-actions');
        batchActions.append(
            $('<input type="submit" class="contribution-selected" formaction="contribution/create-token">').val(Omeka.jsTranslate('Go'))
        );
        batchActions.append(
            $('<input type="submit" class="contribution-all" formaction="contribution/create-token">').val(Omeka.jsTranslate('Go'))
        );

        const resourceType = window.location.pathname.split("/").pop();
        batchActions.append(
            $('<input type="hidden" name="resource_type">').val(resourceType)
        );

        // Kept as long as pull request #1260 is not passed.
        $('.select-all').change(function() {
            Omeka.contributionManageSelectedActions();
        });
        $('.batch-edit td input[type="checkbox"]').change(function() {
            Omeka.contributionManageSelectedActions();
        });

    });

}(window.jQuery, window, document));
