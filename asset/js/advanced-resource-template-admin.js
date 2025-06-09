(function($) {

    /**
     * Check if a value is true (true, 1, "1", "true", yes", "on").
     *
     * This function avoids issues with values stored directly or with a form.
     * A value can be neither true or false.
     */
    const valueIsTrue = function checkIfValueIsTrue(value) {
        return [true, 1, '1', 'true', 'yes', 'on'].includes(value);
    }

    /**
     * Check if a value is false (false, 0, "0", "false", "no", "off").
     *
     * This function avoids issues with values stored directly or with a form.
     * A value can be neither true or false.
     */
    const valueIsFalse = function checkIfValueIsFalse(value) {
        return [false, 0, '0', 'false', 'no', 'off'].includes(value);
    }

    $(document).ready(function() {

        const baseUrl = window.location.pathname.replace(/\/admin\/.*/, '/');

        // Initialize the sidebar according to the main option.
        const sidebarPropertySelector = $('#property-selector');
        if ($('form.resource-form').hasClass('closed-property-list on-load')) {
            // Once loaded, the param is managed dynamically via event "o:template-applied".
            $('form.resource-form').removeClass('on-load');
            sidebarPropertySelector.removeClass('always-open');
            $('form.resource-form #property-selector-button').hide();
            Omeka.closeSidebar(sidebarPropertySelector);
        }

        function prepareFieldDisplay(field) {
            if ($('body').hasClass('art-metadata-collapse')) {
                if (field) {
                    field.find('.field-meta a.collapse').trigger('click');
                } else {
                    $('.resource-form .field-meta a.collapse').trigger('click');
                }
            }
        }

        /**
         * Prepare the lock for original values of a property.
         */
        function prepareFieldLocked(field) {
            const rtpData = field.data('template-property-data') ? field.data('template-property-data') : {};
            if (rtpData.locked_value != true) {
                return;
            }

            // Some weird selectors are needed to manage all cases.
            // Check if a value is filled: don't lock an empty value.
            var isEmpty = true;
            field.find('.inputs .values .value:not(.default-value) .input-body').find('input, select, textarea').filter('[data-value-key]').each(function() {
                const input = $(this);
                // Check real value only, often hidden.
                if ($.inArray(input.data('value-key'), ['@value', 'value_resource_id', '@id', 'o:label']) === -1) {
                    return;
                }
                // TODO Find a way to to lock a checkbox value.
                if (input.closest('.value').data('data-type') === 'boolean') {
                    return;
                }
                if (input.val().trim() === '') {
                    return;
                }
                isEmpty = false;
            });
            if (isEmpty) {
                return;
            }

            field.find('.inputs .values .value:not(.default-value) .input-body').find('input, select, textarea').addClass('original-value');
            const originalValues = field.find('.input-body .original-value');
            originalValues
                .prop('readonly', 'readonly')
                .attr('readonly', 'readonly');
            originalValues.closest('.input-body').find('.o-icon-close').remove();
            originalValues.closest('.input-body').find('.button.resource-select').remove();
            originalValues.closest('div.value').find('.input-footer .remove-value').remove();

            // Disable some field is required separately: it can be still changed (numeric data type).
            originalValues.filter('[type=checkbox]:not([data-value-key])').attr('disabled', true);
            originalValues.filter('[type=radio]:not([data-value-key])').attr('disabled', true);
            originalValues.filter('select:not([data-value-key])').attr('disabled', true).trigger('chosen:updated');
            // Manage custom vocab with chosen.
            originalValues.filter('select[data-value-key]').each(function() {
                $(this).find('option[value!="' + $(this).val() + '"]').remove()
                    .end().chosen('destroy');
            });
        }

        /**
         * Set an input regex pattern to a literal field.
         */
        function prepareFieldInputControl(field) {
            const rtpData = field.data('template-property-data');
            if (!rtpData) {
                return;
            }

            const dataType = field.data('data-types') && field.data('data-types').length ? field.data('data-types').split(',')[0] : 'literal';
            if (dataType !== 'literal') {
                return;
            }

            // Html input pattern does not work on textarea, so replace each textarea
            // by an input.
            const textareas = field.find('textarea.input-value, textarea.inputcontrol');
            textareas.each(function() {
                const textarea = $(this);
                var input;
                if (rtpData.input_control) {
                    if (!textarea.hasClass('inputcontrol')) {
                        textarea.addClass('inputcontrol');
                        textarea.removeClass('input-value to-require');
                        textarea.after($('<input/>', { type: 'text', class: 'input-value to-require', 'data-value-key': '@value' }));
                        input = textarea.next('input.input-value');
                        input.attr('pattern', rtpData.input_control);
                        input.val(textarea.val());
                        textarea.hide();
                    }
                } else if (textarea.hasClass('inputcontrol')) {
                    input = textarea.next('input.input-value');
                    textarea.val(input.val());
                    textarea.addClass('input-value to-require');
                    textarea.removeClass('inputcontrol');
                    input.remove();
                    textarea.show();
                }
            });
        }

        /**
         * Set min/max length of a literal field.
         */
        function prepareFieldLength(field) {
            const rtpData = field.data('template-property-data');
            if (!rtpData) {
                return;
            }

            const dataType = field.data('data-types') && field.data('data-types').length ? field.data('data-types').split(',')[0] : 'literal';
            if (dataType !== 'literal') {
                return;
            }

            const textarea = field.find('textarea.input-value');
            if (rtpData.min_length) {
                textarea.attr('minlength', rtpData.min_length);
            } else {
                textarea.removeAttr('minlength');
            }
            if (rtpData.max_length) {
                textarea.attr('maxlength', rtpData.max_length);
            } else {
                textarea.removeAttr('maxlength');
            }
        }

        /**
         * Prepare the number of specified fields when a minimum number is set.
         */
        function prepareFieldMinValues(field) {
            const rtpData = field.data('template-property-data');
            if (!rtpData) {
                return;
            }
            const currentCountValues = field.find('.inputs .values > .value').length;
            if (!rtpData.min_values || currentCountValues >= rtpData.min_values
                || (rtpData.max_values && currentCountValues >= rtpData.max_values)
            ) {
                return;
            }
            const dataType = field.data('data-types') && field.data('data-types').length ? field.data('data-types').split(',')[0] : 'literal';
            for (let i = 0; i < (rtpData.min_values - currentCountValues); i++) {
                // Add only one field, because it is called recursively.
                const value = makeNewValue(field.data('property-term'), dataType);
                field.find('.values').append(value);
            }
        }

        /**
         * Prepare the button "add-value" after filling values.
         *
         * The button may be hidden in a later step too when a value is added.
         * Don't include the removed values in the total of values.
         *
         * The button is displayed when a value is removed.
         */
        function prepareFieldMaxValues(field) {
            const rtpData = field.data('template-property-data');
            if (!rtpData) {
                return;
            }
            const currentCountValues = field.find('.inputs .values > .value').length;
            if (!rtpData.max_values || currentCountValues < rtpData.max_values) {
                return;
            }
            const selector = field.data('selector') ? field.data('selector') : 'default';
            field.find('.add-values.' + selector + '-selector').hide();
        }

        /**
         * Disable manual edition of specified values.
         */
        function prepareFieldReadOnly(field) {
            const rtpData = field.data('template-property-data');
            if (!rtpData) {
                return;
            }
            if (typeof rtpData.property_read_only === undefined || !valueIsTrue(rtpData.property_read_only)) {
                return;
            }
            field.find('.inputs .values :input:not([type=hidden])').each(function (idx, el) {
                $(el)
                    .attr('readonly', 'readonly')
                    // Remove "required" from empty values.
                    .attr('required', false);
            });
            field.find('.inputs .remove-value, .inputs .add-values').hide();
        }

        /**
         * Prepare the autocompletion for a property.
         */
        function prepareFieldAutocomplete(field) {
            const templateData = $('#resource-values').data('template-data');
            const rtpData = field.data('template-property-data') ? field.data('template-property-data') : {};

            // Reset autocomplete for all properties.
            $('.inputs .values textarea.input-value').prop('autocomplete', 'off');
            field.removeData('autocomplete');
            field.find('.inputs .values textarea.input-value.autocomplete').each(function() {
                const autocomp = $(this).autocomplete();
                if (autocomp) {
                    autocomp.dispose();
                }
            });
            field.find('.inputs .values textarea.input-value').prop('autocomplete', 'off').removeClass('autocomplete');

            var autocomplete = templateData && templateData.autocomplete ? templateData.autocomplete : 'no';
            autocomplete = rtpData.autocomplete && $.inArray(rtpData.autocomplete, ['no', 'sw', 'in'])
                ? rtpData.autocomplete
                : autocomplete;
            if (autocomplete === 'sw' || autocomplete === 'in') {
                field.data('autocomplete', autocomplete);
                field.find('.inputs .values textarea.input-value').addClass('autocomplete');
                field.find('.inputs .values textarea.input-value.autocomplete').each(initAutocomplete);
            }
        }

        /**
         * Prepare the language for a property.
         */
        function prepareFieldLanguage(field) {
            // Add a specific datalist for the property. It replaces the previous one from another template.
            const templateData = $('#resource-values').data('template-data');
            const rtpData = field.data('template-property-data') ? field.data('template-property-data') : {};
            const term = field.data('property-term');

            var listName = 'value-languages';
            var datalist = $('#value-languages-template');
            if (datalist.length) {
                datalist.empty();
            } else {
                $('#value-languages').after($('<datalist/>', { id: 'value-languages-template', class: 'value-languages' }));
                datalist = $('#value-languages-template');
            }
            if (templateData && templateData.value_languages && !$.isEmptyObject(templateData.value_languages)) {
                listName = 'value-languages-template';
                $.each(templateData.value_languages, function(code, label) {
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
            if (rtpData.value_languages && !$.isEmptyObject(rtpData.value_languages)) {
                listName = 'value-languages-' + term;
                $.each(rtpData.value_languages, function(code, label) {
                    datalist.append($('<option>', { value: code, label: label.length ? label : code }));
                });
            }

            // Use the main datalist, or the template one, or the property one.
            const inputLanguage = field.find('.values input.value-language');
            inputLanguage.attr('list', listName);

            const noLanguage = valueIsFalse(rtpData.use_language)
                || ((!rtpData.use_language || rtpData.use_language === '') && templateData && valueIsTrue(templateData.no_language));
            field.data('no-language', noLanguage);
            field.find('.inputs .values input.value-language').each(function() {
                initValueLanguage($(this), field);
            });
        }

        /**
         * Prepare the value annotations for a property.
         */
        function prepareFieldValueAnnotation(field) {
            // Add a specific datalist for the property. It replaces the previous one from another template.
            const templateData = $('#resource-values').data('template-data');
            const propertyData = field.data('template-property-data') ? field.data('template-property-data') : {};
            const vaTemplate = templateData && templateData.value_annotations_template ? templateData.value_annotations_template : 'manual';
            const vaTemplateProperty = propertyData.value_annotations_template && propertyData.value_annotations_template !== ''
                ? propertyData.value_annotations_template
                : vaTemplate;
            // Default config: display value annotations (except if main params hide them via EasyAdmin).
            if (!vaTemplateProperty || vaTemplateProperty === 'manual') {
                field.find('.value-annotation-annotate').show();
            } else if (vaTemplateProperty === 'none') {
                field.find('.value-annotation-annotate').hide();
            } else {
                // This is a template, so load it via api.
                field.find('.value-annotation-annotate').show();
            }
        }

        /**
         * Set a custom vocab open to new values.
         */
        function prepareFieldCustomVocabOpen(field) {
            const rtpData = field.data('template-property-data');
            if (!rtpData) {
                return;
            }

            const dataType = field.data('data-types') && field.data('data-types').length ? field.data('data-types').split(',')[0] : 'literal';
            if (!dataType.startsWith('customvocab:')) {
                return;
            }

            const select = field.find('select.custom-vocab-literal');
            const isCustomVocabOpen = valueIsTrue(rtpData.custom_vocab_open);
            if (isCustomVocabOpen) {
                prepareCustomVocabOpenInput(select);
            } else if (select.hasClass('customvocab-open')) {
                select.removeClass('customvocab-open');
                field.find('input.customvocab-open')
                    .remove();
            }
        }

        function prepareCustomVocabOpenInput(select) {
            if (!select.length) {
                return;
            }

            // May be already set for new resource.
            if (select.hasClass('customvocab-open')) {
                return;
            }

            select.addClass('customvocab-open');
            select.on('change', function(e) {
                const inputBody = $(this).closest('.input-body');
                if ($(this).val() === '') {
                    inputBody
                        .append($('<input/>', { type: 'text', class: 'customvocab-open', 'data-value-key': '@value', placeholder: Omeka.jsTranslate('New value') }));
                } else {
                    inputBody
                        .find('input.customvocab-open')
                        .remove();
                }
            });
        }

        /**
         * Init the language input.
         */
        function initValueLanguage(languageInput, field) {
            const languageWrapper = languageInput.closest('.language-wrapper');
            const languageButton = languageInput.prev('a.value-language');
            var language = languageInput.val();
            if (field.data('no-language') == true) {
                language = '';
                languageWrapper.removeClass('active');
                languageButton.addClass('no-language');
                languageInput.prop('disabled', true);
            } else {
                languageButton.removeClass('no-language');
                languageInput.prop('disabled', false);
            }
            if (language !== '') {
                languageWrapper.addClass('active');
            }
        }

        /**
         * Fill the default language.
         */
        function fillDefaultLanguage(value, valueObj, field) {
            value.find('input.value-language').each(function() {
                initValueLanguage($(this), field);
            });

            if (valueObj) {
                return;
            }
            if (field.data('no-language')) {
                return;
            }

            const templateData = $('#resource-values').data('template-data');
            const rtpData = field.data('template-property-data') ? field.data('template-property-data') : {};
            var defaultLanguage = templateData && templateData.default_language && templateData.default_language.length
                ? templateData.default_language
                : '';
            defaultLanguage = rtpData['o:default_lang'] && rtpData['o:default_lang'].length
                ? rtpData['o:default_lang']
                : defaultLanguage;
            if (defaultLanguage.length) {
                value.find('input.value-language').val(defaultLanguage);
                value.find('.language-wrapper').addClass('active');
            }
        }

        /**
         * Make a new property field with data stored in the property selector.
         *
         * Copy of resource-form.js, not available here, except the trigger, managed outside.
         * @see resource-form.js makeNewField()
         */
        const makeNewField = function(property, dataTypes) {
            // Prepare data type name of the field.
            if (!dataTypes || dataTypes.length < 1) {
                dataTypes = $('div#properties').data('default-data-types').split(',');
            }

            // Sort out whether property is the LI that holds data, or the id.
            var propertyLi, propertyId;
            switch (typeof property) {
                case 'object':
                    propertyLi = property;
                    propertyId = propertyLi.data('property-id');
                    break;

                case 'number':
                    propertyId = property;
                    propertyLi = $('#property-selector').find("li[data-property-id='" + propertyId + "']");
                    break;

                case 'string':
                    propertyLi = $('#property-selector').find("li[data-property-term='" + property + "']");
                    propertyId = propertyLi.data('property-id');
                    break;

                default:
                    return null;
            }

            const term = propertyLi.data('property-term');
            const field = $('.resource-property.template').clone(true);
            field.removeClass('template');
            field.find('.field-label').text(propertyLi.data('child-search')).attr('id', 'property-' + propertyId + '-label');
            field.find('.field-term').text(term);
            field.find('.field-description').prepend(propertyLi.find('.field-comment').text());
            field.data('property-term', term);
            field.data('property-id', propertyId);
            field.data('data-types', dataTypes.join(','));
            // Adding the attr because selectors need them to find the correct field
            // and count when adding more.
            field.attr('data-property-term', term);
            field.attr('data-property-id', propertyId);
            field.attr('data-data-types', dataTypes.join(','));
            field.attr('aria-labelledby', 'property-' + propertyId + '-label');
            $('div#properties').append(field);

            new Sortable(field.find('.values')[0], {
                draggable: '.value',
                handle: '.sortable-handle'
            });

            // field.trigger('o:property-added');
            return field;
        };

        /**
         * Make a new value.
         *
         * Copy of resource-form.js, not available here, in order to add a function
         * before trigger.
         * @see resource-form.js makeNewValue()
         */
        const makeNewValue = function(term, dataType, valueObj) {
            var field = $('.resource-property[data-property-term="' + term + '"]');
            // Get the value node from the templates.
            if (!dataType || typeof dataType !== 'string') {
                // ":visible" cannot be used when loading page on a specific tab, for example "#item-media".
                // dataType = valueObj ? valueObj['type'] : field.find('.add-value:visible:first').data('type');
                dataType = valueObj ? valueObj['type'] : field.find('.add-values:not([style="display: none;"]):not([style="display:none;"]) .add-value:first').data('type');
            }
            const fieldForDataType = field.filter(function() { return $.inArray(dataType, $(this).data('data-types').split(',')) > -1; });
            field = fieldForDataType.length ? fieldForDataType.first() : field.first();
            const value = $('.value.template[data-data-type="' + dataType + '"]').clone(true);
            value.removeClass('template');
            value.attr('data-term', term);
            value.data('valueAnnotations', valueObj ? valueObj['@annotation'] : null);

            // Get and display the value's visibility.
            var isPublic = true; // values are public by default
            if (field.hasClass('private') || (valueObj && false === valueObj['is_public'])) {
                isPublic = false;
            }
            const valueVisibilityButton = value.find('a.value-visibility');
            if (isPublic) {
                valueVisibilityButton.removeClass('o-icon-private').addClass('o-icon-public');
                valueVisibilityButton.attr('aria-label', Omeka.jsTranslate('Make private'));
                valueVisibilityButton.attr('title', Omeka.jsTranslate('Make private'));
            } else {
                valueVisibilityButton.removeClass('o-icon-public').addClass('o-icon-private');
                valueVisibilityButton.attr('aria-label', Omeka.jsTranslate('Make public'));
                valueVisibilityButton.attr('title', Omeka.jsTranslate('Make public'));
            }

            // Prepare the value node.
            const valueLabelID = 'property-' + field.data('property-id') + '-label';
            value.find('input.is_public')
                .val(isPublic ? 1 : 0);
            value.find('span.label')
                .attr('id', valueLabelID);
            value.find('textarea.input-value')
                .attr('aria-labelledby', valueLabelID);
            value.attr('aria-labelledby', valueLabelID);

            valueObj = fixValueForDataType(valueObj, dataType);

            $(document).trigger('o:prepare-value', [dataType, value, valueObj]);

            return value;
        };

        /**
         * Fixes some values for some data types.
         *
         * Because resource-form.js listens event first, some fixes should be done
         * for some data types, for example for a timestamp with an incomplete date,
         * that can't be managed for autofilling by currently implemented twig features.
         *
         * @todo Implement missing twig features ("if empty", etc.) or add a specific function.
         */
        function fixValueForDataType(valueObj, dataType) {
            if (!valueObj || !Object.keys(valueObj).length) {
                return valueObj;
            }
            if (dataType === 'numeric:timestamp') {
                // Remove missing parts of the end of the date.
                valueObj['@value'] = valueObj['@value'].replace(/[\s-]+$/g, '');
            }
            return valueObj;
        }

        /**
         * Fill a new value, that can be empty.
         *
         * @see makeNewValue() in resource-form.js: the same, except that the empty
         * value is already created in previous hook and may be filled.
         */
        function fillValue(value, term, dataType, valueObj) {
            // If defaultValue is undefined, it means that valueObj is filled.
            var v;
            const defaultValue = valueObj.default;
            const isSpecific = typeof defaultValue !== 'undefined';

            // Manage specific data type "resource".
            if (dataType.startsWith('resource')) {
                const dataTypeLabels = {
                    'resource': Omeka.jsTranslate('Resource'),
                    'resource:item': Omeka.jsTranslate('Item'),
                    'resource:itemset': Omeka.jsTranslate('Item set'),
                    'resource:media': Omeka.jsTranslate('Media'),
                };
                const dataTypeLabel = dataTypeLabels[dataType] ? dataTypeLabels[dataType] : dataTypeLabels['resource'];
                if (isSpecific && /^\d+$/.test(defaultValue)) {
                    valueObj = {
                        display_title: dataTypeLabel + ' #' + defaultValue,
                        value_resource_id: defaultValue,
                        value_resource_name: dataType,
                        url: '#',
                    };
                }
                value.find('input.value[data-value-key="value_resource_id"]').val(valueObj['value_resource_id']);
                // TODO Get the title from the api (when authentication will be opened).
                value.find('span.default').hide();
                const resource = value.find('.selected-resource');
                resource.find('.o-title')
                    .removeClass() // remove all classes
                    .addClass('o-title ' + valueObj['value_resource_name'])
                    .html($('<a>', { href: valueObj['url'], text: valueObj['display_title'] }));
                if (typeof valueObj['thumbnail_url'] !== 'undefined') {
                    resource.find('.o-title')
                        .prepend($('<img>', { src: valueObj['thumbnail_url'] }));
                }
            }

            // Manage most common default values for other data types.
            if (isSpecific) {
                if (dataType === 'uri' || dataType.startsWith('valuesuggest')) {
                    if (defaultValue.match(/^(\S+)\s(.*)/)) {
                        valueObj = defaultValue.match(/^(\S+)\s(.*)/).slice(1);
                        valueObj = { '@id': valueObj[0], 'o:label': valueObj[1] };
                    } else {
                        valueObj = { '@id': defaultValue };
                    }
                } else {
                    valueObj = { '@value': defaultValue };
                }
            }

            // Prepare simple single-value form inputs using data-value-key.
            value.find(':input').each(function() {
                const valueKey = $(this).data('valueKey');
                // Default language is managed separately.
                if (!valueKey || valueKey === '@language') {
                    return;
                }
                $(this).removeAttr('name')
                    .val(valueObj ? valueObj[valueKey] : null);
            });

            // @see custom-vocab.js
            if (dataType.startsWith('customvocab:')) {
                const selectTerms = value.find('select.terms');
                selectTerms.find('option[value="' + valueObj['@value'] + '"]').prop('selected', true);
                selectTerms.chosen({ width: '100%', });
                selectTerms.trigger('chosen:updated');
            }

            // @see numeric-data-types.js
            if (typeof NumericDataTypes !== 'undefined' && dataType.startsWith('numeric:')) {
                if (dataType === 'numeric:timestamp') {
                    v = value.find('.numeric-datetime-value');
                    // The class is used to init a field, but it doesn't have the value yet.
                    v.val(defaultValue);
                    v.closest('.numeric-timestamp').removeClass('numeric-enabled');
                    NumericDataTypes.enableTimestamp(value);
                }
                if (dataType === 'numeric:interval') {
                    v = value.find('.numeric-datetime-value');
                    // The class is used to init a field, but it doesn't have the value yet.
                    v.val(defaultValue);
                    v.closest('.numeric-interval').removeClass('numeric-enabled');
                    NumericDataTypes.enableInterval(value);
                }
                if (dataType === 'numeric:duration') {
                    v = value.find('.numeric-duration-value');
                    // The class is used to init a field, but it doesn't have the value yet.
                    v.val(defaultValue);
                    v.closest('.numeric-duration').removeClass('numeric-enabled');
                    NumericDataTypes.enableDuration(value);
                }
                if (dataType === 'numeric:integer') {
                    v = value.find('.numeric-integer-value');
                    // The class is used to init a field, but it doesn't have the value yet.
                    v.val(defaultValue);
                    v.closest('.numeric-integer').removeClass('numeric-enabled');
                    NumericDataTypes.enableInteger(value);
                }
            }

            // Value Suggest is a lot more complex. Sub-trigger value?
            // @see valuesuggest.js
            if (dataType.startsWith('valuesuggest')) {
                const thisValue = value;
                const suggestInput = thisValue.find('.valuesuggest-input');
                const labelInput = thisValue.find('input[data-value-key="o:label"]');
                const idInput = thisValue.find('input[data-value-key="@id"]');
                const valueInput = thisValue.find('input[data-value-key="@value"]');
                // const languageLabel = thisValue.find('.value-language.label');
                const languageInput = thisValue.find('input[data-value-key="@language"]');
                // const languageRemove = thisValue.find('.value-language.remove');
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
                    const link = $('<a>')
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

        /**
         * Manage the display of the value input.
         */
        function prepareValueDisplay(value) {
            if ($('body').hasClass('art-no-more-actions')) {
                if (value) {
                    const val = $(value);
                    val.find('.input-footer .actions').append(val.find('.more-actions li'));
                    val.find('.more-actions').remove();
                } else {
                    $('.art-no-more-actions .resource-form .input-footer .actions').each(function() {
                        $(this).append($(this).find('.more-actions li'));
                        $(this).find('.more-actions').remove();
                    });
                }
            }
        }

        /**
         * Manage the options for the resource class select.
         */
        function prepareResourceClassSelect() {
            const templateData = $('#resource-values').data('template-data');
            const hasTemplate = $('#resource-template-select').val() != '';
            const resourceClassSelect = $('#resource-values #resource-class-select');
            const resourceClassId = Number(resourceClassSelect.val());

            // Store the default full list of resource classes to allow to restore 
            // it when the template is changed.
            const areResourceClassesStored = typeof $('#resource-values').data('resource_class_select') !== 'undefined';
            if (areResourceClassesStored) {
                // Restore default select.
                resourceClassSelect.html($('#resource-values').data('resource_class_select'));
            } else {
                $('#resource-values').data('resource_class_select', $('#resource-values #resource-class-select').html());
            }
            // Re-set the previous resource class id when the html is reset.
            resourceClassSelect.val(resourceClassId);

            const suggestedClasses = templateData && templateData.suggested_resource_class_ids ? templateData.suggested_resource_class_ids : {};
            const countSuggestedClasses = Object.keys(suggestedClasses).length;

            // Fill the classes according to the template.
            if (hasTemplate
                && countSuggestedClasses
                && valueIsTrue(templateData.closed_class_list)
            ) {
                resourceClassSelect.find('option').each(function() {
                    const resClassId = Number($(this).val());
                    if (resClassId && !Object.values(suggestedClasses).includes(resClassId)) {
                        $(this).remove();
                    }
                });
            }

            // Manage no template and bad template options: at least one class.
            if (!hasTemplate || !resourceClassSelect.find('option').length) {
                resourceClassSelect.html($('#resource-values').data('resource_class_select'));
                resourceClassSelect.val(resourceClassId);
            }

            // If the current resource class is not in the new list of options,
            // set the first suggested one. This is the most common case when
            // templates are used.
            if (hasTemplate
                && Object.values(suggestedClasses).length
                && !Object.values(suggestedClasses).includes(resourceClassId)
            ) {
                let resClassId = countSuggestedClasses
                    ? Object.values(suggestedClasses)[0]
                    // Manage non-advanced templates.
                    : (templateData['o:resource_class'] ? templateData['o:resource_class']['o:id'] : null);
                resourceClassSelect.val(resClassId);
            }

            if (templateData && valueIsTrue(templateData.require_resource_class)) {
                resourceClassSelect.attr('require', 'require')
                    .closest('.field').addClass('required');
            } else {
                resourceClassSelect.removeAttr('require')
                    .closest('.field').removeClass('required');
            }

            resourceClassSelect.trigger('chosen:updated');
        }

        function prepareAutofiller() {
            $('#resource-values .non-properties .field.autofiller').remove();

            const templateData = $('#resource-values').data('template-data');
            if (!templateData || !templateData.autofillers || !templateData.autofillers.length) {
                return;
            }

            templateData.autofillers.forEach(function(autofillerName) {
                $.get(baseUrl + 'admin/autofiller/settings', {
                        service: autofillerName,
                        template: $('#resource-template-select').val(),
                    })
                    .done(function(data) {
                        const autofiller = data.data.autofiller;
                        const autofillerId = 'autofiller-' + autofillerName.replace(/[\W_]+/g, '-');
                        $('#resource-values .non-properties').append(`
    <div class="field autofiller">
        <div class="field-meta">
            <label for="autofiller-input">${autofiller.label}</label>
            <span class="spinner"></span>
        </div>
        <div class="inputs">
            <input type="text" class="autofiller" id="${autofillerId}">
        </div>
    </div>`);
                        const autofillerField = $('#' + autofillerId);
                        autofillerField.autocomplete({
                            serviceUrl: baseUrl + 'admin/autofiller',
                            deferRequestBy: 200,
                            // minChars: 3,
                            dataType: 'json',
                            maxHeight: 600,
                            paramName: 'q',
                            params: {
                                service: autofillerName,
                                template: $('#resource-template-select').val(),
                            },
                            showNoSuggestionNotice: true,
                            noSuggestionNotice: Omeka.jsTranslate('No results'),
                            // Required, because when multiple characters are typed,
                            // the previous requests are stopped.
                            preventBadQueries: false,
                            transformResult: function(response) {
                                return response.data;
                            },
                            onSearchStart: function(/*params*/) {
                                $(this).closest('.field.autofiller').find('.spinner').addClass('processing fas fa-sync fa-spin');
                                $(this).css('cursor', 'progress');
                            },
                            onSearchComplete: function(/*query, suggestions*/) {
                                $(this).closest('.field.autofiller').find('.spinner').removeClass('processing fas fa-sync fa-spin');
                                $(this).css('cursor', 'default');
                            },
                            onSearchError: function(query, jqXHR, textStatus, errorThrown) {
                                // If there is no response, the request is aborted for autocompletion.
                                if (jqXHR.responseJSON) {
                                    if (jqXHR.responseJSON.status === 'fail') {
                                        alert(jqXHR.responseJSON.data.suggestions);
                                    } else {
                                        alert(jqXHR.responseJSON.message);
                                    }
                                    autofillerField.autocomplete().dispose();
                                }
                            },
                            beforeRender: function(container, suggestions) {
                                container.children().each(function(index) {
                                    if (Object.keys(suggestions[index].data).length) {
                                        const info = $(this).append('<div class="suggest-info autofill"><dl></dl></div>').find('.suggest-info dl');
                                        $.each(suggestions[index].data, function(term, value) {
                                            info.append(`<dt>${value[0].property_label ? value[0].property_label : term}</dt>`);
                                            value.forEach(function(val) {
                                                info.append(`<dd>${typeof val['@value'] === 'undefined' || String(val['@value']) && !String(val['@value']).length ? val['@id'] : val['@value']}</dd>`);
                                            });
                                        });
                                    }
                                });
                            },
                            onSelect: function(suggestion) {
                                const autofillerId = 'autofiller-' + autofillerName.replace(/[\W_]+/g, '-');
                                $('#' + autofillerId).val(suggestion.info.label);
                                autofill(suggestion.data);
                            },
                        });
                    })
                    .fail(function(jqXHR, textStatus) {
                        if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                            alert(jqXHR.responseJSON.message);
                        } else {
                            alert(Omeka.jsTranslate('Something went wrong'));
                        }
                    })
                    .always(function() {
                        $(this).closest('.field.autofiller').find('.spinner').removeClass('processing fas fa-sync fa-spin');
                        $(this).css('cursor', 'default');
                    });
            });
        }

        function autofill(values) {
            Object.keys(values).forEach(function(term) {
                values[term].forEach(function(value) {
                    var field = $('.resource-property[data-property-term="' + term + '"]').filter(function() { return $.inArray(value.type, $(this).data('data-types').split(',')) > -1; });
                    if (!field.length) {
                        field = makeNewField(term);
                    }
                    // Check if the first field has an empty default value, so remove it.
                    field.first().find('.values .value.default-value').remove();
                    field.first().find('.values').append(makeNewValue(term, value.type, value));
                });
            });
        }

        function initAutocomplete() {
            const autocompleteField = $(this);
            autocompleteField.autocomplete({
                serviceUrl: baseUrl + 'admin/values',
                dataType: 'json',
                maxHeight: 600,
                paramName: 'q',
                params: {
                    prop: autocompleteField.closest('.resource-property').data('property-id'),
                    type: autocompleteField.closest('.resource-property').data('autocomplete'),
                },
                deferRequestBy: 200,
                // minChars: 3,
                // showNoSuggestionNotice: true,
                //. noSuggestionNotice: Omeka.jsTranslate('No results'),
                // Required, because when multiple characters are typed,
                // the previous requests are stopped.
                preventBadQueries: false,
                transformResult: function(response) {
                    return response.data;
                },
                onSearchStart: function(/*params*/) {
                    $(this).css('cursor', 'progress');
                },
                onSearchComplete: function(/*query, suggestions*/) {
                    $(this).css('cursor', 'default');
                },
                onSearchError: function(query, jqXHR, textStatus, errorThrown) {
                    // If there is no response, the request is aborted for autocompletion.
                    if (jqXHR.responseJSON) {
                        if (jqXHR.responseJSON.status === 'fail') {
                            alert(jqXHR.responseJSON.data.suggestions);
                        } else {
                            alert(jqXHR.responseJSON.message);
                        }
                        autofillerField.autocomplete().dispose();
                    }
                },
            });
        }

        function jsonDecodeObject(string) {
            try {
                const obj = JSON.parse(string);
                return obj && typeof obj === 'object' && Object.keys(obj).length ? obj : null;
            } catch (e) {
                return null;
            }
        }

        /**
         * Determine the resource type (routing).
         *
         * @todo  Determine the resource type in a cleaner way (cf. fix #omeka/omeka-s/1655).
         */
        function typeResource() {
            const resourceType = $('#select-resource.sidebar').find('#sidebar-resource-search').data('search-url');
            return resourceType.substring(resourceType.lastIndexOf('/admin/') + 7, resourceType.lastIndexOf('/sidebar-select'));
        }

        $(document).on('o:form-loaded', 'form.resource-form', function() {
            if (typeof $('#resource-values').data('is-loaded') === 'undefined') {
                prepareAutofiller();
                $('#resource-values').data('is-loaded', $('#resource-template-select').val());
            }
            prepareValueDisplay();
            prepareFieldDisplay();
        });

        $(document).on('o:template-applied', 'form.resource-form', function() {
            const templateData = $('#resource-values').data('template-data');
            const hasTemplate = $('#resource-template-select').val() != '';
            if ((hasTemplate && templateData && valueIsTrue(templateData.closed_property_list))
                || (!hasTemplate && $('form.resource-form').hasClass('closed-property-list'))
            ) {
                sidebarPropertySelector.removeClass('always-open');
                $('form.resource-form #property-selector-button').hide();
                Omeka.closeSidebar(sidebarPropertySelector);
            } else {
                Omeka.openSidebar(sidebarPropertySelector);
                sidebarPropertySelector.addClass('always-open');
                $('form.resource-form #property-selector-button').show();
            }

            prepareValueDisplay();

            prepareResourceClassSelect();

            if (!hasTemplate) {
                return;
            }

            const fields = $('#properties .resource-property');

            if (!$('#resource-values').data('locked-ready')) {
                fields.each(function(index, field) {
                    prepareFieldLocked($(field));
                });
                $('#resource-values').data('locked-ready', true);
            }

            fields.each(function(index, field) {
                prepareFieldInputControl($(field));
                prepareFieldLength($(field));
                prepareFieldMinValues($(field));
                prepareFieldMaxValues($(field));
                prepareFieldCustomVocabOpen($(field));
                prepareFieldReadOnly($(field));
                prepareFieldAutocomplete($(field));
                prepareFieldLanguage($(field));
                prepareFieldValueAnnotation($(field));
                prepareFieldDisplay($(field));
            });

            if (typeof $('#resource-values').data('is-loaded') !== 'undefined') {
                prepareAutofiller();
            }
        });

        $(document).on('o:property-added', '.resource-property', function() {
            const field = $(this);
            // This is managed after values.
            // This is useless for this event: added properties have no specific params.
            // prepareFieldInputControl($(field));
            // prepareFieldLength(field);
            // prepareFieldMinValues(field);
            // prepareFieldMaxValues(field);
            // prepareFieldCustomVocabOpen($(field));
            prepareFieldReadOnly(field);
            prepareFieldAutocomplete(field);
            // prepareFieldLanguage($(field));
            prepareFieldValueAnnotation($(field));
            prepareFieldDisplay(field);
        });

        $(document).on('o:prepare-value', function(e, dataType, value, valueObj) {
            prepareValueDisplay(value);

            const term = value.data('term');
            var field = value.closest('.resource-property');
            if (!field.length) {
                field = $('#properties [data-property-term="' + term + '"].field')
                    .filter(function() { return $.inArray(dataType, $(this).data('data-types').split(',')) > -1; }).first();
                if (!field.length) {
                    return;
                }
            }

            const rtpData = field.data('template-property-data');
            if (!rtpData) {
                return;
            }

            const selector = field.data('selector') ? field.data('selector') : 'default';
            if (rtpData.max_values && field.find('.inputs .values > .value').length + 1 >= rtpData.max_values) {
                field.find('.add-values.' + selector + '-selector').hide();
            } else {
                field.find('.add-values.' + selector + '-selector').show();
            }

            if (field.data('autocomplete')) {
                value.find('textarea.input-value').addClass('autocomplete');
                value.find('textarea.input-value.autocomplete').each(initAutocomplete);
            }

            if (dataType === 'literal') {
                // Html input pattern does not work on textarea, so replace the textarea
                // by an input.
                if (rtpData.input_control) {
                    const textarea = value.find('textarea.input-value');
                    textarea.addClass('inputcontrol');
                    textarea.removeClass('input-value to-require');
                    textarea.after($('<input/>', { type: 'text', class: 'input-value to-require', 'data-value-key': '@value' }));
                    textarea.hide();
                    const input = value.find('input.input-value');
                    input.attr('pattern', rtpData.input_control);
                    input.val('');
                }
                if (rtpData.min_length) {
                    value.find('textarea.input-value').attr('minlength', rtpData.min_length);
                }
                if (rtpData.max_length) {
                    value.find('textarea.input-value').attr('maxlength', rtpData.max_length);
                }
            }

            if (dataType.startsWith('customvocab:')) {
                if (valueIsTrue(rtpData.custom_vocab_open)) {
                    const select = value.find('select.custom-vocab-literal');
                    prepareCustomVocabOpenInput(select);
                }
            }

            const templateData = $('#resource-values').data('template-data');
            var listName = templateData && templateData.value_languages && !$.isEmptyObject(templateData.value_languages)
                ? 'value-languages-template'
                : 'value-languages';
            listName = rtpData.value_languages && !$.isEmptyObject(rtpData.value_languages)
                ? 'value-languages-' + term
                : listName;
            value.find('input.value-language').attr('list', listName);

            fillDefaultLanguage(value, valueObj, field);

            // Fill a new value with the setting specified in the template.
            // This is not the same than the default value in resource-form.js, that is an empty value.
            if (!rtpData.default_value || !rtpData.default_value.trim().length
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
                || field.find('input.numeric-datetime-value').length > 0
                || field.find('input.numeric-duration-value').length > 0
                || field.find('input.numeric-integer-value').length > 0
                // Value suggest.
                || field.find('input.valuesuggest-input').length > 0
            ) {
                return;
            } else {
                const defaultValue = jsonDecodeObject(rtpData.default_value);
                fillValue(value, term, dataType, defaultValue === null ? { default: rtpData.default_value.trim() } : defaultValue);
                if (rtpData.max_values && field.find('.inputs .values > .value').length + 1 >= rtpData.max_values) {
                    field.find('.add-values.' + selector + '-selector').hide();
                }
            }
        });

        /**
         * Display the button "add value" in all cases: if there is a max number of
         * values, the button will be removed.
         *
         * @todo Manage the issue when there are two values, but only one max.
         */
        $(document).on('click', 'a.remove-value', function(e) {
            var field = $(this).closest('.resource-property');
            const rtpData = field.data('template-property-data');
            if (!rtpData) {
                return;
            }
            const selector = field.data('selector') ? field.data('selector') : 'default';
            field.find('.add-values.' + selector + '-selector').show();
        });

        /**
         * Enable/disable quick creation of a resource, except media. Default is enabled.
         **/

        var modal;

        // Append the button to create a new resource, except media.
        $(document).on('o:sidebar-content-loaded', '#select-resource.sidebar', function(e) {
            const sidebar = $(this);
            const resourceType = typeResource();
            const isManagedResourceType = resourceType && resourceType !== 'media';
            const templateData = $('#resource-values').data('template-data');
            const templatePropertyData = sidebar.data('template-property-data');
            const quickNewResourceTemplate = templateData && !valueIsFalse(templateData.quick_new_resource);
            const quickNewResourceTemplateProperty = typeof templatePropertyData === 'object'
                ? (typeof templatePropertyData.quick_new_resource === 'undefined' || templatePropertyData.quick_new_resource === ''
                    ? quickNewResourceTemplate
                    : !valueIsFalse(templatePropertyData.quick_new_resource)
                )
                : quickNewResourceTemplate;
            if (isManagedResourceType && quickNewResourceTemplateProperty) {
                const iconResourceType = resourceType === 'media' ? 'media' : resourceType + 's';
                const button = `<div class="quick-new-resource" data-data-type="resource:${resourceType}">
            <a class="o-icon-${iconResourceType} button quick-add-resource" href="${baseUrl + 'admin/' + resourceType}/add?window=modal" target="_blank"> ${Omeka.jsTranslate('New ' + resourceType.replace('-', ' '))}</a>
        </div>`;
                sidebar.find('.search-nav').after(button)
            } else {
                sidebar.find('.quick-new-resource').remove()
            }
        });

        // Allow to create a new resource in a modal window during edition of another resource.
        $(document).on('click', '.quick-add-resource', function(e) {
            e.preventDefault();
            // Save the modal in local storage to allow recursive new resources.
            const d = new Date();
            const windowName = 'new resource ' + d.getTime();
            const windowFeatures = 'titlebar=no,menubar=no,location=no,resizable=yes,scrollbars=yes,status=yes,directories=no,fullscreen=no,top=90,left=120,width=830,height=700';
            modal = window.open(e.target.href, windowName, windowFeatures);
            window.localStorage.setItem('modal', modal);
            // Check if the modal is closed, then refresh the list of resources.
            const checkSidebarModal = setInterval(function() {
                if (modal && modal.closed) {
                    clearInterval(checkSidebarModal);
                    // Wait to let Omeka saves the new resource, if any.
                    setTimeout(function() {
                        const s = $('#sidebar-resource-search');
                        Omeka.populateSidebarContent(s.closest('.sidebar'), s.data('search-url'), '');
                    }, 2000);
                }
            }, 100);
            return false;
        });

        // Add a new resource on modal window.
        $(document).on('click', '.modal form.resource-form #page-actions button[type=submit]', function(e) {
            // Warning: the submit may not occur when the modal is not focus.
            $('form.resource-form').submit();
            // TODO Manage error after submission (via ajax post?).
            // To avoid most issues for now, tab "Media" and "Thumbnail" are hidden.
            // Anyway, the user is working on the main resource.
            if ($('form.resource-form').data('has-error') === true) {
                e.preventDefault();
            } else {
                window.localStorage.removeItem('modal');
                // Leave time to submit the form before closing form.
                setTimeout(function() {
                    window.close();
                }, 1000);
            }
            return false;
        });

        // Cancel modal window.
        $(document).on('click', '.modal form.resource-form #page-actions a.cancel', function(e) {
            e.preventDefault();
            window.localStorage.removeItem('modal');
            window.close();
            return false;
        });

    });

})(jQuery);
