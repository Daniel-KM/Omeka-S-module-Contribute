$(document).ready(function() {

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

    $('.chosen-select').chosen(chosenOptions);

    $('#edit-resource .inputs').on('click', '.add-value', function(ev) {
        ev.stopPropagation();

        var target = $(ev.target);
        if (!target.is('.add-value-new, .add-value-resource, .add-value-uri, .add-value-numeric-integer, .add-value-numeric-timestamp, .add-value-custom-vocab, .add-value-value-suggest')) {
            return;
        }

        var selector = target.closest('.default-selector');
        var term = selector.data('next-term');
        var index = selector.data('next-index') ? parseInt(selector.data('next-index')) : 0;
        var inputs = target.closest('.property').find('.values').first();
        var newElement,
            name,
            namel,
            newInput,
            newInput1,
            newInput2,
            newInput3;

        var maxValues = fields[term] && fields[term]['max_values'] ? parseInt(fields[term]['max_values']) : 0;
        if (maxValues && index >= maxValues) {
            selector.hide();
            return;
        }

        var required = fields[term] && fields[term]['required'];

        if (target.hasClass('add-value-new')) {
            newElement = $('#edit_value_template > .value').clone();
            name = term + '[' + index + '][@value]';
            newInput = $(newElement).find('textarea')
                .prop('name', name)
                .removeAttr('readonly')
                .val('');
        }

        if (target.hasClass('add-value-resource')) {
            newElement = $('#edit_resource_template > .value').clone();
            name = term + '[' + index + '][@resource]';
            newInput = $(newElement).find('select');
            fillSelectOptions(newInput, name, target);
        }

        if (target.hasClass('add-value-uri')) {
            newElement = $('#edit_uri_template > .value').clone();
            name = term + '[' + index + '][@uri]';
            namel = term + '[' + index + '][@label]';
            $(newElement).find('input[data-value-key="@uri"]')
                .prop('name', name)
                .removeAttr('readonly')
                .val('');
            $(newElement).find('input[data-value-key="@label"]')
                .prop('name', namel)
                .removeAttr('readonly')
                .val('');
        }

        if (target.hasClass('add-value-numeric-integer')) {
            newElement = $('#edit_numeric-integer_template > .value').clone();
            name = term + '[' + index + '][@value]';
            newInput = $(newElement).find('input')
                .prop('name', name)
                .removeAttr('readonly')
                .val('');
        }

        if (target.hasClass('add-value-numeric-timestamp')) {
            newElement = $('#edit_numeric-timestamp_template > .value').clone();
            name = term + '[' + index + '][@value]';
            namel = term + '[' + index + ']';
            newInput = $(newElement).find('input[data-value-key="@value"]')
                .prop('name', name)
                .removeAttr('readonly')
                .val('');
            // Other elements are not submitted, but fill the hidden input on blur.
            newInput1 = $(newElement).find('[data-input-part=year]')
                .prop('name', namel + '[year]')
                .removeAttr('readonly')
                .val('');
            newInput2 = $(newElement).find('[data-input-part=month]')
                .prop('name', namel + '[month]')
                .removeAttr('readonly')
                .val('');
            newInput3 = $(newElement).find('[data-input-part=day]')
                .prop('name', namel + '[day]')
                .removeAttr('readonly')
                .val('');
            newInput1.on('blur', fillDate);
            newInput2.on('blur', fillDate);
            newInput3.on('blur', fillDate);
        }

        if (target.hasClass('add-value-custom-vocab')) {
            const basetype = target.data('basetype');
            newElement = $('#edit_customvocab_template > .value[data-basetype=' + basetype + ']').clone();
            newInput = $(newElement).find('select');
            if (basetype === 'resource') {
                name = term + '[' + index + '][@resource]';
            } else if (basetype === 'uri') {
                // The label is managed by the server.
                name = term + '[' + index + '][@uri]';
            } else {
                name = term + '[' + index + '][@value]';
            }
            fillSelectOptions(newInput, name, target);
        }

        if (target.hasClass('add-value-value-suggest')) {
            newElement = $('#edit_valuesuggest_template > .value').clone();
            name = term + '[' + index + '][@uri]';
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
        if (maxValues && index >= maxValues) {
            selector.hide();
        }

        inputs.append(newElement);
        selector.data('next-index', index);
    });

});
