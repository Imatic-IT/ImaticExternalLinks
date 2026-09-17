// Dependency-free substring filter over each enabled-projects multi-select on the
// plugin config page. Loaded as an external file (not inline) so it passes the
// Mantis Content-Security-Policy, which blocks inline scripts (script-src 'self').
(function () {
    function init() {
        var filters = document.querySelectorAll('.imatic-el-project-filter');
        Array.prototype.forEach.call(filters, function (filter) {
            var select = document.getElementById(filter.getAttribute('data-target'));
            if (!select) {
                return;
            }
            filter.addEventListener('input', function () {
                var query = filter.value.trim().toLowerCase();
                Array.prototype.forEach.call(select.options, function (option) {
                    option.hidden = query !== '' && option.text.toLowerCase().indexOf(query) === -1;
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
