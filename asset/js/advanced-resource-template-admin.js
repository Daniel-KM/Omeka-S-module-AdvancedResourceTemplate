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

    if (data.default_value && data.default_value.length && !valueObj) {
        value.find('textarea.input-value').val(data.default_value);
    }
});

});
