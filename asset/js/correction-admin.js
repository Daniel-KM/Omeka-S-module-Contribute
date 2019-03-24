(function($, window, document) {
    $(function() {

        var batchSelect = $('#batch-form .batch-actions-select');
        batchSelect.append(
            $('<option class="batch-selected" disabled></option>').val('correction-selected').html(Omeka.jsTranslate('Prepare tokens to correct selected'))
        );
        batchSelect.append(
            $('<option></option>').val('correction-all').html(Omeka.jsTranslate('Prepare tokens to correct all'))
        );
        var batchActions = $('#batch-form .batch-actions');
        batchActions.append(
            $('<input type="submit" class="correction-selected" formaction="correction/create-token">').val(Omeka.jsTranslate('Go'))
        );
        batchActions.append(
            $('<input type="submit" class="correction-all" formaction="correction/create-token">').val(Omeka.jsTranslate('Go'))
        );
        var resourceType = window.location.pathname.split("/").pop();
        batchActions.append(
            $('<input type="hidden" name="resource_type">').val(resourceType)
        );

    });
}(window.jQuery, window, document));
