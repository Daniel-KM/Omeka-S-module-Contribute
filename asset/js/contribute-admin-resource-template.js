
$(document).ready(function() {

    var contributionEditablePartInput = function(propertyId, contributionPart) {
        contributionPart = contributionPart || '0';
        return `
        <input class="contribution-editable-part" type="hidden" name="o:resource_template_property[${propertyId}][data][contribution_editable_part]" value="${contributionPart}">
        `;
    }

    var contributionFillablePartInput = function(propertyId, contributionPart) {
        contributionPart = contributionPart || '0';
        return `
        <input class="contribution-fillable-part" type="hidden" name="o:resource_template_property[${propertyId}][data][contribution_fillable_part]" value="${contributionPart}">
        `;
    }

    var contributionPartForm = function(contributionEditablePart, contributionFillPart) {
        var checked_1 = (contributionEditablePart === 'oc:hasEditable') ? 'checked="checked" ' : '';
        var checked_2 = (contributionFillPart === 'oc:hasFillable') ? 'checked="checked" ' : '';
        return `
            <div class="field" id="contribution-options">
                <h3>` + Omeka.jsTranslate('Contribute options') + `</h3>
                <div class="option">
                    <label for="contribution-editable-part">
                        ` + Omeka.jsTranslate('Editable') + `
                        <input id="contribution-editable-part" type="checkbox" ${checked_1}>
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
        var resourceTemplateDataUrl = baseUrl + '/contribute/resource-template-data';
        $.get(resourceTemplateDataUrl, {resource_template_id: resourceTemplateId})
            .done(function(data) {
                propertyList.find('li.property').each(function() {
                    var propertyId = $(this).data('property-id');
                    var contributionPart = data['editable'][propertyId] || '';
                    if (contributionPart == '') {
                        $(this).find('.data-type').after(contributionEditablePartInput(propertyId, 0));
                    } else {
                        $(this).find('.data-type').after(contributionEditablePartInput(propertyId, 1));
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
                    var editable = "<th>Editable?</th>";
                    var fillable = "<th>Fillable?</th>";
                    $(this).append(editable,fillable);
                });
                table.find('tbody tr').each(function(){

                    var propertyId = $(this).attr('data-property-id');
                    var contributionPart = data['editable'][propertyId] || '';

                    var editable = '';
                    if (contributionPart == ''){
                        editable = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">No</span></td>';
                    } else {
                        editable = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">Yes</span></td>';
                    }

                    var contributionPart = data['fillable'][propertyId] || '';
                    var fillable = '';
                    if (contributionPart == ''){
                        fillable = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">No</span></td>';
                    } else {
                        fillable = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">Yes</span></td>';
                    }

                    $(this).append(editable, fillable);
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
        propertyList.find('li:last-child').append(contributionEditablePartInput(propertyId));
        propertyList.find('li:last-child').append(contributionFillablePartInput(propertyId));
    });

    propertyList.on('click', '.property-edit', function(e) {
        e.preventDefault();
        var prop = $(this).closest('.property');
        var contributionEditable = prop.find('.contribution-editable-part');
        var contributionEditableVal = contributionEditable.val()|| 'oc:ContributeEditable';
        var contributionFillable = prop.find('.contribution-fillable-part');
        var contributionFillableVal = contributionFillable.val()|| 'oc:ContributeFillable';

        if (contributionEditableVal == 1) {
            $('#contribution-editable-part').prop('checked', true);
        } else {
            $('#contribution-editable-part').prop('checked', false);
        }
        if (contributionFillableVal == 1) {
            $('#contribution-fillable-part').prop('checked', true);
        } else {
            $('#contribution-fillable-part').prop('checked', false);
        }

        $('#set-changes').on('click.setchanges', function(e) {
            contributionEditable.val($('#contribution-editable-part').prop('checked')?'1':'0');
            contributionFillable.val($('#contribution-fillable-part').prop('checked')?'1':'0');
        });
    });

});
