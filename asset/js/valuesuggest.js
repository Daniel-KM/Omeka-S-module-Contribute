/*
 * Adapted from module Value Suggest, required to use Value Suggest in public
 * side (the function should be available to all js).
 *
 * @todo Currently, language is not managed. See module valuesuggest.js.
 */

$(document).ready(function() {

    $('.valuesuggest-input').each(function() {
        var suggestInput = $(this);
        valueSuggestAutocomplete(suggestInput);
    });

});

function valueSuggestAutocomplete(suggestInput) {
    var type = suggestInput.data('data-type');
    if (type.indexOf('valuesuggest:') !== 0 && type.indexOf('valuesuggestall:') !== 0) {
        return;
    }

    // Use an hidden value to simplify submission.
    // TODO Move this hack to the prompt element with Zend validator/filter.
    var suggestHidden = $('<input>')
        .attr('type', 'hidden')
        .attr('name', suggestInput.attr('name'))
        .val('');
    suggestInput.after(suggestHidden);
    suggestInput.prop('name', '_' + suggestInput.prop('name'));

    // In case of a failed submission, the value suggest input should be prepared.
    if (suggestInput.val() !== 'undefined' && suggestInput.val() !== '') {
        suggestHidden.val(suggestInput.val());
        suggestInput.val(suggestInput.data('value'));
    }

    var allResults;

    // Build the autocomplete options.
    var options = {
        // Must disable triggerSelectOnValidInput or onSelect will be
        // triggered whether the user wants it or not. The user must
        // explicitly select the suggestion.
        triggerSelectOnValidInput: false,
        // Set contextual parameters for suggesters that may need them. For
        // example, we set "lang" so the suggester always uses the current
        // language when making a query.
        onSearchStart: function(params) {
            type = $(this).data('data-type');
            $(this).css('cursor', 'progress');
            params.type = type;
            // params.lang = languageInput.val();
            params.lang = '';
            params.property_id = $(this).closest('.property').data('property-id');
            params.property_term = $(this).closest('.property').data('term');
            params.resource_template_id = $(this).closest('[data-resource-template-id]').data('resource-template-id');
            params.resource_class_id = $(this).closest('[data-resource-template-id]').data('resource-class-id');
        },
        onSearchComplete: function(query, suggestions) {
            $(this).css('cursor', 'default');
        },
        onSearchError: function(query, jqXHR, textStatus, errorThrown) {
            // Silently handle error.
            $(this).css('cursor', 'default');
        },
        // Prepare the value when the user selects a suggestion.
        onSelect: function(suggestion) {
            // Set value as URI type
            suggestion.value = suggestion.data.label ? suggestion.data.label : suggestion.value;
            suggestInput.val(suggestion.value)
                .attr('placeholder', suggestion.value);
            suggestInput.attr('data-value', suggestion.value);
            suggestInput.val(suggestion.value);
            if (suggestion.data.uri) {
                suggestInput.attr('data-uri', suggestion.data.uri);
                var link = $('<a>')
                    .attr('href', suggestion.data.uri)
                    .attr('target', '_blank')
                    // Unlike value-suggest-admin, the value is used as text.
                    .text(suggestion.value);
                suggestHidden.val(link[0].outerHTML);
            } else {
                suggestInput.attr('data-uri', '');
                suggestHidden.val(suggestion.value);
            }
        }
    };

    // For the "valuesuggestall" type, assume the first response contains
    // all available suggestions. Do not make subsequent requests.
    if (0 === type.indexOf('valuesuggestall:')) {
        // Get suggestions immediately when input is first put into focus.
        options.minChars = 0;
        // Prepare the suggestions prior to rendering them.
        options.beforeRender = function(container, suggestions) {
            container.find('.autocomplete-suggestion').wrapInner('<div class="suggest-data"></div>');
            // Add available info to each suggestion for disambiguation.
            container.children().each(function(index) {
                if (suggestions[index].data.info) {
                    $(this).append('<div class="suggest-info"></div>')
                        .find('.suggest-info').append(suggestions[index].data.info);
                }
            });
            // Hide suggestions that contain no matches.
            var hasSuggestions = container.children(':has(strong)');
            hasSuggestions.show();
            if (hasSuggestions.length) {
                container.children().not(':has(strong)').hide();
            }
        };
        // Use custom lookup function to make only one request.
        options.lookup = function(query, done) {
            if (null == allResults) {
                $.get(valueSuggestProxyUrl, this.params, function(data) {
                    allResults = data; // cache the data
                    done(allResults);
                });
            } else {
                done(allResults);
            }
        };

    // For the "valuesuggest" type, make requests as normal.
    } else {
        options.serviceUrl = valueSuggestProxyUrl;
        options.deferRequestBy = 200;
        options.minChars = 3;
        // Must disable preventBadQueries or autocomplete will not fire on
        // queries that share a root that previously returned no results.
        options.preventBadQueries = false;
        // Prepare the suggestions prior to rendering them.
        options.beforeRender = function(container, suggestions) {
            container.find('.autocomplete-suggestion').wrapInner('<div class="suggest-data"></div>');
            // Add available info to each suggestion for disambiguation.
            container.children().each(function(index) {
                if (suggestions[index].data.info) {
                    $(this).append('<div class="suggest-info"></div>')
                        .find('.suggest-info').append(suggestions[index].data.info);
                }
            });
        };
    }

    suggestInput.autocomplete(options);
}
