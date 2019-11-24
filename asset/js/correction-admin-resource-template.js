
$(document).ready(function() {

    var correctionCorrigiblePartInput = function(propertyId, correctionPart) {
        correctionPart = correctionPart || '0';
        return `
        <input class="correction-corrigible-part" type="hidden" name="o:resource_template_property[${propertyId}][data][correction_corrigible_part]" value="${correctionPart}">
        `;
    }

    var correctionFillablePartInput = function(propertyId, correctionPart) {
        correctionPart = correctionPart || '0';
        return `
        <input class="correction-fillable-part" type="hidden" name="o:resource_template_property[${propertyId}][data][correction_fillable_part]" value="${correctionPart}">
        `;
    }

    var correctionPartForm = function(correctionCorrigiblePart, correctionFillPart) {
        var checked_1 = (correctionCorrigiblePart === 'oc:hasCorrigible') ? 'checked="checked" ' : '';
        var checked_2 = (correctionFillPart === 'oc:hasFillable') ? 'checked="checked" ' : '';
        return `
            <div class="field" id="correction-options">
                <h3>` + Omeka.jsTranslate('Correction options') + `</h3>
                <div class="option">
                    <label for="correction-corrigible-part">
                        ` + Omeka.jsTranslate('Corrigible') + `
                        <input id="correction-corrigible-part" type="checkbox" ${checked_1}>
                    </label>
                </div>
                <div class="option">
                    <label for="correction-fillable-part">
                        ` + Omeka.jsTranslate('Fillable') + `
                        <input id="correction-fillable-part" type="checkbox" ${checked_2}>
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
        var resourceTemplateDataUrl = baseUrl + '/correction/resource-template-data';
        $.get(resourceTemplateDataUrl, {resource_template_id: resourceTemplateId})
            .done(function(data) {
                propertyList.find('li.property').each(function() {
                    var propertyId = $(this).data('property-id');
                    var correctionPart = data['corrigible'][propertyId] || '';
                    if (correctionPart == '') {
                        $(this).find('.data-type').after(correctionCorrigiblePartInput(propertyId, 0));
                    } else {
                        $(this).find('.data-type').after(correctionCorrigiblePartInput(propertyId, 1));
                    }
                    var correctionPart = data['fillable'][propertyId] || '';
                    if (correctionPart == '') {
                        $(this).find('.data-type').after(correctionFillablePartInput(propertyId, 0));
                    } else {
                        $(this).find('.data-type').after(correctionFillablePartInput(propertyId, 1));
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
                    var correctionPart = data['corrigible'][propertyId] || '';

                    var corrigible = '';
                    if (correctionPart == ''){
                        corrigible = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">No</span></td>';
                    } else {
                        corrigible = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">Yes</span></td>';
                    }

                    var correctionPart = data['fillable'][propertyId] || '';
                    var fillable = '';
                    if (correctionPart == ''){
                        fillable = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">No</span></td>';
                    } else {
                        fillable = '<td><b class="tablesaw-cell-label">Required?</b><span class="tablesaw-cell-content">Yes</span></td>';
                    }

                    $(this).append(corrigible, fillable);
                });
            });

        // Initialization of the sidebar.
        $('#edit-sidebar .confirm-main').append(correctionPartForm());
    }

    // Add property row via the property selector.
    $('#property-selector .selector-child').click(function(e) {
        e.preventDefault();
        var propertyId = $(this).closest('li').data('property-id');
        if ($('#properties li[data-property-id="' + propertyId + '"]').length) {
            // Resource templates cannot be assigned duplicate properties.
            return;
        }
        propertyList.find('li:last-child').append(correctionCorrigiblePartInput(propertyId));
        propertyList.find('li:last-child').append(correctionFillablePartInput(propertyId));
    });

    propertyList.on('click', '.property-edit', function(e) {
        e.preventDefault();
        var prop = $(this).closest('.property');
        var correctionCorrigible = prop.find('.correction-corrigible-part');
        var correctionCorrigibleVal = correctionCorrigible.val()|| 'oc:CorrectionCorrigible';
        var correctionFillable = prop.find('.correction-fillable-part');
        var correctionFillableVal = correctionFillable.val()|| 'oc:CorrectionFillable';

        if (correctionCorrigibleVal == 1) {
            $('#correction-corrigible-part').prop('checked', true);
        } else {
            $('#correction-corrigible-part').prop('checked', false);
        }
        if (correctionFillableVal == 1) {
            $('#correction-fillable-part').prop('checked', true);
        } else {
            $('#correction-fillable-part').prop('checked', false);
        }

        $('#set-changes').on('click.setchanges', function(e) {
            correctionCorrigible.val($('#correction-corrigible-part').prop('checked')?'1':'0');
            correctionFillable.val($('#correction-fillable-part').prop('checked')?'1':'0');
        });
    });

});
