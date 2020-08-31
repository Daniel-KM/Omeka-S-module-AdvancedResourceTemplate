$(document).ready(function() {

    /**
     * Manage default value.
     *
     * The default value can be a json value, a simple string, an integer, or a uri + a string.
     * @see resource-form.js, same hook (but this one comes after).
     */
    function fillDefaultValue(dataType, value, valueObj, field) {
        var settings = field.data('settings');
        if (!settings
            || !settings.default_value || !settings.default_value.trim().length
            // The value from the object is already managed.
            || valueObj
            // Don't add a value if this is an edition.
            || !$('body').hasClass('add')
            // Don't add a value if there is already a value.
            || field.find('.input-value').length > 0
            || field.find('input.value').length > 0
            || field.find('input.value.to-require').length > 0
        ) {
            return;
        }

        var defaultValue = settings.default_value.trim();
        valueObj = jsonDecodeObject(defaultValue);
        // Manage specific data type "resource".
        if (dataType.startsWith('resource')) {
            if (/^\d+$/.test(defaultValue)) {
                if (!valueObj) {
                    valueObj = {
                        display_title: Omeka.jsTranslate('Resource') + ' #' + defaultValue,
                        value_resource_id: defaultValue,
                        value_resource_name: 'resource',
                        url: '#',
                    };
                }
                value.find('input.value[data-value-key="value_resource_id"]').val(valueObj.value_resource_id);
                // TODO Get the title from the api (when authentication will be opened).
                value.find('span.default').hide();
                var resource = value.find('.selected-resource');
                resource.find('.o-title')
                    .removeClass() // remove all classes
                    .addClass('o-title ' + valueObj['value_resource_name'])
                    .html($('<a>', {href: valueObj['url'], text: valueObj['display_title']}));
                if (typeof valueObj['thumbnail_url'] !== 'undefined') {
                    resource.find('.o-title')
                        .prepend($('<img>', {src: valueObj['thumbnail_url']}));
                }
            }
        }

        // Manage most common default values for other data types.
        if (!valueObj) {
            if (dataType === 'uri' || dataType.startsWith('valuesuggest')) {
                if (defaultValue.match(/^(\S+)\s(.*)/)) {
                    valueObj = defaultValue.match(/^(\S+)\s(.*)/).slice(1);
                    valueObj = {'@id': valueObj[0], 'o:label': valueObj[1]};
                } else {
                    valueObj = {'@id': defaultValue};
                }
            } else {
                valueObj = {'@value': defaultValue};
            }
        }

        // Prepare simple single-value form inputs using data-value-key.
        value.find(':input').each(function () {
            var valueKey = $(this).data('valueKey');
            if (!valueKey) {
                return;
            }
            $(this).removeAttr('name')
                .val(valueObj ? valueObj[valueKey] : null);
        });
    }

    function jsonDecodeObject(string) {
        try {
            var obj = JSON.parse(string);
            return obj && typeof obj === 'object' && Object.keys(obj).length ? obj : null;
        } catch (e) {
            return null;
        }
    }

    $(document).on('o:prepare-value', function(e, dataType, value, valueObj) {
        var field = value.closest('.field');
        var term = value.data('term');
        if (!field.length) {
            field = $('#properties [data-property-term="' + term + '"].field');
            if (!field.length) {
                return;
            }
        }
        var settings = field.data('settings');
        if (!settings) {
            return;
        }

        fillDefaultValue(dataType, value, valueObj, field);
    });

});
