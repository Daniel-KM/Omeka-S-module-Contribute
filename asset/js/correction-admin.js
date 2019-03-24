(function($, window, document) {
    // Browse batch actions.
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

$(document).ready(function() {

    // Mark a correction reviewed/unreviewed.
    $('#content').on('click', '.correction a.status-toggle', function(e) {
        e.preventDefault();

        var button = $(this);
        var url = button.data('status-toggle-url');
        var status = button.data('status');
        $.ajax({
            url: url,
            beforeSend: function() {
                button.removeClass('o-icon-' + status).addClass('o-icon-transmit');
            }
        })
        .done(function(data) {
            if (!data.content) {
                alert(Omeka.jsTranslate('Something went wrong'));
            } else {
                status = data.content.status;
                button.data('status', status);
                var row = button.closest('tr')
                row.find('.status-label').text(data.content.statusLabel);
            }
        })
        .fail(function(jqXHR, textStatus) {
            if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                alert(jqXHR.responseJSON.message);
            } else {
                alert(Omeka.jsTranslate('Something went wrong'));
            }
        })
        .always(function () {
            button.removeClass('o-icon-transmit').addClass('o-icon-' + status);
        });
    });

});
