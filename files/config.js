// Turns the enabled-projects multi-selects on the plugin config page into
// searchable select2 tag pickers. Loaded as an external file (not inline) so it
// passes the Mantis Content-Security-Policy, which blocks inline scripts
// (script-src 'self'). jQuery and select2 are already loaded on the page.
(function () {
    function init() {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') {
            return;
        }
        jQuery('.imatic-el-project-select').each(function () {
            var $select = jQuery(this);
            $select.select2({
                width: '100%',
                allowClear: true,
                placeholder: $select.data('placeholder') || '',
                // Mantis renders subprojects with a leading indent in the option
                // text; keep it as-is so the hierarchy stays readable.
                dropdownParent: $select.parent()
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
