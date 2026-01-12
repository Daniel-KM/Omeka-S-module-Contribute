'use strict';

/**
 * Require common-dialog.js.
 *
 * @see Access, Comment, ContactUs, Contribute, Generate, Guest, Resa, SearchHistory, Selection, TwoFactorAuth, Urify.
 */

(function ($) {
    $(document).ready(function() {

        const contributionInfo = function() {
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
                            <input id="is-fillable" type="checkbox">
                        </label>
                    </div>
                </div>
            `;
        }
        $('#edit-sidebar .confirm-main').append(contributionInfo());

        // Manage the modal to create the token.
        // Get the modal.
        const modal = document.getElementById('create_contribution_token_dialog');
        // Get the button that opens the modal.
        const btn = document.getElementById('create_contribution_token_dialog_go');
        // Get the <span> element that closes the modal.
        const span = document.getElementById('create_contribution_token_dialog_close');

        // When the user clicks the button, open the modal.
        if (btn) {
            btn.onclick = function() {
                let href = $('#create_contribution_token a').prop('href');
                const email = $('#create_contribution_token_dialog_email').val();
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

        // Mark a contribution undertaken.
        $('#content').on('click', '.contribution a.undertaking-toggle', function(e) {
            e.preventDefault();
            const button = this;
            const url = button.dataset.statusUndertakenToggleUrl;
            let status = button.dataset.status;

            CommonDialog.spinnerEnable(button);
            $(button).removeClass('o-icon-' + status);

            fetch(url, { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (!data.status || data.status !== 'success') {
                        CommonDialog.jSendFail(data);
                    } else {
                        status = data.data.contribution.status;
                        button.dataset.status = status;
                        button.title = data.data.contribution.statusLabel;
                        button.setAttribute('aria-label', data.data.contribution.statusLabel);
                        $(document).trigger('o:contribution-updated', data);
                    }
                })
                .catch(error => CommonDialog.jSendFail(error))
                .finally(() => {
                    CommonDialog.spinnerDisable(button);
                    $(button).addClass('o-icon-' + status);
                });
        });

        // Mark a contribution validated.
        $('#content').on('click', '.contribution a.status-toggle', function(e) {
            e.preventDefault();
            const button = this;
            const url = button.dataset.statusToggleUrl;
            let status = button.dataset.status;

            CommonDialog.spinnerEnable(button);
            $(button).removeClass('o-icon-' + status);

            fetch(url, { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (!data.status || data.status !== 'success') {
                        CommonDialog.jSendFail(data);
                    } else {
                        status = data.data.contribution.status;
                        button.dataset.status = status;
                        button.title = data.data.contribution.statusLabel;
                        button.setAttribute('aria-label', data.data.contribution.statusLabel);
                        $(document).trigger('o:contribution-updated', data);
                    }
                })
                .catch(error => CommonDialog.jSendFail(error))
                .finally(() => {
                    CommonDialog.spinnerDisable(button);
                    $(button).addClass('o-icon-' + status);
                });
        });

        // Display the dialog to send a message.
        $('#content').on('click', '.contribution a.send-message', function(e) {
            e.preventDefault();
            const url = $(this).data('url');
            const dialog = document.querySelector('dialog.dialog-send-message.dialog-contribute');
            $(dialog).find('form').prop('action', url);
            dialog.showModal();
            $(dialog).trigger('o:dialog-opened');
        });

        // Send a message via ajax.
        $('#content').on('submit', '#contribute-send-message-form', function(e) {
            e.preventDefault();
            const dialog = this.closest('dialog.popup');
            const button = event.submitter;
            const form = this;
            const url = form.action;
            const formData = new FormData(form);
            const formQuery = new URLSearchParams(formData).toString();

            CommonDialog.spinnerEnable(button);

            fetch(url, {
                method: 'POST',
                body: formQuery,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            })
            .then(response => response.json())
            .then(data => {
                if (!data.status || data.status !== 'success') {
                    CommonDialog.jSendFail(data);
                } else {
                    dialog.close();
                    const msg = CommonDialog.jSendMessage(data);
                    if (msg) {
                        CommonDialog.dialogAlert(msg);
                    }
                    // TODO Dynamically update status view after message sent.
                    // location.reload();
                    $(document).trigger('o:contribution-email-sent', data);
                }
            })
            .catch(error => CommonDialog.jSendFail(error))
            .finally(() => {
                CommonDialog.spinnerDisable(button);
            });
        });

        // Expire a token
        $('#content').on('click', '.contribution a.expire-token', function(e) {
            e.preventDefault();
            const button = this;
            const url = button.dataset.expireTokenUrl;
            let status = 'expire';

            CommonDialog.spinnerEnable(button);
            $(button).removeClass('o-icon-expire-token');

            fetch(url, { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (!data.status || data.status !== 'success') {
                        CommonDialog.jSendFail(data);
                    } else {
                        status = 'expired';
                        button.title = data.data.contribution_token.statusLabel;
                        button.setAttribute('aria-label', data.data.contribution_token.statusLabel);
                    }
                })
                .catch(error => CommonDialog.jSendFail(error))
                .finally(() => {
                    CommonDialog.spinnerDisable(button);
                    $(button).addClass('o-icon-' + status + '-token');
                });
        });

        // Add a contribution.
        $('#content').on('click', '.contribution .actions .o-icon-add', function(e) {
            e.preventDefault();
            const button = this;
            const url = button.href;
            let status = 'add';

            CommonDialog.spinnerEnable(button);
            $(button).removeClass('o-icon-' + status);

            fetch(url, { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (!data.status || data.status !== 'success') {
                        CommonDialog.jSendFail(data);
                    } else {
                        $(button).closest('td').find('span.title')
                            .wrap('<a href="' + data.data.url + '"></a>');
                        const newButton = '<a class="o-icon-edit"'
                            + ' title="'+ Omeka.jsTranslate('Edit') + '"'
                            + ' href="' + data.data.url + '"'
                            + ' aria-label="' + Omeka.jsTranslate('Edit') + '"></a>';
                        $(button).replaceWith(newButton);
                    }
                })
                .catch(error => CommonDialog.jSendFail(error))
                .finally(() => {
                    CommonDialog.spinnerDisable(button);
                    $(button).addClass('o-icon-' + status);
                });
        });

        // Validate all values of a contribution.
        $('#content').on('click', '.contribution a.validate', function(e) {
            e.preventDefault();
            const button = this;
            const url = button.dataset.validateUrl;
            let status = button.dataset.status;

            CommonDialog.spinnerEnable(button);
            $(button).removeClass('o-icon-' + status);

            fetch(url, { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (!data.status || data.status !== 'success') {
                        CommonDialog.jSendFail(data);
                    } else {
                        // Set the contribution validated in all cases.
                        status = data.data.contribution.validated.status;
                        const buttonValidated = $(button).closest('th').find('a.status-toggle');
                        buttonValidated.data('status', status);
                        buttonValidated.addClass('o-icon-' + status);
                        // Update the validate button.
                        status = data.data.contribution.status;
                        // button.prop('title', statusLabel);
                        // button.prop('aria-label', statusLabel);
                        // Reload the page to update the default show view.
                        // TODO Dynamically update default show view after contribution.
                        location.reload();
                    }
                })
                .catch(error => CommonDialog.jSendFail(error))
                .finally(() => {
                    CommonDialog.spinnerDisable(button);
                    $(button).addClass('o-icon-' + status);
                });
        });

        // Validate a specific value of a contribution.
        $('#content').on('click', '.contribution a.validate-value', function(e) {
            e.preventDefault();
            const button = this;
            const url = button.dataset.validateValueUrl;
            let status = button.dataset.status;

            CommonDialog.spinnerEnable(button);
            $(button).removeClass('o-icon-' + status);

            fetch(url, { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (!data.status || data.status !== 'success') {
                        CommonDialog.jSendFail(data);
                    } else {
                        // Update the validate button.
                        status = data.data.contribution.status;
                        button.title = data.data.contribution.statusLabel;
                        button.setAttribute('aria-label', data.data.contribution.statusLabel);
                        // TODO Update the value in the main metadata tab.
                    }
                })
                .catch(error => CommonDialog.jSendFail(error))
                .finally(() => {
                    CommonDialog.spinnerDisable(button);
                    $(button).addClass('o-icon-' + status);
                });
        });

        /**
         * @see https://stackoverflow.com/questions/46155/how-to-validate-an-email-address-in-javascript
         */
        function validateEmail(email) {
            const re = /^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
            return re.test(email);
        }

        /**
         * Search sidebar.
         */
        $('#content').on('click', 'a.search', function(e) {
            e.preventDefault();
            const sidebar = $('#sidebar-search');
            Omeka.openSidebar(sidebar);

            $('body').one('o:sidebar-opened', '.sidebar', function () {
                if (!sidebar.is(this)) {
                    Omeka.closeSidebar(sidebar);
                }
            });
        });

    });
})(jQuery);
