$(document).ready(function() {
    $('#edit-resource .property').on('click', function(ev) {

        if ($(ev.target).hasClass('add-value-new')) {
            var new_element = $('#correct_value_template>.value').clone();
            var title = $(ev.target.parentElement).data('next-term');
            var key = $(ev.target.parentElement).data('next-key');
            var name = title + '[' + key + '][@value]';

            $(new_element).find('textarea').attr('name', name);
            $(new_element).find('textarea').removeAttr('readonly');
            $(new_element).find('textarea').val('');
            $(this).find('.values').append(new_element);
            key = parseInt(key) + 1;
            $(ev.target.parentElement).data('next-key', key);
        }

        if ($(ev.target).hasClass('add-value-uri')) {
            var new_element = $('#correct_uri_template>.value').clone();
            $(new_element).find('input').removeAttr('readonly');

            var title = $(ev.target.parentElement).data('next-term');
            var key = $(ev.target.parentElement).data('next-key');
            $(new_element).find('input').each(function(index, obj) {
                var name = title + '[' + key + '][@uri]';
                $(obj).attr('name', name);
            })
            $(new_element).find('textarea').each(function(index, obj) {
                var name = title + '[' + key + '][@label]';
                $(obj).attr('name', name);
            })
            $(new_element).removeAttr('id');
            $(new_element).css('display', 'flex');
            $(this).find('.values').append(new_element);
            key = parseInt(key) + 1;
            $(ev.target.parentElement).data('next-key', key);
        }

    });
});
