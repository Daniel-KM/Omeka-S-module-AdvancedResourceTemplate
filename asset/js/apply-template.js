(function($) {

    $(document).ready(function() {

        var sidebar = $('#apply-template-sidebar');

        // Toggle the options fieldset when the fix checkbox
        // changes.
        var $submitBtn = sidebar.find('button[type="submit"]');
        var labelAudit = $submitBtn.data('label-audit');
        var labelFix = $submitBtn.data('label-fix');

        $('#apply-template-fix').on('change', function() {
            var fieldset = $('#apply-template-options');
            if (this.checked) {
                fieldset.prop('disabled', false);
                $submitBtn.text(labelFix);
            } else {
                fieldset.prop('disabled', true);
                fieldset.find('input[type="checkbox"]')
                    .prop('checked', false);
                $submitBtn.text(labelAudit);
            }
        });

        // Open the sidebar when a trigger button is clicked.
        // Use event delegation so dynamically added triggers
        // work too.
        $(document).on('click', '.apply-template-trigger', function(e) {
            e.preventDefault();

            var trigger = $(this);
            var templateId = trigger.data('template-id');

            if (templateId) {
                sidebar.find('input[name="template_id"]')
                    .val(templateId);
            }

            var baseAction = sidebar.data('base-action');
            if (baseAction && templateId) {
                var action = baseAction.replace('__ID__', templateId);
                sidebar.find('form').attr('action', action);
            }

            var resourceCount = trigger.data('resource-count');
            if (typeof resourceCount !== 'undefined') {
                sidebar.find('.apply-template-resource-count')
                    .text(resourceCount);
            }

            Omeka.openSidebar(sidebar);
        });

    });

})(jQuery);
