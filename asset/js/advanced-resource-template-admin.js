$(document).ready(function() {

$(document).on('o:prepare-value', function(e, dataType, value, valueObj, term) {
    var data;
    var field = value.closest('.field');
    if (!term && field.length) {
        term = field.data('property-term');
    } else if (!field.length && term) {
        field = $('#properties [data-property-term="' + term + '"].field');
    }
    data = field.data('settings');
    if (!data) {
        return;
    }

    // Manage default value.
    // @see resource-form.js, same hook (but this one comes after).
    if (data.default_value && data.default_value.length
        // The value from the object is already managed.
        && !valueObj
        // Don't add a value if this is an edition.
        && $('body').hasClass('add')
        // Don't add a value if there is already a value.
        && field.find('.input-value').length === 0
        && field.find('input.value').length === 0
        && field.find('input.value.to-require').length === 0
    ) {
        if (dataType.startsWith('resource')) {
            if (/^\d+$/.test(data.default_value)) {
                value.find('input.value[data-value-key="value_resource_id"]').val(data.default_value);
                // TODO Get the title from the api (when authentication will be opened).
                value.find('span.default').hide();
                var resource = value.find('.selected-resource');
                valueObj = {
                    display_title: Omeka.jsTranslate('Resource') + ' #' + data.default_value,
                    value_resource_name: 'resource',
                    url: '#',
                };
                resource.find('.o-title')
                    .removeClass() // remove all classes
                    .addClass('o-title ' + valueObj['value_resource_name'])
                    .html($('<a>', {href: valueObj['url'], text: valueObj['display_title']}));
                if (typeof valueObj['thumbnail_url'] !== 'undefined') {
                    resource.find('.o-title')
                        .prepend($('<img>', {src: valueObj['thumbnail_url']}));
                }
            }
        } else {
            value.find('.input-value').val(data.default_value);
        }
    }
});

});
