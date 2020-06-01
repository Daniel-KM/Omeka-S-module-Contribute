
$(document).ready(function() {

    var contributeCorrigiblePartInput = function(propertyId, contributePart) {
        contributePart = contributePart || '0';
        return `
        <input class="contribute-corrigible-part" type="hidden" name="o:resource_template_property[${propertyId}][data][contribute_corrigible_part]" value="${contributePart}">
        `;
    }

    var contributeFillablePartInput = function(propertyId, contributePart) {
        contributePart = contributePart || '0';
        return `
        <input class="contribute-fillable-part" type="hidden" name="o:resource_template_property[${propertyId}][data][contribute_fillable_part]" value="${contributePart}">
        `;
    }

    var contributePartForm = function(contributeCorrigiblePart, contributeFillPart) {
        var checked_1 = (contributeCorrigiblePart === 'oc:hasCorrigible') ? 'checked="checked" ' : '';
        var checked_2 = (contributeFillPart === 'oc:hasFillable') ? 'checked="checked" ' : '';
        return `
            <div class="field" id="contribute-options">
                <h3>` + Omeka.jsTranslate('Contribute options') + `</h3>
                <div class="option">
                    <label for="contribute-corrigible-part">
                        ` + Omeka.jsTranslate('Corrigible') + `
                        <input id="contribute-corrigible-part" type="checkbox" ${checked_1}>
                    </label>
                </div>
                <div class="option">
                    <label for="contribute-fillable-part">
                        ` + Omeka.jsTranslate('Fillable') + `
                        <input id="contribute-fillable-part" type="checkbox" ${checked_2}>
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
                    var contributePart = data['corrigible'][propertyId] || '';
                    if (contributePart == '') {
                        $(this).find('.data-type').after(contributeCorrigiblePartInput(propertyId, 0));
                    } else {
                        $(this).find('.data-type').after(contributeCorrigiblePartInput(propertyId, 1));
                    }
                    var contributePart = data['fillable'][propertyId] || '';
                    if (contributePart == '') {
                        $(this).find('.data-type').after(contributeFillablePartInput(propertyId, 0));
                    } else {
                        $(this).find('.data-type').after(contributeFillablePartInput(propertyId, 1));
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
                    var contributePart = data['corrigible'][propertyId] || '';

                    var corrigible = '';
                    if (contributePart == ''){
                        corrigible = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">No</span></td>';
                    } else {
                        corrigible = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">Yes</span></td>';
                    }

                    var contributePart = data['fillable'][propertyId] || '';
                    var fillable = '';
                    if (contributePart == ''){
                        fillable = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">No</span></td>';
                    } else {
                        fillable = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">Yes</span></td>';
                    }

                    $(this).append(corrigible, fillable);
                });
            });

        // Initialization of the sidebar.
        $('#edit-sidebar .confirm-main').append(contributePartForm());
    }

    // Add property row via the property selector.
    $('#property-selector .selector-child').click(function(e) {
        e.preventDefault();
        var propertyId = $(this).closest('li').data('property-id');
        if ($('#properties li[data-property-id="' + propertyId + '"]').length) {
            // Resource templates cannot be assigned duplicate properties.
            return;
        }
        propertyList.find('li:last-child').append(contributeCorrigiblePartInput(propertyId));
        propertyList.find('li:last-child').append(contributeFillablePartInput(propertyId));
    });

    propertyList.on('click', '.property-edit', function(e) {
        e.preventDefault();
        var prop = $(this).closest('.property');
        var contributeCorrigible = prop.find('.contribute-corrigible-part');
        var contributeCorrigibleVal = contributeCorrigible.val()|| 'oc:ContributeCorrigible';
        var contributeFillable = prop.find('.contribute-fillable-part');
        var contributeFillableVal = contributeFillable.val()|| 'oc:ContributeFillable';

        if (contributeCorrigibleVal == 1) {
            $('#contribute-corrigible-part').prop('checked', true);
        } else {
            $('#contribute-corrigible-part').prop('checked', false);
        }
        if (contributeFillableVal == 1) {
            $('#contribute-fillable-part').prop('checked', true);
        } else {
            $('#contribute-fillable-part').prop('checked', false);
        }

        $('#set-changes').on('click.setchanges', function(e) {
            contributeCorrigible.val($('#contribute-corrigible-part').prop('checked')?'1':'0');
            contributeFillable.val($('#contribute-fillable-part').prop('checked')?'1':'0');
        });
    });

});
