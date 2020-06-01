$(document).ready(function() {
    $('#edit-resource .property').on('click', function(ev) {
        var new_element, term, index, name, namel;

        if ($(ev.target).hasClass('add-value-new')) {
            new_element = $('#correct_value_template > .value').clone();
            term = $(ev.target.parentElement).data('next-term');
            index = $(ev.target.parentElement).data('next-index');
            name = term + '[' + index + '][@value]';

            $(new_element).find('textarea')
                .attr('name', name);
                .removeAttr('readonly');
                .val('');
            $(this).find('.values .inputs').before(new_element);

            index = parseInt(index) + 1;
            $(ev.target.parentElement).data('next-index', index);
        }

        if ($(ev.target).hasClass('add-value-resource')) {
            new_element = $('#correct_resource_template > .value').clone();
            term = $(ev.target.parentElement).data('next-term');
            index = $(ev.target.parentElement).data('next-index');
            name = term + '[' + index + '][@resource]';

            let select = $(new_element).find('select');
            select
                .attr('name', name)
                .removeAttr('readonly')
                .addClass('chosen-select')
                .val('');
            $.each(JSON.parse($(ev.target).attr('data-value-options')), function (i, item) {
                select.append($('<option>', {
                    value: item.v,
                    text : item.t
                }));
            });
            $(this).find('.values .inputs').before(new_element);
            var chosenOptions = {
                allow_single_deselect: true,
                disable_search_threshold: 10,
                width: '100%',
                include_group_label_in_selected: true,
                placeholder_text_single: $(ev.target).attr('data-placeholder')
            };
            select.chosen(chosenOptions);

            index = parseInt(index) + 1;
            $(ev.target.parentElement).data('next-index', index);
        }

        if ($(ev.target).hasClass('add-value-uri')) {
            new_element = $('#correct_uri_template > .value').clone();
            term = $(ev.target.parentElement).data('next-term');
            index = $(ev.target.parentElement).data('next-index');
            name = term + '[' + index + '][@uri]';
            namel = term + '[' + index + '][@label]';

            $(new_element).find('input').first().attr('name', name);
            $(new_element).find('input').removeAttr('readonly');
            $(new_element).find('textarea').first().attr('name', namel);
            $(new_element).find('textarea').removeAttr('readonly');
            $(new_element).removeAttr('id');
            $(new_element).css('display', 'flex');
            $(this).find('.values .inputs').before(new_element);

            index = parseInt(index) + 1;
            $(ev.target.parentElement).data('next-index', index);
        }

        if ($(ev.target).hasClass('add-value-value-suggest')) {
            new_element = $('#correct_valuesuggest_template > .value').clone();
            term = $(ev.target.parentElement).data('next-term');
            index = $(ev.target.parentElement).data('next-index');
            name = term + '[' + index + '][@uri]';

            $(new_element).find('input').first().attr('data-data-type', $(ev.target).attr('data-type'));
            $(new_element).find('input').first().attr('name', name);
            $(new_element).find('input').removeAttr('readonly');
            $(this).find('.values .inputs').before(new_element);

            let suggestInput = $(this).find('> div:last').prev().find('.valuesuggest-input');
            valueSuggestAutocomplete(suggestInput);

            index = parseInt(index) + 1;
            $(ev.target.parentElement).data('next-index', index);
        }

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
        $('.chosen-select').chosen(chosenOptions);
    });
});
