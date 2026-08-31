(function($) {

    $(document).ready(function() {

        var sidebar = $('#apply-template-sidebar');
        if (!sidebar.length) {
            return;
        }

        // Display the fixes only when the mode is not the audit.
        var $submitBtn = sidebar.find('button[type="submit"]');
        var labelAudit = $submitBtn.data('label-audit');
        var labelFix = $submitBtn.data('label-fix');

        sidebar.on('change', 'input[name="fix"]', function() {
            var isFix = this.value === '1';
            var audits = $('#apply-template-audit-options');
            var fixes = $('#apply-template-options');
            audits.prop('disabled', isFix).prop('hidden', isFix);
            fixes.prop('disabled', !isFix).prop('hidden', !isFix);
            (isFix ? audits : fixes)
                .find('input[type="checkbox"]')
                .prop('checked', false);
            $submitBtn.text(isFix ? labelFix : labelAudit);
        });

        // Close other dropdowns when opening one.
        $('.page-action-menu').on('click', 'a.expand, a.collapse', function() {
            $(this).closest('.with-sub-menu')
                .siblings('.with-sub-menu')
                .find('a.collapse')
                .removeClass('collapse').addClass('expand')
                .attr('aria-label', Omeka.jsTranslate('Expand'))
                .attr('title', Omeka.jsTranslate('Expand'));
        });

        // Close this sidebar when another sidebar opens
        // (e.g. Details or Delete via the three-dots icon).
        $('body').on('o:sidebar-opened', '.sidebar', function() {
            if (!sidebar.is(this) && sidebar.hasClass('active')) {
                Omeka.closeSidebar(sidebar);
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
