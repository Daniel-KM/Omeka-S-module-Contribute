'use strict';

/**
 * Require common-dialog.js.
 *
 * @see Access, Comment, ContactUs, Contribute, Generate, Guest, Resa, SearchHistory, Selection, TwoFactorAuth, Urify.
 */

(function ($) {
    $(document).ready(function() {

        /****
         * Manage the creation of a specific token for a resource.
         */

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
        const tokenModal = document.getElementById('create_contribution_token_dialog');
        // Get the button that opens the modal.
        const tokenButton = document.getElementById('create_contribution_token_dialog_go');
        // Get the <span> element that closes the modal.
        const tokenSpan = document.getElementById('create_contribution_token_dialog_close');

        // When the user clicks the button, open the modal.
        if (tokenButton) {
            tokenButton.onclick = function() {
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
        if (tokenSpan) {
            tokenSpan.onclick = function() {
                tokenModal.style.display = 'none';
            }
        }

        // TODO When the user clicks anywhere outside of the modal, close it.
        // window.onclick = function(event) {
        //     if (event.target == tokenModal) {
        //         tokenModal.style.display = 'none';
        //     }
        // }

        $('#create_contribution_token').on('click', function(ev){
            tokenModal.style.display = 'block';
            ev.preventDefault();
        })

        /****
         * Manage the contributions and valildations.
         */

        // Display the dialog to send a message.
        $('#content').on('click', '.contribution .send-message', function(e) {
            e.preventDefault();
            const url = $(this).data('url');
            const dialog = document.querySelector('dialog.dialog-send-message.dialog-contribute');
            $(dialog).find('form').prop('action', url);
            dialog.showModal();
            $(dialog).trigger('o:dialog-opened');
        });

        // Validate all values of a contribution.
        $('#content').on('click', '.contribution .validate', function(e) {
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
        $('#content').on('click', '.contribution .validate-value', function(e) {
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
         * Update UI after any jSend success.
         */
        document.addEventListener('o:jsend-success', function(ev) {
            const detail = ev.detail || {};
            const data = detail.data || {};
            let button = detail.context?.target || null;

            if (!button) {
                return;
            }

            // Fix issue when clicking span inside button, if any.
            let iconSpan = button;
            if (button.tagName === 'SPAN') {
                button = button.closest('button') || button.closest('a');
                if (!button) {
                    return;
                }
            } else {
                iconSpan = button.querySelector('span.fas');
            }

            const row = $(button.closest('tr'));

            // Undertaking toggle.
            if (button.matches('.undertaking-toggle') && data.data.contribution) {
                const prev = button.dataset.status;
                button.dataset.status = data.data.contribution.status;
                button.title = data.data.contribution.statusLabel || button.title;
                button.ariaLabel = button.title;
                $(iconSpan)
                    .removeClass('o-icon-' + prev)
                    .addClass('o-icon-' + button.dataset.status);
            }

            // Status toggle.
            else if (button.matches('.status-toggle') && data.data.contribution) {
                const prev = button.dataset.status;
                button.dataset.status = data.data.contribution.status;
                button.title = data.data.contribution.statusLabel || button.title;
                button.ariaLabel = button.title;
                $(iconSpan)
                    .removeClass('o-icon-' + prev)
                    .addClass('o-icon-' + button.dataset.status);
            }

            else if (button.matches('.create-resource') && data.data.contribution) {
                row.find('span.title')
                    .wrap('<a href="' + data.data.contribution.url + '"></a>');
                const newButton = '<a class="o-icon-edit"'
                    + ' title="'+ Omeka.jsTranslate('Show') + '"'
                    + ' href="' + data.data.contribution.url + '"'
                    + ' aria-label="' + Omeka.jsTranslate('Show') + '"></a>';
                $(button).replaceWith(newButton);
                updateStatuses(data.data.contribution);
            }

            else if (button.name === 'submit' && button.closest('form').name === 'send-message' && data.data.contribution) {
                updateStatuses(data.data.contribution);
            }

            // Expire token.
            else if (button.matches('.expire-token') && data.data.contribution_token) {
                button.dataset.status = data.data.contribution_token.status;
                button.title = data.data.contribution_token.statusLabel || button.title;
                button.ariaLabel = button.title;
                $(iconSpan).removeClass('o-icon-expire-token');
            }

        }, false);

        function updateStatuses(contribution) {
            const contributionId = contribution['o:id'];
            const rows = $('.contribution[data-id="' + contributionId + '"]');
            const isSubmitted = contribution['o-module-contribute:submitted'];
            const submittedButton = rows.find('.is-submitted span.fas');
            submittedButton
                .removeClass('o-icon-submitted o-icon-not-not-submitted')
                .addClass('o-icon-' + (isSubmitted ? 'submitted' : 'not-submitted'))
                .attr('title', isSubmitted ? Omeka.jsTranslate('Submitted') : Omeka.jsTranslate('Not submitted'))
                .attr('aria-label', submittedButton.attr('title'));
            const isUndertaken = contribution['o-module-contribute:undertaken'];
            const undertakingButton = rows.find('.undertaking-toggle span.fas');
            undertakingButton
                .removeClass('o-icon-undertaken o-icon-not-undertaken')
                .addClass('o-icon-' + (isUndertaken ? 'undertaken' : 'not-undertaken'))
                .attr('title', isUndertaken ? Omeka.jsTranslate('Undertaken') : Omeka.jsTranslate('Not undertaken'))
                .attr('aria-label', undertakingButton.attr('title'));
            const isValidated = contribution['o-module-contribute:validated'];
            const validateButton = rows.find('.status-toggle span.fas');
            validateButton
                .removeClass('o-icon-validated o-icon-not-validated o-icon-undetermined')
                .addClass('o-icon-' + (isValidated === null ? 'undetermined' : (isValidated ? 'validated' : 'not-validated')))
                .attr('title', isValidated === null ? Omeka.jsTranslate('Undetermined') : (isValidated ? Omeka.jsTranslate('Validated') : Omeka.jsTranslate('Rejected')))
                .attr('aria-label', validateButton.attr('title'));
        }

        /**
         * @see https://stackoverflow.com/questions/46155/how-to-validate-an-email-address-in-javascript
         */
        function validateEmail(email) {
            const re = /^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
            return re.test(email);
        }

        /****
         * Other.
         */

        /**
         * Search sidebar.
         */
        $('#content').on('click', '.button-sidebar-search', function(e) {
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
