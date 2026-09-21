// Config-page enhancements for ImaticExternalLinks. Loaded as an external file
// (not inline) so it passes the Mantis Content-Security-Policy (script-src
// 'self'). jQuery and select2 are already loaded on the page.
(function () {
    function hasSelect2() {
        return typeof jQuery !== 'undefined' && typeof jQuery.fn.select2 !== 'undefined';
    }

    // Turn one <select> into a searchable select2, anchored to its own row so the
    // dropdown positions correctly even inside a flex row.
    function initSelect2(el) {
        var $select = jQuery(el);
        if ($select.data('select2')) {
            return; // already initialised (avoid double-init on clones)
        }
        $select.select2({
            width: '100%',
            allowClear: true,
            placeholder: $select.data('placeholder') || '',
            dropdownParent: $select.closest('.imatic-el-ncpf-row, td, body').first()
        });
    }

    // Per-project Nextcloud folder mapping: add/remove rows instead of hand-typing
    // "projectId = /folder". Each row is a project select2 + a folder input.
    function initProjectFolderRows() {
        var container = document.getElementById('imatic-el-ncpf');
        var addBtn = document.getElementById('imatic-el-ncpf-add');
        var template = document.getElementById('imatic-el-ncpf-template');
        if (!container || !addBtn || !template) {
            return;
        }

        if (addBtn.addEventListener) {
            addBtn.addEventListener('click', function () {
                var frag = template.content.cloneNode(true);
                var row = frag.querySelector('.imatic-el-ncpf-row');
                container.appendChild(frag);
                var sel = row.querySelector('.imatic-el-ncpf-project');
                if (sel && hasSelect2()) {
                    initSelect2(sel);
                }
            });
        }

        // Remove a row (delegated, so it covers rows added later too).
        container.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.imatic-el-ncpf-del') : null;
            if (!btn) {
                return;
            }
            var row = btn.closest('.imatic-el-ncpf-row');
            if (row) {
                row.parentNode.removeChild(row);
            }
        });
    }

    function init() {
        if (hasSelect2()) {
            jQuery('.imatic-el-project-select').each(function () {
                initSelect2(this);
            });
            jQuery('#imatic-el-ncpf .imatic-el-ncpf-project').each(function () {
                initSelect2(this);
            });
        }
        initProjectFolderRows();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
