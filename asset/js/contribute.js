$(document).ready(function() {

    // Get fields and mediaFields from html.

    /**
     * Chosen default options.
     *
     * @see https://harvesthq.github.io/chosen/
     */
    var chosenOptions = {
        allow_single_deselect: true,
        disable_search_threshold: 10,
        width: '100%',
        include_group_label_in_selected: true,
    };

    function baseName(field, index, indexMedia) {
        return Number.isInteger(indexMedia)
            ? ('media[' + indexMedia + '][' + field + '][' + index + ']')
            : (field + '[' + index + ']');
    }

    function fillSelectOptions(newInput, name, target) {
        newInput
            .prop('name', name)
            .removeAttr('readonly')
            .addClass('chosen-select')
            .val('');
        $.each(target.data('value-options'), function (i, item) {
            newInput.append($('<option>', {
                value: item.v,
                text : item.t,
            }));
        });
        let specificChosenOptions = chosenOptions;
        let placeholder = target.data('placeholder');
        specificChosenOptions['placeholder_text_single'] = placeholder
            ? placeholder
            : 'Select…';
        newInput.chosen(specificChosenOptions);
    }

    function fillHiddenInput() {
        let group = $(this).closest('.group-input-part');
        if (group.find('[data-input-part=year]').length) {
            return;
        }
        let main = group.find('[data-input-part=main]');
        var parts = group.find('[data-input-part]:not([data-input-part=main])');
        let separator = main.data('separator');
        if (separator && separator.length) {
            var list = [];
            parts.each(function(index, part) {
                list.push($(part).val());
            });
            main.val(list.join(separator));
            return;
        }
        main.val('');
    }

    function fillDate() {
        let group = $(this).closest('.group-input-part');
        let main = group.find('[data-input-part=main]');
        let year = group.find('[data-input-part=year]').val();
        let month = group.find('[data-input-part=month]').val();
        let day = group.find('[data-input-part=day]').val();
        if (!year.length && !month.length && !day.length) {
            main.val('');
            return;
        }
        let iso = ('0000' + year).slice (-4) + '-' + ('00' + month).slice (-2) + '-' + ('00' + day).slice (-2);
        // Full iso regex, to complete the input pattern one.
        // @see https://stackoverflow.com/questions/12756159/regex-and-iso8601-formatted-datetime
        const regex = /^(\d{4}(?!\d{2}\b))((-?)((0[1-9]|1[0-2])(\3([12]\d|0[1-9]|3[01]))?|W([0-4]\d|5[0-2])(-?[1-7])?|(00[1-9]|0[1-9]\d|[12]\d{2}|3([0-5]\d|6[1-6])))([T\s]((([01]\d|2[0-3])((:?)[0-5]\d)?|24\:?00)([\.,]\d+(?!:))?)?(\17[0-5]\d([\.,]\d+)?)?([zZ]|([\+-])([01]\d|2[0-3]):?([0-5]\d)?)?)?)?$/
        main.val(regex.test(iso) ? iso : '');
    }

    function contributionDelete(id, url){
        $.post({
            url: url,
            data: {
                id: id,
                confirmform_csrf: confirmFormCsrf,
            },
        })
        .done(function(data) {
            window.location.reload();
         })
        .fail(function() {
            alert('An error occured.');
         });
    }

    // On load, hide all buttons "more" where the number of values is greater
    // than the allowed max. But display it when there is no values and not fillable,
    // so the user can add it (and the value empty should be displayed when it is required).
    // TODO Move this check in template form-value-more.
    [typeof fields === 'undefined' ? [] : fields, typeof mediaFields === 'undefined' ? [] : mediaFields].forEach(function(currentFields) {
        if (!currentFields) return;
        Object.keys(currentFields).forEach(function(term) {
            if (currentFields[term]['fillable'] === false) {
                $('#edit-resource .property[data-term="' + term + '"]').each(function () {
                    if ($(this).find('.values > .value').length) {
                        $(this).find('.add-values').hide();
                    }
                });
            }
            if (currentFields[term]['max_values']) {
                $('#edit-resource .property[data-term="' + term + '"]').each(function () {
                    if ($(this).find('.values > .value').length >= currentFields[term]['max_values']) {
                        $(this).find('.add-values').hide();
                    }
                });
            }
        });
    });

    // Manage some special fields that may be pre-loaded.

    $(':not(.contribute_template) .chosen-select').chosen(chosenOptions);

    $('#edit-resource').on('blur change', '[data-input-part]', fillHiddenInput);

    $('#edit-resource').on('blur change', '[data-input-part=year], [data-input-part=month], [data-input-part=day]', fillDate);

    if (typeof valueSuggestAutocomplete === 'function') {
        $(':not(.contribute_template) .valuesuggest-input').on('load', valueSuggestAutocomplete);
    }

    $('#edit-resource').on('click', '.add-values .add-value', function(ev) {
        ev.stopPropagation();

        var target = $(ev.target);
        const adds = [
            '.add-value-new',
            '.add-value-literal',
            '.add-value-resource',
            '.add-value-uri',
            '.add-value-numeric-integer',
            '.add-value-numeric-timestamp',
            '.add-value-custom-vocab',
            '.add-value-value-suggest',
            '.add-value-media-file',
        ];
        if (!target.is(adds.join(','))) {
            return;
        }

        var selector = target.closest('.add-values');
        var term = selector.data('next-term');
        var index = selector.data('next-index') ? parseInt(selector.data('next-index')) : 0;
        var isMedia = target.hasClass('add-value-media');
        var indexMedia = isMedia ? parseInt(target.closest('.contribute-media').data('index-media')) : null;
        var inputs = target.closest('.property').find('.values').first();
        var newElement,
            name,
            namel,
            newInput;

        const currentFields = isMedia ? mediaFields : fields;
        const fillable = currentFields[term] && currentFields[term]['fillable'];
        const required = currentFields[term] && currentFields[term]['required'];
        const countValues = target.closest('.property').find('.values > .value').length;
        const maxValues = currentFields[term] && currentFields[term]['max_values']
            ? parseInt(currentFields[term]['max_values'])
            : 0;
        if (maxValues && countValues >= maxValues) {
            selector.hide();
            return;
        }

        if (target.hasClass('add-value-new')) {
            var template = target.data('template');
            newElement = $('#' + template + ' > .value').clone();
            namel = baseName(term, index, indexMedia);
            name = namel + '[@value]';
            newInput = $(newElement).find('input[data-value-key="@value"]')
                .prop('name', name)
                .removeAttr('readonly')
                .val('');
            // Other elements are not submitted, but fill the hidden input on blur.
            $(newElement).find('[data-input-part]:not([data-input-part=main])').each(function(index, element) {
                $(this)
                    .prop('name', namel + '[' + (index + 1) + ']')
                    .removeAttr('readonly')
                    .val('');
            });
        }

        if (target.hasClass('add-value-literal')) {
            newElement = $('#edit_template_value > .value').clone();
            name = baseName(term, index, indexMedia) + '[@value]';
            newInput = $(newElement).find('textarea, input[data-value-key="@value"]')
                .prop('name', name)
                .removeAttr('readonly')
                .val('');
        }

        if (target.hasClass('add-value-resource')) {
            newElement = $('#edit_template_resource > .value').clone();
            name = baseName(term, index, indexMedia) + '[@resource]';
            newInput = $(newElement).find('select');
            fillSelectOptions(newInput, name, target);
        }

        if (target.hasClass('add-value-uri')) {
            newElement = $('#edit_template_uri > .value').clone();
            name = baseName(term, index, indexMedia) + '[@uri]';
            namel = baseName(term, index, indexMedia) + '[@label]';
            $(newElement).find('input[data-value-key="@uri"]')
                .prop('name', name)
                .removeAttr('readonly')
                .val('');
            $(newElement).find('input[data-value-key="@label"], textarea[data-value-key="@label"]')
                .prop('name', namel)
                .removeAttr('readonly')
                .val('');
        }

        if (target.hasClass('add-value-numeric-integer')) {
            newElement = $('#edit_template_numeric-integer > .value').clone();
            name = baseName(term, index, indexMedia) + '[@value]';
            newInput = $(newElement).find('input')
                .prop('name', name)
                .removeAttr('readonly')
                .val('');
        }

        if (target.hasClass('add-value-numeric-timestamp')) {
            newElement = $('#edit_template_numeric-timestamp > .value').clone();
            namel = baseName(term, index, indexMedia);
            name = namel + '[@value]';
            newInput = $(newElement).find('input[data-value-key="@value"]')
                .prop('name', name)
                .removeAttr('readonly')
                .val('');
            // Other elements are not submitted, but fill the hidden input on blur.
            $(newElement).find('[data-input-part=year]')
                .prop('name', namel + '[year]')
                .removeAttr('readonly')
                .val('');
            $(newElement).find('[data-input-part=month]')
                .prop('name', namel + '[month]')
                .removeAttr('readonly')
                .val('');
            $(newElement).find('[data-input-part=day]')
                .prop('name', namel + '[day]')
                .removeAttr('readonly')
                .val('');
        }

        if (target.hasClass('add-value-custom-vocab')) {
            const basetype = target.data('basetype');
            newElement = $('#edit_template_customvocab > .value[data-basetype=' + basetype + ']').clone();
            newInput = $(newElement).find('select');
            namel = baseName(term, index, indexMedia);
            if (basetype === 'resource') {
                name = namel + '[@resource]';
            } else if (basetype === 'uri') {
                // The label is managed by the server.
                name = namel + '[@uri]';
            } else {
                name = namel + '[@value]';
            }
            fillSelectOptions(newInput, name, target);
        }

        if (target.hasClass('add-value-value-suggest')) {
            newElement = $('#edit_template_valuesuggest > .value').clone();
            name = baseName(term, index, indexMedia) + '[@uri]';
            newInput = $(newElement).find('input').first();
            newInput
                .prop('name', name)
                .data('data-type', target.data('data-type'))
                .removeAttr('readonly')
                .val('');
            valueSuggestAutocomplete(newInput);
        }

        if (required && newInput) {
            newInput.prop('required', 'required');
        }

        ++index;

        // Check the button "more" for max values and when the value was removed.
        if (maxValues && (index >= maxValues || countValues + 1 >= maxValues)) {
            selector.hide();
        } else if (!fillable) {
            // Hide the selector since there is at least one value for a non fillable property.
            selector.hide();
        }

        inputs.append(newElement);
        selector.data('next-index', index);
    });

    $('#edit-resource').on('click', '.values .remove-value', function(ev) {
        ev.stopPropagation();
        ev.preventDefault();
        // Add a new value when it is a required one and no more value is set.
        // The button "add-value" or the template may be missing.
        var val = $(this).closest('.value');
        val.find('.chosen-option').chosen('destroy');
        const isRequired = val.find(':input[required]').length;
        if (isRequired && $(this).closest('.values').find('> .value').length <= 1) {
            val.find(':input').each(function() {
                if (this.type === 'checkbox' || this.type === 'radio') {
                    this.checked = false;
                } else {
                    $(this).val('');
                }
            });
            val.find('.chosen-option').chosen(chosenOptions);
          } else {
            // Display the button more values in all other cases.
            val.closest('.property').find('.add-values').show();
            val.remove();
        }
    });

    $('#edit-resource').on('click', '.inputs-media .add-media-new', function(ev) {
        var target = $(ev.target);
        var fieldsetMedias = target.closest('.contribute-medias');
        var indexMedia = 0 + parseInt(fieldsetMedias.data('next-index-media'));
        var fieldsetMedia = $('#edit_template_media > .sub-form').clone();
        // Set all names and indexes.
        fieldsetMedia
            .prop('name', 'media[' + indexMedia + ']')
            .data('index-media', indexMedia);
        // In fact, required names are already set and new ones are set
        // dynamically. so there only the input file name should be set.
        fieldsetMedia.find('input[type=file]')
            .prop('name', baseName('file', 0, indexMedia) + '[@value]');
        // Nevertheless, required fields are added by default in the template
        // and should be updated.
        fieldsetMedia.find('[name^="media[]"]').each(function() {
            $(this).prop('name', 'media[' + indexMedia + ']' + $(this).prop('name').substring(7));
        });
        fieldsetMedia.find('.chosen-container').remove();
        fieldsetMedia.find('.chosen-select').chosen(chosenOptions);
        fieldsetMedias
            .data('next-index-media', indexMedia + 1)
            .find('.inputs-media')
            .before(fieldsetMedia);
    });

    $('#edit-resource').on('click', '.contribute-media .remove-media', function(ev) {
        ev.stopPropagation();
        ev.preventDefault();
        $(this).closest('.contribute-media').remove();
    });

    /* Avoid submission with return key. */
    $('#edit-resource').on('keypress', ':input:not(textarea):not([type=submit])', function(ev) {
        if ((ev.keycode || ev.which) == '13') {
            return false;
        }
    });

    $('.remove-contribution').on('click', function(ev) {
        ev.stopPropagation();
        ev.preventDefault();
        const id = $(this).data('contribution-id');
        const urlDelete = $(this).data('contribution-url');
        const message = $(this).closest('.actions').data('message-remove-contribution');
        if (urlDelete && confirm(message)) {
            contributionDelete(id, urlDelete);
        }
        return false;
    });

});
