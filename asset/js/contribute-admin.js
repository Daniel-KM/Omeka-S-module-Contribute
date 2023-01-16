// TODO Remove dead code.

// Kept as long as pull request #1260 is not passed.
Omeka.contributionManageSelectedActions = function() {
    var selectedOptions = $('[value="update-selected"], [value="delete-selected"], #batch-form .batch-inputs .batch-selected');
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
    // Browse batch actions.
    $(function() {

        const batchSelect = $('#batch-form .batch-actions-select');
        batchSelect.attr('name', 'batch_action');

        batchSelect.append(
            $('<option class="batch-selected" disabled></option>').val('contribution_selected').html(Omeka.jsTranslate('Prepare tokens to edit selected'))
        );
        batchSelect.append(
            $('<option></option>').val('contribution_all').html(Omeka.jsTranslate('Prepare tokens to edit all'))
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

$(document).ready(function() {

    var contributionInfo = function() {
        return `
            <div class="field">
                <h3>` + Omeka.jsTranslate('Contribute options') + `</h3>
                <div class="option">
                    <label for="is-editable">
                        ` + Omeka.jsTranslate('Editable') + `
                        <input id="is-editable" type="checkbox">
                    </label>
                </div>
                <div class="option">
                    <label for="is-fillable">
                        ` + Omeka.jsTranslate('Fillable') + `
                        <input id="is-editable" type="checkbox">
                    </label>
                </div>
            </div>
        `;
    }
    $('#edit-sidebar .confirm-main').append(contributionInfo());

    // Manage the modal to create the token.
    // Get the modal.
    var modal = document.getElementById('create_contribution_token_dialog');
    // Get the button that opens the modal.
    var btn = document.getElementById('create_contribution_token_dialog_go');
    // Get the <span> element that closes the modal.
    var span = document.getElementById('create_contribution_token_dialog_close');

    // When the user clicks the button, open the modal.
    if (btn) {
        btn.onclick = function() {
            var href = $('#create_contribution_token a').prop('href');
            var email = $('#create_contribution_token_dialog_email').val();
            if (email !== '' && !validateEmail(email)) {
                $('#create_contribution_token_dialog_email').css('color', 'red');
                return;
            }
            href = href + '&email=' + email;
            location.href = href;
            modal.style.display = 'none';
        }
    }

    // When the user clicks on <span> (x), close the modal.
    if (span) {
        span.onclick = function() {
            modal.style.display = 'none';
        }
    }

    // TODO When the user clicks anywhere outside of the modal, close it.
    // window.onclick = function(event) {
    //     if (event.target == modal) {
    //         modal.style.display = 'none';
    //     }
    // }

    const alertFail = (jqXHR, textStatus) => {
        if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
            alert(jqXHR.responseJSON.message);
        } else {
            alert(Omeka.jsTranslate('Something went wrong'));
        }
    }

    const alertDataError = (data) => {
        if (data.data && Object.keys(data.data).length) {
            var i = 0;
            const flat = (obj, out) => {
                Object.keys(obj).forEach(key => {
                    if (typeof obj[key] == 'object') {
                        out = flat(obj[key], out);
                    } else if (key in out) {
                        out[key + '_' + (++i).toString()] = obj[key];
                    } else {
                        out[key] = obj[key];
                    }
                })
                return out;
            }
            var message = data.message && data.message.length
                ? data.message
                : Omeka.jsTranslate('Contribution is not valid.');
            var flatData = flat(data.data, {});
            Object.keys(flatData).reduce(function (r, k) {
                message += "\n" + flatData[k];
             }, []);
            alert(message);
        } else if (data.message) {
            alert(data.message);
        } else {
            alert(Omeka.jsTranslate('Something went wrong'));
        }
    }

    $('#create_contribution_token').on('click', function(ev){
        modal.style.display = 'block';
        ev.preventDefault();
    })

    // Mark a contribution reviewed/unreviewed.
    $('#content').on('click', '.contribution a.status-toggle', function(e) {
        e.preventDefault();

        var button = $(this);
        var url = button.data('status-toggle-url');
        var status = button.data('status');
        $.ajax({
            url: url,
            beforeSend: function() {
                button.removeClass('o-icon-' + status).addClass('fas fa-sync fa-spin');
            }
        })
        .done(function(data) {
            if (!data.status || data.status !== 'success') {
                alertDataError(data);
            } else {
                status = data.data.contribution.status;
                button.data('status', status);
                button.prop('title', data.data.contribution.statusLabel);
                button.prop('aria-label', data.data.contribution.statusLabel);
            }
        })
        .fail(alertFail)
        .always(function () {
            button.removeClass('fas fa-sync fa-spin').addClass('o-icon-' + status);
        });
    });

    // Expire a token
    $('#content').on('click', '.contribution a.expire-token', function(e) {
        e.preventDefault();

        var button = $(this);
        var url = button.data('expire-token-url');
        var status = 'expire';
        $.ajax({
            url: url,
            beforeSend: function() {
                button.removeClass('o-icon-expire-token').addClass('fas fa-sync fa-spin');
            }
        })
        .done(function(data) {
            if (!data.status || data.status !== 'success') {
                alertDataError(data);
            } else {
                status = 'expired';
                button.prop('title', data.data.contribution_token.statusLabel);
                button.prop('aria-label', data.data.contribution_token.statusLabel);
            }
        })
        .fail(alertFail)
        .always(function () {
            button.removeClass('fas fa-sync fa-spin').addClass('o-icon-' + status + '-token');
        });
    });

    // Validate all values of a contribution.
    $('#content').on('click', '.contribution .actions .o-icon-add', function(e) {
        e.preventDefault();
        var button = $(this);
        var url = button.prop('href');
        var status = 'add';
        $.ajax({
            url: url,
            beforeSend: function() {
                button.removeClass('o-icon-' + status).addClass('fas fa-sync fa-spin');
            }
        })
        .done(function(data) {
            if (!data.status || data.status !== 'success') {
                alertDataError(data);
            } else {
                button.closest('td').find('span.title')
                    .wrap('<a href="' + data.data.url + '"></a>');
                let newButton = '<a class="o-icon-edit"'
                    + ' title="'+ Omeka.jsTranslate('Edit') + '"'
                    + ' href="' + data.data.url + '"'
                    + ' aria-label="' + Omeka.jsTranslate('Edit') + '"></a>';
                button.replaceWith(newButton);
            }
        })
        .fail(alertFail)
        .always(function () {
            button.removeClass('fas fa-sync fa-spin').addClass('o-icon-' + status);
        });
    });

    // Validate all values of a contribution.
    $('#content').on('click', '.contribution a.validate', function(e) {
        e.preventDefault();
        var button = $(this);
        var url = button.data('validate-url');
        var status = button.data('status');
        $.ajax({
            url: url,
            beforeSend: function() {
                button.removeClass('o-icon-' + status).addClass('fas fa-sync fa-spin');
            }
        })
        .done(function(data) {
            if (!data.status || data.status !== 'success') {
                alertDataError(data);
            } else {
                // Set the contribution reviewed in all cases.
                status = data.data.contribution.reviewed.status;
                buttonReviewed = button.closest('th').find('a.status-toggle');
                buttonReviewed.data('status', status);
                buttonReviewed.addClass('o-icon-' + status);

                // Update the validate button.
                status = data.data.contribution.status;
                // button.prop('title', statusLabel);
                // button.prop('aria-label', statusLabel);

                // Reload the page to update the default show view.
                // TODO Dynamically update default show view after contribution.
                location.reload();
            }
        })
        .fail(alertFail)
        .always(function () {
            button.removeClass('fas fa-sync fa-spin').addClass('o-icon-' + status);
        });
    });

    // Validate a specific value of a contribution.
    $('#content').on('click', '.contribution a.validate-value', function(e) {
        e.preventDefault();

        var button = $(this);
        var url = button.data('validate-value-url');
        var status = button.data('status');
        $.ajax({
            url: url,
            beforeSend: function() {
                button.removeClass('o-icon-' + status).addClass('fas fa-sync fa-spin');
            }
        })
        .done(function(data) {
            if (!data.status || data.status !== 'success') {
                alertDataError(data);
            } else {
                // Update the validate button.
                status = data.data.contribution.status;
                button.prop('title', data.data.contribution.statusLabel);
                button.prop('aria-label', data.data.contribution.statusLabel);
                // TODO Update the value in the main metadata tab.
            }
        })
        .fail(alertFail)
        .always(function () {
            button.removeClass('fas fa-sync fa-spin').addClass('o-icon-' + status);
        });
    });

    // https://stackoverflow.com/questions/46155/how-to-validate-an-email-address-in-javascript
    function validateEmail(email) {
        var re = /^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
        return re.test(email);
    }

    /**
     * Search sidebar.
     */
    $('#content').on('click', 'a.search', function(e) {
        e.preventDefault();
        var sidebar = $('#sidebar-search');
        Omeka.openSidebar(sidebar);

        // Auto-close if other sidebar opened
        $('body').one('o:sidebar-opened', '.sidebar', function () {
            if (!sidebar.is(this)) {
                Omeka.closeSidebar(sidebar);
            }
        });
    });

});
