$(document).ready(function() {

    /**
     * Prepare the language for a property.
     */
    function prepareFieldLanguage(field) {
        // Add a specific datalist for the property. It replaces the previous one from another template.
        var templateSettings = $('#resource-values').data('template-settings');
        var settings = field.data('settings');
        var listName = 'value-languages';
        var term = field.data('property-term');

        var datalist = $('#value-languages-template');
        if (datalist.length) {
            datalist.empty();
        } else {
            $('#value-languages').after('<datalist id="value-languages-template" class="value-languages"></datalist>');
            datalist = $('#value-languages-template');
        }
        if (templateSettings.value_languages && !$.isEmptyObject(templateSettings.value_languages)) {
            listName = 'value-languages-template';
            $.each(templateSettings.value_languages, function(code, label) {
                datalist.append($('<option>', { value: code, label: label.length ? label : code }));
            });
        }

        datalist = field.find('.values ~ datalist.value-languages');
        if (datalist.length) {
            datalist.empty();
        } else {
            field.find('.values').first().after('<datalist class="value-languages"></datalist>');
            datalist = field.find('.values ~ datalist.value-languages');
            datalist.attr('id', 'value-languages-' + term);
        }
        if (settings && settings.value_languages && !$.isEmptyObject(settings.value_languages)) {
            listName = 'value-languages-' + term;
            $.each(settings.value_languages, function(code, label) {
                datalist.append($('<option>', { value: code, label: label.length ? label : code }));
            });
        }

        // Use the main datalist, or the template one, or the property one.
        var inputLanguage = field.find('.values input.value-language');
        inputLanguage.attr('list', listName);
    }

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
            // Custom vocab.
            || field.find('select.terms option').length > 0
            // Numeric data types.
            || field.find('input.numeric-integer-value').length > 0
            // Value suggest.
            || field.find('input.valuesuggest-input').length > 0
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

        // @see custom-vocab.js
        if (dataType.startsWith('customvocab:')) {
            var selectTerms = value.find('select.terms');
            selectTerms.find('option[value="' + valueObj['@value'] + '"]').prop('selected', true);
            selectTerms.chosen({ width: '100%', });
            selectTerms.trigger('chosen:updated');
        }

        // @see numeric-data-types.js
        if (dataType === 'numeric:integer') {
            var container = value;
            var v = container.find('.numeric-integer-value');
            var int = container.find('.numeric-integer-integer');
            int.val(v.val());
        }

        // Value Suggest is a lot more complex. Sub-trigger value?
        // @see valuesuggest.js
        if (dataType.startsWith('valuesuggest')) {
            var thisValue = value;
            var suggestInput = thisValue.find('.valuesuggest-input');
            var labelInput = thisValue.find('input[data-value-key="o:label"]');
            var idInput = thisValue.find('input[data-value-key="@id"]');
            var valueInput = thisValue.find('input[data-value-key="@value"]');
            // var languageLabel = thisValue.find('.value-language.label');
            var languageInput = thisValue.find('input[data-value-key="@language"]');
            // var languageRemove = thisValue.find('.value-language.remove');
            var idContainer = thisValue.find('.valuesuggest-id-container');

            if (valueObj['o:label']) {
                labelInput.val(valueObj['o:label']);
            }
            if (valueObj['@id']) {
                idInput.val(valueObj['@id']);
            }
            if (valueObj['@value']) {
                valueInput.val(valueObj['@value']);
            }
            if (valueObj['@language']) {
                languageInput.val(valueObj['@language']);
            }

            // Literal is the default type.
            idInput.prop('disabled', true);
            labelInput.prop('disabled', true);
            valueInput.prop('disabled', false);
            idContainer.hide();

            // Set existing values during initial load.
            if (idInput.val()) {
                // Set value as URI type
                suggestInput.val(labelInput.val()).attr('placeholder', labelInput.val());
                idInput.prop('disabled', false);
                labelInput.prop('disabled', false);
                valueInput.prop('disabled', true);
                var link = $('<a>')
                    .attr('href', idInput.val())
                    .attr('target', '_blank')
                    .text(idInput.val());
                idContainer.show().find('.valuesuggest-id').html(link);
            } else if (valueInput.val()) {
                // Set value as Literal type
                suggestInput.val(valueInput.val()).attr('placeholder', valueInput.val());
                idInput.prop('disabled', true);
                labelInput.prop('disabled', true);
                valueInput.prop('disabled', false);
            }
        }
    }

    function jsonDecodeObject(string) {
        try {
            var obj = JSON.parse(string);
            return obj && typeof obj === 'object' && Object.keys(obj).length ? obj : null;
        } catch (e) {
            return null;
        }
    }

    $(document).on('o:template-applied', 'form.resource-form', function() {
        var fields = $('#properties .resource-values');
        fields.each(function(index, field) {
            prepareFieldLanguage($(field));
        });
    });

    $(document).on('o:property-added', '.resource-values.field', function() {
        var field = $(this);
        prepareFieldLanguage(field);
    });

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

        prepareFieldLanguage(field);
        var templateSettings = $('#resource-values').data('template-settings');
        var listName = templateSettings.value_languages && !$.isEmptyObject(templateSettings.value_languages)
            ? 'value-languages-template'
            : 'value-languages';
        listName = settings.value_languages && !$.isEmptyObject(settings.value_languages)
            ? 'value-languages-' + term
            : listName;
        value.find('input.value-language').attr('list', listName);

        fillDefaultValue(dataType, value, valueObj, field);
    });

});
