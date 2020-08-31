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
    if (data.default_value && data.default_value.length
        // The value from the object is already managed.
        && !valueObj
        // Don't add a value if this is an edition.
        && $('body').hasClass('add')
        // Don't add a value if there is already a value.
        && field.find('.input-value').length === 0
    ) {
        value.find('.input-value').val(data.default_value);
    }
});

});
