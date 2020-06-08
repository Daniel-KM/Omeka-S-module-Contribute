$(document).ready(function() {
    $('#edit-resource .inputs').on('click', '.add-value', function(ev) {
        ev.stopPropagation();

        var target = $(ev.target);
        if (!target.is('.add-value-new, .add-value-resource, .add-value-uri, .add-value-value-suggest')) {
            return;
        }

        var selector = target.closest('.default-selector');
        var term = selector.data('next-term');
        var index = selector.data('next-index');
        var inputs = target.closest('.property').find('.values').first();
        var newElement, name, namel;

        if (target.hasClass('add-value-new')) {
            newElement = $('#edit_value_template > .value').clone();
            name = term + '[' + index + '][@value]';
            $(newElement).find('textarea')
                .attr('name', name)
                .removeAttr('readonly')
                .val('');
        }

        if (target.hasClass('add-value-resource')) {
            newElement = $('#edit_resource_template > .value').clone();
            name = term + '[' + index + '][@resource]';
            let select = $(newElement).find('select');
            select
                .attr('name', name)
                .removeAttr('readonly')
                .addClass('chosen-select')
                .val('');
            $.each(JSON.parse(target.attr('data-value-options')), function (i, item) {
                select.append($('<option>', {
                    value: item.v,
                    text : item.t
                }));
            });
            let chosenOptions = {
                allow_single_deselect: true,
                disable_search_threshold: 10,
                width: '100%',
                include_group_label_in_selected: true,
                placeholder_text_single: target.attr('data-placeholder'),
            };
            select.chosen(chosenOptions);
        }

        if (target.hasClass('add-value-uri')) {
            newElement = $('#edit_uri_template > .value').clone();
            name = term + '[' + index + '][@uri]';
            namel = term + '[' + index + '][@label]';
            $(newElement).find('input').first().attr('name', name);
            $(newElement).find('input').removeAttr('readonly');
            $(newElement).find('textarea').first().attr('name', namel);
            $(newElement).find('textarea').removeAttr('readonly');
        }

        if (target.hasClass('add-value-value-suggest')) {
            newElement = $('#edit_valuesuggest_template > .value').clone();
            name = term + '[' + index + '][@uri]';
            $(newElement).find('input').first().attr('data-data-type', target.attr('data-type'));
            $(newElement).find('input').first().attr('name', name);
            $(newElement).find('input').removeAttr('readonly');
            valueSuggestAutocomplete($(newElement).find('input').first());
        }

        inputs.append(newElement);
        index = parseInt(index) + 1;
        selector.data('next-index', index);
    });

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
