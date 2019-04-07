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

    // Manage the modal to create the token.
    // Get the modal.
    var modal = document.getElementById('create_correction_token_dialog');
    // Get the button that opens the modal.
    var btn = document.getElementById("create_correction_token_dialog_go");
    // Get the <span> element that closes the modal.
    var span = document.getElementById("create_correction_token_dialog_close");

    // When the user clicks the button, open the modal.
    btn.onclick = function() {
        var href = $("#create_correction_token a").attr("href");
        href =href +  "&email=" + $("#create_correction_token_dialog_email").val();
        location.href = href;
        modal.style.display = "none";
    }

    // When the user clicks on <span> (x), close the modal.
    span.onclick = function() {
        modal.style.display = "none";
    }

    // When the user clicks anywhere outside of the modal, close it.
    // window.onclick = function(event) {
    //     if (event.target == modal) {
    //         modal.style.display = "none";
    //     }
    // }

    $("#create_correction_token").on("click",function(ev){
        modal.style.display = "block";
        ev.preventDefault();
    })

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
                var content = data.content;
                status = content.status;
                button.data('status', status);
                // button.attribute('title', content.statusLabel);
                // button.attribute('aria-label', content.statusLabel);
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

    // Validate all values of a correction.
    $('#content').on('click', '.correction a.validate', function(e) {
        e.preventDefault();

        var button = $(this);
        var url = button.data('validate-url');
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
                // Set the correction reviewed in all cases.
                var content = data.content.reviewed;
                status = content.status;
                buttonReviewed = button.closest('th').find('a.status-toggle');
                buttonReviewed.data('status', status);
                buttonReviewed.addClass('o-icon-' + status);

                // Update the validate button.
                content = data.content;
                status = content.status;
                button.attr('title', statusLabel);
                button.attr('aria-label', statusLabel);

                // Reload the page to update the default show view.
                // TODO Dynamically update default show view after correction.
                location.reload();
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

    // Validate a specific value of a correction.
    $('#content').on('click', '.correction a.validate-value', function(e) {
        e.preventDefault();

        var button = $(this);
        var url = button.data('validate-value-url');
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
                // Update the validate button.
                var content = data.content;
                status = content.status;
                button.attr('title', content.statusLabel);
                button.attr('aria-label', content.statusLabel);
                // TODO Update the value in the main metadata tab.
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
