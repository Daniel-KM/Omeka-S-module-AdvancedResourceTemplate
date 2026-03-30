$(document).ready(function() {

var propertyList = $('#properties');
var titleProperty = $('#title-property-id');
var descriptionProperty = $('#description-property-id');

var titlePropertyTemplate = $('<span class="title-property-cell">' + Omeka.jsTranslate('Title') + '</span>');
var descriptionPropertyTemplate = $('<span class="description-property-cell">' + Omeka.jsTranslate('Description') + '</span>');

// Improve the display of the main advanced settings.
$('.resource-templates.edit fieldset.settings > div.field').wrapAll('<div class="collapsible" style="overflow: inherit;"/>');
$('.resource-templates.edit fieldset.settings legend').wrapAll(`<a href="#" class="expand" aria-label="${Omeka.jsTranslate('Expand')}"></a>`);

// Mark the title and description properties.
$('#properties li[data-property-id="' + titleProperty.val() + '"] .actions').before(titlePropertyTemplate);
$('#properties li[data-property-id="' + descriptionProperty.val() + '"] .actions').before(descriptionPropertyTemplate);

// Enable sorting on property rows.
new Sortable(propertyList[0], {
    draggable: ".property",
    handle: ".sortable-handle"
});

// Add property row via the property selector.
$('#property-selector .selector-child').click(function(e) {
    e.preventDefault();
    var propertyId = $(this).closest('li').data('property-id');
    // Resource templates cannot be assigned duplicate properties.
    // if ($('#properties li[data-property-id="' + propertyId + '"]').length) {
    //     return;
    // }
    $.get(propertyList.data('addNewPropertyRowUrl'), {property_id: propertyId})
        .done(function(data) {
            // Check if the property is the template title or description.
            propertyList.append(data);
            if (propertyId == titleProperty.val()) {
                $('.title-property-cell').remove();
                $('#properties .property[data-property-id=' + propertyId + ']').find('.actions').before(titlePropertyTemplate);
            }
            if (propertyId == descriptionProperty.val()) {
                $('.description-property-cell').remove();
                $('#properties .property[data-property-id=' + propertyId + ']').find('.actions').before(descriptionPropertyTemplate);
            }
        });
});

propertyList.on('click', '.property-remove', function(e) {
    e.preventDefault();
    var thisButton = $(this);
    var prop = thisButton.closest('.property');
    prop.find(':input').prop('disabled', true);
    prop.addClass('delete');
    prop.find('.property-restore').show().focus();
    thisButton.hide();
});

propertyList.on('click', '.property-restore', function(e) {
    e.preventDefault();
    var thisButton = $(this);
    var prop = thisButton.closest('.property');
    prop.find(':input').prop('disabled', false);
    prop.removeClass('delete');
    prop.find('.property-remove').show().focus();
    thisButton.hide();
});

propertyList.on('click', '.property-edit', function(e) {
    e.preventDefault();
    // Get values stored in the row.
    var prop = $(this).closest('.property');
    var propertyId = prop.data('property-id');
    var oriLabel = prop.find('.original-label');
    var altLabel = prop.find('[data-property-key="o:alternate_label"]');
    var oriComment = prop.find('.original-comment');
    var altComment = prop.find('[data-property-key="o:alternate_comment"]');
    var isRequired = prop.find('[data-property-key="o:is_required"]');
    var isPrivate = prop.find('[data-property-key="o:is_private"]');
    var defaultLang = prop.find('.default-lang');
    var dataTypes = prop.find('[data-property-key="o:data_type"]');
    var data = {};
    prop.find('[data-setting-key]').each(function(index, hiddenElement) {
        data[index] = $(hiddenElement);
    });
    var vocabularyLabel = $('#property-selector > ul > li > ul > li[data-property-id=' + propertyId + ']').closest('li[data-vocabulary-id]').find('> span:first-of-type').text();
    var propertyTerm = $('#property-selector > ul > li > ul > li[data-property-id=' + propertyId + ']').data('property-term');

    // Copy values into the sidebar.
    $('#edit-sidebar #vocabulary-label').text(vocabularyLabel);
    $('#edit-sidebar #property-term').text(propertyTerm);
    $('#edit-sidebar #original-label').text(oriLabel.val());
    $('#edit-sidebar #alternate-label').val(altLabel.val());
    $('#edit-sidebar #original-comment').text(oriComment.val());
    $('#edit-sidebar #alternate-comment').val(altComment.val());
    $('#edit-sidebar #is-title-property').prop('checked', propertyId == titleProperty.val());
    $('#edit-sidebar #is-description-property').prop('checked', propertyId == descriptionProperty.val());
    $('#edit-sidebar #is-required').prop('checked', isRequired.prop('checked'));
    $('#edit-sidebar #is-private').prop('checked', isPrivate.prop('checked'));
    $('#edit-sidebar #default-lang').val(defaultLang.val());
    $('#edit-sidebar #data-type').val(dataTypes.val());
    $('#edit-sidebar #data-type').trigger('chosen:updated');
    $.each(data, function(index, hiddenElement) {
        var dataKey = hiddenElement.data('setting-key');
        var sidebarElement = $('#edit-sidebar [data-setting-key="' +  dataKey + '"]');
        var sidebarElementType = sidebarElement.prop('type') ? sidebarElement.prop('type') : sidebarElement.prop('nodeName').toLowerCase();
        if (sidebarElementType === 'checkbox') {
            sidebarElement.prop('checked', hiddenElement.prop('checked'));
        } else if (sidebarElementType === 'radio') {
            $('#edit-sidebar [data-setting-key="' + hiddenElement.data('setting-key') + '"]')
                .val([prop.find('[data-setting-key="' + hiddenElement.data('setting-key') + '"]:checked').val()]);
        } else if (sidebarElementType === 'select'
            || sidebarElementType === 'select-one'
            || sidebarElementType === 'select-multiple'
        ) {
            sidebarElement.val(hiddenElement.val());
            sidebarElement.trigger('chosen:updated');
        } else { // Text, textarea, number…
            sidebarElement.val(hiddenElement.val());
        }
    });

    // When the sidebar fieldset is applied, store new values in the row.
    $('#set-changes').off('click').on('click', function(e) {
        if (!$('#default-lang').trigger('change')[0].reportValidity()) {
            // The default language is invalid.
            return false;
        }
        altLabel.val($('#edit-sidebar #alternate-label').val());
        prop.find('.alternate-label-cell').text($('#edit-sidebar #alternate-label').val());
        $('#edit-sidebar #is-private').prop('checked')
            ? prop.find('.visibility').removeClass('o-icon-public').addClass('o-icon-private').prop('aria-label', Omeka.jsTranslate('Private'))
            : prop.find('.visibility').removeClass('o-icon-private').addClass('o-icon-public').prop('aria-label', Omeka.jsTranslate('Public'));
        altComment.val($('#edit-sidebar #alternate-comment').val());
        if ($('#edit-sidebar #is-title-property').prop('checked')) {
            titleProperty.val(propertyId);
            $('.title-property-cell').remove();
            prop.find('.actions').before(titlePropertyTemplate);
        } else if (propertyId == titleProperty.val()) {
            titleProperty.val(null);
            $('.title-property-cell').remove();
        }
        if ($('#edit-sidebar #is-description-property').prop('checked')) {
            descriptionProperty.val(propertyId);
            $('.description-property-cell').remove();
            prop.find('.actions').before(descriptionPropertyTemplate);
        } else if (propertyId == descriptionProperty.val()) {
            descriptionProperty.val(null);
            $('.description-property-cell').remove();
        }
        isRequired.prop('checked', $('#edit-sidebar #is-required').prop('checked'));
        isPrivate.prop('checked', $('#edit-sidebar #is-private').prop('checked'));
        defaultLang.val($('#edit-sidebar #default-lang').val());
        dataTypes.val($('#edit-sidebar #data-type').val());
        // New fields are not yet stored in the row.
        $('#edit-sidebar [data-setting-key]').each(function(index, sidebarElement) {
            sidebarElement = $(sidebarElement);
            var sidebarElementType = sidebarElement.prop('type') ? sidebarElement.prop('type') : sidebarElement.prop('nodeName').toLowerCase();
            var hiddenElement = prop.find('[data-setting-key="' + sidebarElement.data('setting-key') + '"]');
            if (sidebarElementType === 'checkbox') {
                hiddenElement.prop('checked', sidebarElement.prop('checked'));
            } else if (sidebarElementType === 'radio') {
                prop.find('[data-setting-key="' + sidebarElement.data('setting-key') + '"]')
                    .val([$('#edit-sidebar [data-setting-key="' + sidebarElement.data('setting-key') + '"]:checked').val()]);
            } else {
                hiddenElement.val(sidebarElement.val());
            }
        });
        Omeka.closeSidebar($('#edit-sidebar'));
    });

    Omeka.openSidebar($('#edit-sidebar'));
});

// Append template property settings to the Details sidebar.
var detailsSidebar = $('#details');
var detailsPropertyRow = null;

propertyList.on('click', '.o-icon-more.sidebar-content', function() {
    detailsPropertyRow = $(this).closest('.property.row');
});

detailsSidebar.on('o:sidebar-content-loaded', function() {
    if (!detailsPropertyRow) {
        return;
    }

    var fieldset = detailsPropertyRow.find('.row-fieldset');
    var items = [];

    // Data types.
    var typeValues = fieldset.find('[name="o\\:data_type\\[\\]"]').val()
        || fieldset.find('select[name$="[o\\:data_type][]"]').val();
    if (!typeValues) {
        var vals = [];
        fieldset.find('input[name$="[o\\:data_type][]"], select[name$="[o\\:data_type][]"] option:selected').each(function() {
            var v = $(this).val();
            if (v) vals.push(v);
        });
        typeValues = vals;
    }
    if (typeValues && typeValues.length) {
        var dataTypeSelect = $('#edit-sidebar #data-type');
        var labels = [];
        $.each(typeValues, function(i, val) {
            var optionText = dataTypeSelect.find('option[value="' + val + '"]').text();
            labels.push(optionText || val);
        });
        items.push({
            label: Omeka.jsTranslate('Data types'),
            value: labels.join(', ')
        });
    }

    // Required.
    if (fieldset.find('[data-property-key="o\\:is_required"]').prop('checked')) {
        items.push({
            label: Omeka.jsTranslate('Required'),
            value: Omeka.jsTranslate('Yes')
        });
    }

    // Private.
    if (fieldset.find('[data-property-key="o\\:is_private"]').prop('checked')) {
        items.push({
            label: Omeka.jsTranslate('Private'),
            value: Omeka.jsTranslate('Yes')
        });
    }

    // Default language.
    var defaultLang = fieldset.find('[data-property-key="o\\:default_lang"]').val();
    if (defaultLang) {
        items.push({
            label: Omeka.jsTranslate('Default language'),
            value: defaultLang
        });
    }

    // Other settings (data-setting-key): same filter as
    // show view "Other settings" column: skip empty, "0",
    // and false values.
    fieldset.find('[data-setting-key]').each(function() {
        var el = $(this);
        var key = el.data('setting-key');
        var type = el.prop('type') || el.prop('nodeName').toLowerCase();
        var val;
        if (type === 'checkbox') {
            if (!el.prop('checked')) return;
            val = Omeka.jsTranslate('Yes');
        } else if (type === 'radio') {
            val = fieldset.find('[data-setting-key="' + key + '"]:checked').val();
            if (!val || val === '0') return;
        } else if (type === 'select-multiple') {
            var selected = el.find('option:selected');
            if (!selected.length) return;
            val = selected.map(function() {
                return $(this).text();
            }).get().join(', ');
        } else {
            val = el.val();
            if (!val || val === '0') return;
        }
        items.push({
            label: key.replace(/_/g, ' '),
            value: val
        });
    });

    if (!items.length) {
        return;
    }

    var html = '<div class="meta-group art-details-settings">'
        + '<h4>' + Omeka.jsTranslate('Template settings') + '</h4>'
        + '<dl class="meta-group">';
    $.each(items, function(i, item) {
        html += '<dt>' + item.label + '</dt>'
            + '<dd>' + item.value + '</dd>';
    });
    html += '</dl></div>';

    detailsSidebar.find('.sidebar-content').append(html);
});

$('#resource-template-form').on('submit', function () {
    // Prepend the full form data first to allow to pass more than 1000 variables
    // (default on servers).
    // Don't use Object.fromEntries() directly: it skips values when there
    // are multiple entries, for example for multi-select.
    const formData = new FormData(document.getElementById('resource-template-form'));
    var post = {};
    var remainingKey;
    for (const [key, value] of formData.entries()) {
        if (!key.includes('[')) {
            post[key] = value;
            continue;
        }
        // First key is always a string. For php, everything is object and order
        // should be kept, so prepend a "_" when the key is numeric, because the
        //  array is not ordered in js.
        // TODO Use a recursive method to convert post key with brackets into nested array. Four levels are enough in real world.
        const mainKey = key.slice(0, key.indexOf('['));
        remainingKey = key.slice(key.indexOf('['));
        if (!post.hasOwnProperty(mainKey)) {
            post[mainKey] = remainingKey === '[]' ? [] : {};
        }
        var key1 = key.slice(key.indexOf('[') + 1, key.indexOf(']'));
        if (key1 === '') {
            if (!Array.isArray(post[mainKey])) {
                post[mainKey] = [];
            }
            post[mainKey].push(value);
        } else {
            if (key1.match(/^\d+$/)) {
                key1 = '_' + key1;
            }
            remainingKey = key.slice(key.indexOf(']') + 1);
            if (remainingKey === '') {
                post[mainKey][key1] = value;
            } else {
                if (!post[mainKey].hasOwnProperty(key1)) {
                    post[mainKey][key1] = remainingKey === '[]' ? [] : {};
                }
                var key2 = remainingKey.slice(remainingKey.indexOf('[') + 1, remainingKey.indexOf(']'));
                if (key2 === '') {
                    if (!Array.isArray(post[mainKey][key1])) {
                        post[mainKey][key1] = [];
                    }
                    post[mainKey][key1].push(value);
                } else {
                    if (key2.match(/^\d+$/)) {
                        key2 = '_' + key2;
                    }
                    remainingKey = remainingKey.slice(remainingKey.indexOf(']') + 1);
                    if (remainingKey === '') {
                        post[mainKey][key1][key2] = value;
                    } else {
                        if (!post[mainKey][key1].hasOwnProperty(key2)) {
                            post[mainKey][key1][key2] = remainingKey === '[]' ? [] : {};
                        }
                        var key3 = remainingKey.slice(remainingKey.indexOf('[') + 1, remainingKey.indexOf(']'));
                        if (key3 === '') {
                            if (!Array.isArray(post[mainKey][key1][key2])) {
                                post[mainKey][key1][key2] = [];
                            }
                            post[mainKey][key1][key2].push(value);
                        } else {
                            if (key3.match(/^\d+$/)) {
                                key3 = '_' + key3;
                            }
                            remainingKey = remainingKey.slice(remainingKey.indexOf(']') + 1);
                            if (remainingKey === '') {
                                post[mainKey][key1][key2][key3] = value;
                            } else {
                                if (!post[mainKey][key1][key2].hasOwnProperty(key3)) {
                                    post[mainKey][key1][key2][key3] = remainingKey === '[]' ? [] : {};
                                }
                                var key4 = remainingKey.slice(remainingKey.indexOf('[') + 1, remainingKey.indexOf(']'));
                                if (key4 === '') {
                                    if (!Array.isArray(post[mainKey][key1][key2][key3])) {
                                        post[mainKey][key1][key2][key3] = [];
                                    }
                                    post[mainKey][key1][key2][key3].push(value);
                                } else {
                                    if (key4.match(/^\d+$/)) {
                                        key4 = '_' + key4;
                                    }
                                    remainingKey = remainingKey.slice(remainingKey.indexOf(']') + 1);
                                    if (remainingKey === '') {
                                        post[mainKey][key1][key2][key3][key4] = value;
                                    } else {
                                        post[mainKey][key1][key2][key3][key4][remainingKey] = value;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    $('#resource-template-form').prepend($('<input>', {
        type: 'hidden',
        name: '_post',
        value: JSON.stringify(post),
    }));
});

});
