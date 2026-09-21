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

    // Shared Nextcloud folder-browser modal: pick a folder for the row that
    // opened it, instead of typing the path. Browses the service account tree
    // via ajax_nc_config_browse.php (admin-only, folders only).
    function initFolderBrowser() {
        var container = document.getElementById('imatic-el-ncpf');
        var modal = document.getElementById('imatic-el-ncbrowse');
        if (!container || !modal) {
            return;
        }
        var browseUrl = container.getAttribute('data-browse-url');
        var listEl = modal.querySelector('.imatic-el-ncbrowse-list');
        var pathEl = modal.querySelector('.imatic-el-ncbrowse-path');
        var upBtn = modal.querySelector('#imatic-el-ncbrowse-up');
        var pickBtn = modal.querySelector('#imatic-el-ncbrowse-pick');
        var tokenEl = document.querySelector('input[name="plugin_imatic_external_links_config_token"]');
        var token = tokenEl ? tokenEl.value : '';
        var current = '/';
        var target = null;   // the .imatic-el-ncpf-path input to fill
        var loading = false; // block "select" while a listing is still loading
        var reqId = 0;       // ignore responses from superseded requests

        function open(input) {
            target = input;
            modal.hidden = false;
            load(input.value && input.value.charAt(0) === '/' ? input.value : '/');
        }
        function close() {
            modal.hidden = true;
            target = null;
        }
        function setLoading(on) {
            loading = on;
            pickBtn.disabled = on;
            upBtn.disabled = on || current === '/';
        }
        function render(entries) {
            listEl.innerHTML = '';
            if (!entries.length) {
                var empty = document.createElement('li');
                empty.className = 'imatic-el-ncbrowse-empty';
                empty.textContent = 'Žádné podsložky.';
                listEl.appendChild(empty);
                return;
            }
            entries.forEach(function (e) {
                var li = document.createElement('li');
                var icon = document.createElement('i');
                icon.className = 'ace-icon fa fa-folder';
                li.appendChild(icon);
                li.appendChild(document.createTextNode(e.name));
                li.addEventListener('click', function () { if (!loading) { load(e.path); } });
                listEl.appendChild(li);
            });
        }
        function load(path) {
            // Commit the target path up-front so "Vybrat" is correct even if it's
            // clicked before the listing finishes; the response confirms it.
            current = path || '/';
            pathEl.textContent = current;
            setLoading(true);
            listEl.innerHTML = '<li class="imatic-el-ncbrowse-empty"><i class="ace-icon fa fa-spinner fa-spin"></i> Načítání…</li>';
            var myReq = ++reqId;
            var body = new URLSearchParams();
            body.set('path', path);
            body.set('plugin_imatic_external_links_config_token', token);
            fetch(browseUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    if (myReq !== reqId) { return; } // superseded by a newer navigation
                    setLoading(false);
                    if (!res.ok) {
                        listEl.innerHTML = '<li class="imatic-el-ncbrowse-empty">' + (res.j && res.j.error ? res.j.error : 'Chyba') + '</li>';
                        return;
                    }
                    current = res.j.path || '/';
                    pathEl.textContent = current;
                    upBtn.disabled = current === '/';
                    render(res.j.entries || []);
                })
                .catch(function () {
                    if (myReq !== reqId) { return; }
                    setLoading(false);
                    listEl.innerHTML = '<li class="imatic-el-ncbrowse-empty">Chyba připojení.</li>';
                });
        }

        container.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.imatic-el-ncpf-browse') : null;
            if (!btn) { return; }
            var row = btn.closest('.imatic-el-ncpf-row');
            var input = row && row.querySelector('.imatic-el-ncpf-path');
            if (input) { open(input); }
        });
        upBtn.addEventListener('click', function () {
            if (loading || current === '/') { return; }
            var parent = current.replace(/\/[^/]+$/, '');
            load(parent === '' ? '/' : parent);
        });
        pickBtn.addEventListener('click', function () {
            if (loading) { return; }
            if (target) { target.value = current; }
            close();
        });
        modal.querySelector('#imatic-el-ncbrowse-cancel').addEventListener('click', close);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) { close(); } // backdrop click
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
        initFolderBrowser();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
