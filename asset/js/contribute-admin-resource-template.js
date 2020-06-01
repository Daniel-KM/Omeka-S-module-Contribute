
$(document).ready(function() {

    var contributionCorrigiblePartInput = function(propertyId, contributionPart) {
        contributionPart = contributionPart || '0';
        return `
        <input class="contribution-corrigible-part" type="hidden" name="o:resource_template_property[${propertyId}][data][contribution_corrigible_part]" value="${contributionPart}">
        `;
    }

    var contributionFillablePartInput = function(propertyId, contributionPart) {
        contributionPart = contributionPart || '0';
        return `
        <input class="contribution-fillable-part" type="hidden" name="o:resource_template_property[${propertyId}][data][contribution_fillable_part]" value="${contributionPart}">
        `;
    }

    var contributionPartForm = function(contributionCorrigiblePart, contributionFillPart) {
        var checked_1 = (contributionCorrigiblePart === 'oc:hasCorrigible') ? 'checked="checked" ' : '';
        var checked_2 = (contributionFillPart === 'oc:hasFillable') ? 'checked="checked" ' : '';
        return `
            <div class="field" id="contribution-options">
                <h3>` + Omeka.jsTranslate('Contribute options') + `</h3>
                <div class="option">
                    <label for="contribution-corrigible-part">
                        ` + Omeka.jsTranslate('Corrigible') + `
                        <input id="contribution-corrigible-part" type="checkbox" ${checked_1}>
                    </label>
                </div>
                <div class="option">
                    <label for="contribution-fillable-part">
                        ` + Omeka.jsTranslate('Fillable') + `
                        <input id="contribution-fillable-part" type="checkbox" ${checked_2}>
                    </label>
                </div>
            </div>
        `;
    }

    var propertyList = $('#resourcetemplateform #properties');

    var resourceClassTerm = function(termId) {
        return termId
            ? $('#resourcetemplateform select[name="o:resource_class[o:id]"] option[value=' + termId + ']').data('term')
            : null;
    }

    // Initialization during load.
    if (resourceClassTerm($('#resourcetemplateform select[name="o:resource_class[o:id]"]').val()) != '') {
        // Set hidden params inside the form for each properties of  the resource template.
        var baseUrl = '';
        var addNewPropertyRowUrl;
        var resourceTemplateId = 0;
        if (propertyList.length == 0){
            addNewPropertyRowUrl = location.href;
        } else {
            addNewPropertyRowUrl = propertyList.data('addNewPropertyRowUrl');
        }
        baseUrl = addNewPropertyRowUrl.split('?')[0];
        resourceTemplateId = baseUrl.split('/')[baseUrl.split('/').length - 2];

        if (parseInt(resourceTemplateId) > 0){
            baseUrl = baseUrl.substring(0, baseUrl.lastIndexOf('/'));
            baseUrl = baseUrl.substring(0, baseUrl.lastIndexOf('/'));
            baseUrl = baseUrl.substring(0, baseUrl.lastIndexOf('/'));
        } else {
            resourceTemplateId = baseUrl.split('/')[baseUrl.split('/').length - 1];
            baseUrl = baseUrl.substring(0, baseUrl.lastIndexOf('/'));
            baseUrl = baseUrl.substring(0, baseUrl.lastIndexOf('/'));
        }

        if ($('#resourcetemplateform #properties').length > 0 || $('#content #properties').length > 0)
        var resourceTemplateDataUrl = baseUrl + '/contribution/resource-template-data';
        $.get(resourceTemplateDataUrl, {resource_template_id: resourceTemplateId})
            .done(function(data) {
                propertyList.find('li.property').each(function() {
                    var propertyId = $(this).data('property-id');
                    var contributionPart = data['corrigible'][propertyId] || '';
                    if (contributionPart == '') {
                        $(this).find('.data-type').after(contributionCorrigiblePartInput(propertyId, 0));
                    } else {
                        $(this).find('.data-type').after(contributionCorrigiblePartInput(propertyId, 1));
                    }
                    var contributionPart = data['fillable'][propertyId] || '';
                    if (contributionPart == '') {
                        $(this).find('.data-type').after(contributionFillablePartInput(propertyId, 0));
                    } else {
                        $(this).find('.data-type').after(contributionFillablePartInput(propertyId, 1));
                    }
                });

                var table = $('#content #properties');
                table.find('thead tr').each(function(){
                    var corrigible = "<th>Corrigible?</th>";
                    var fillable = "<th>Fillable?</th>";
                    $(this).append(corrigible,fillable);
                });
                table.find('tbody tr').each(function(){

                    var propertyId = $(this).attr('data-property-id');
                    var contributionPart = data['corrigible'][propertyId] || '';

                    var corrigible = '';
                    if (contributionPart == ''){
                        corrigible = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">No</span></td>';
                    } else {
                        corrigible = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">Yes</span></td>';
                    }

                    var contributionPart = data['fillable'][propertyId] || '';
                    var fillable = '';
                    if (contributionPart == ''){
                        fillable = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">No</span></td>';
                    } else {
                        fillable = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">Yes</span></td>';
                    }

                    $(this).append(corrigible, fillable);
                });
            });

        // Initialization of the sidebar.
        $('#edit-sidebar .confirm-main').append(contributionPartForm());
    }

    // Add property row via the property selector.
    $('#property-selector .selector-child').click(function(e) {
        e.preventDefault();
        var propertyId = $(this).closest('li').data('property-id');
        if ($('#properties li[data-property-id="' + propertyId + '"]').length) {
            // Resource templates cannot be assigned duplicate properties.
            return;
        }
        propertyList.find('li:last-child').append(contributionCorrigiblePartInput(propertyId));
        propertyList.find('li:last-child').append(contributionFillablePartInput(propertyId));
    });

    propertyList.on('click', '.property-edit', function(e) {
        e.preventDefault();
        var prop = $(this).closest('.property');
        var contributionCorrigible = prop.find('.contribution-corrigible-part');
        var contributionCorrigibleVal = contributionCorrigible.val()|| 'oc:ContributeCorrigible';
        var contributionFillable = prop.find('.contribution-fillable-part');
        var contributionFillableVal = contributionFillable.val()|| 'oc:ContributeFillable';

        if (contributionCorrigibleVal == 1) {
            $('#contribution-corrigible-part').prop('checked', true);
        } else {
            $('#contribution-corrigible-part').prop('checked', false);
        }
        if (contributionFillableVal == 1) {
            $('#contribution-fillable-part').prop('checked', true);
        } else {
            $('#contribution-fillable-part').prop('checked', false);
        }

        $('#set-changes').on('click.setchanges', function(e) {
            contributionCorrigible.val($('#contribution-corrigible-part').prop('checked')?'1':'0');
            contributionFillable.val($('#contribution-fillable-part').prop('checked')?'1':'0');
        });
    });

});
