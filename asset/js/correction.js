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
    });
});
