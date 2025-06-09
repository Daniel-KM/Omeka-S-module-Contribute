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

(function ($) {

    $(document).ready(function() {

        /**
         * @see ContactUs, Contribute, Guest, SearchHistory, Selection, TwoFactorAuth.
         */

        const beforeSpin = function (element) {
            var span = $(element).find('span');
            if (!span.length) {
                span = $(element).next('span.appended');
                if (!span.length) {
                    $('<span class="appended"></span>').insertAfter($(element));
                    span = $(element).next('span');
                }
            }
            element.hide();
            span.addClass('fas fa-sync fa-spin');
        };

        const afterSpin = function (element) {
            var span = $(element).find('span');
            if (!span.length) {
                span = $(element).next('span.appended');
                if (span.length) {
                    span.remove();
                }
            } else {
                span.removeClass('fas fa-sync fa-spin');
            }
            element.show();
        };

        /**
         * Get the main message of jSend output, in particular for status fail.
         */
        const jSendMessage = function(data) {
            if (typeof data !== 'object') {
                return null;
            }
            if (data.message) {
                return data.message;
            }
            if (!data.data) {
                return null;
            }
            if (data.data.message) {
                return data.data.message;
            }
            for (let value of Object.values(data.data)) {
                if (typeof value === 'string' && value.length) {
                    return value;
                }
            }
            return null;
        }

        const dialogMessage = function (message, nl2br = false) {
            // Use a dialog to display a message, that should be escaped.
            var dialog = document.querySelector('dialog.popup-message');
            if (!dialog) {
                dialog = `
    <dialog class="popup popup-dialog dialog-message popup-message" data-is-dynamic="1">
        <div class="dialog-background">
            <div class="dialog-panel">
                <div class="dialog-header">
                    <button type="button" class="dialog-header-close-button" title="Close" autofocus="autofocus">
                        <span class="dialog-close">🗙</span>
                    </button>
                </div>
                <div class="dialog-contents">
                    {{ message }}
                </div>
            </div>
        </div>
    </dialog>`;
                $('body').append(dialog);
                dialog = document.querySelector('dialog.dialog-message');
            }
            if (nl2br) {
                message = message.replace(/(?:\r\n|\r|\n)/g, '<br/>');
            }
            dialog.innerHTML = dialog.innerHTML.replace('{{ message }}', message);
            dialog.showModal();
            $(dialog).trigger('o:dialog-opened');
        };

        /**
         * Manage ajax fail.
         *
         * @param {Object} xhr
         * @param {string} textStatus
         * @param {string} errorThrown
         */
        const handleAjaxFail = function(xhr, textStatus, errorThrown) {
            const data = xhr.responseJSON;
            if (data && data.status === 'fail') {
                let msg = jSendMessage(data);
                dialogMessage(msg ? msg : 'Check input', true);
            } else {
                // Error is a server error (in particular cannot send mail).
                let msg = data && data.status === 'error' && data.message && data.message.length ? data.message : 'An error occurred.';
                dialogMessage(msg, true);
            }
        };

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
            .done(function(data, textStatus, xhr) {
                if (!data.status || data.status !== 'success') {
                    handleAjaxFail(xhr, textStatus);
                } else {
                    status = data.data.contribution.status;
                    button.data('status', status);
                    button.prop('title', data.data.contribution.statusLabel);
                    button.prop('aria-label', data.data.contribution.statusLabel);
                    $(document).trigger('o:contribution-updated', data);
                }
            })
            .fail(handleAjaxFail)
            .always(function () {
                button.removeClass('fas fa-sync fa-spin').addClass('o-icon-' + status);
            });
        });

        // Display the dialog to send a message.
        $('#content').on('click', '.contribution a.send-message', function(e) {
            var url = $(this).data('url');
            const dialog = document.querySelector('dialog.dialog-send-message');
            $(dialog).find('form').prop('action', url);
            dialog.showModal();
            $(dialog).trigger('o:dialog-opened');
        });

        // Send a message via ajax.
        $('#content').on('click', '#send-message-form [name=submit]', function(e) {
            e.preventDefault();

            const dialog = this.closest('dialog.popup');
            const button = $(this);
            const form = button.closest('form');
            const url = form.prop('action');
            $.ajax({
                type: 'POST',
                url: url,
                data: form.serialize(),
                beforeSend: function() {
                    button.removeClass('o-icon-' + status).addClass('fas fa-sync fa-spin');
                }
            })
            .done(function(data, textStatus, xhr) {
                if (!data.status || data.status !== 'success') {
                    handleAjaxFail(xhr, textStatus);
                } else {
                    dialog.close();
                    if (data.data && data.data.message) {
                        dialogMessage(data.data.message);
                    }
                    // TODO Dynamically update status view after message sent.
                    // location.reload();
                    $(document).trigger('o:contribution-email-sent', data);
                }
            })
            .fail(handleAjaxFail)
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
            .done(function(data, textStatus, xhr) {
                if (!data.status || data.status !== 'success') {
                    handleAjaxFail(xhr, textStatus);
                } else {
                    status = 'expired';
                    button.prop('title', data.data.contribution_token.statusLabel);
                    button.prop('aria-label', data.data.contribution_token.statusLabel);
                }
            })
            .fail(handleAjaxFail)
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
            .done(function(data, textStatus, xhr) {
                if (!data.status || data.status !== 'success') {
                    handleAjaxFail(xhr, textStatus);
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
            .fail(handleAjaxFail)
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
            .done(function(data, textStatus, xhr) {
                if (!data.status || data.status !== 'success') {
                    handleAjaxFail(xhr, textStatus);
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
            .fail(handleAjaxFail)
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
            .done(function(data, textStatus, xhr) {
                if (!data.status || data.status !== 'success') {
                    handleAjaxFail(xhr, textStatus);
                } else {
                    // Update the validate button.
                    status = data.data.contribution.status;
                    button.prop('title', data.data.contribution.statusLabel);
                    button.prop('aria-label', data.data.contribution.statusLabel);
                    // TODO Update the value in the main metadata tab.
                }
            })
            .fail(handleAjaxFail)
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

        $(document).on('click', '.dialog-header-close-button', function(e) {
            const dialog = this.closest('dialog.popup');
            if (dialog) {
                dialog.close();
                if (dialog.hasAttribute('data-is-dynamic') && dialog.getAttribute('data-is-dynamic')) {
                    dialog.remove();
                }
            } else {
                $(this).closest('.popup').addClass('hidden').hide();
            }
        });

    });
})(jQuery);
