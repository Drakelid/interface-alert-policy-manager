{{-- Single place for IAPM styling and behaviour, included once per page by the nav partial. --}}
<style>
:root {
    --iapm-critical:#d9534f; --iapm-warning:#f0ad4e; --iapm-info:#5bc0de; --iapm-ok:#5cb85c; --iapm-muted:#888;
}
/* Secondary/help text. Bootstrap 3's .text-muted is #777, which is 4.48:1 on
   white -- just under WCAG AA -- and far worse on the dark theme. LibreNMS
   toggles dark mode with a .dark class on <html>, so both themes are covered.
   #595959 is 7.0:1 on white; #adb5bd is >7:1 on the dark panel backgrounds. */
.iapm-hint { color:#595959; }
.dark .iapm-hint { color:#adb5bd; }
/* Filter and bulk-action bars. Bootstrap 3's .form-inline gives inline controls
   no gap, which is how the matrix checkboxes ended up butted against their own
   labels and the adjacent buttons (P2-6). A grid gives every control room and
   wraps predictably at narrow widths. */
.iapm-field-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:10px 14px; align-items:end; }
.iapm-field-grid .form-group { margin-bottom:0; }
.iapm-field-grid label { display:block; margin-bottom:3px; font-weight:600; font-size:12px; }
.iapm-checkbox-row { display:flex; flex-wrap:wrap; align-items:center; gap:6px 18px; margin-top:12px; padding-top:10px; border-top:1px solid rgba(128,128,128,.25); }
.iapm-checkbox-row .checkbox-inline { margin:0; padding-left:20px; }
.iapm-filter-actions { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; }
/* Keeps a button aligned with the inputs beside it without an empty-looking gap. */
.iapm-invisible-label { visibility:hidden; }
/* Number inputs used to stretch the full ~1360px page width (P2-1). */
.iapm-narrow-field { max-width:320px; }
.iapm-chips { display:flex; flex-wrap:wrap; gap:4px; align-items:center; margin-top:6px; }
.iapm-chips .iapm-chip { font-family:monospace; font-size:11px; }
.iapm-sms-counter { margin:4px 0 0; font-variant-numeric:tabular-nums; }
/* Schedule editor time ranges (P1-5) */
.iapm-period { display:flex; align-items:center; gap:6px; margin-bottom:4px; }
.iapm-period input[type=time] { width:auto; }
/* Save belongs to the whole form, not the bottom of one column (P2-4). */
.iapm-form-footer { margin-top:16px; padding-top:12px; border-top:1px solid rgba(128,128,128,.25); display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.iapm-result-count { margin:0; }
.iapm-result-bar { display:flex; flex-wrap:wrap; align-items:center; gap:8px 16px; margin:8px 0; }
.iapm-per-page { margin-left:auto; display:flex; align-items:center; gap:6px; }
.iapm-per-page label { margin:0; font-weight:normal; font-size:12px; }
.iapm-per-page select { width:auto; display:inline-block; }
/* Sortable headings: the idle indicator stays faint until hover/active so a
   dozen arrows do not compete with the data. */
th a .iapm-sort-idle { opacity:.3; }
th a:hover .iapm-sort-idle { opacity:.7; }
/* Pages started at h2 with no h1 (P3-3). The h1 keeps the visual weight the h2
   had rather than jumping to the browser default. */
h1.iapm-page-title { font-size:24px; margin:0 0 10px; }
.iapm-toolbar { margin-bottom:10px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.iapm-toolbar .spacer { flex:1 1 auto; }
.iapm-num { text-align:right; font-variant-numeric:tabular-nums; }
.iapm-truncate { max-width:280px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.iapm-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
.iapm-tile { border-left:4px solid transparent; }
.iapm-tile.crit { border-left-color:var(--iapm-critical); }
.iapm-tile.warn { border-left-color:var(--iapm-warning); }
.iapm-tile.ok   { border-left-color:var(--iapm-ok); }
.iapm-tile.info { border-left-color:var(--iapm-info); }
.iapm-tile.crit.hot .panel-body strong { color:var(--iapm-critical); }
.iapm-tile.warn.hot .panel-body strong { color:var(--iapm-warning); }
.iapm-state { display:inline-block; }
.iapm-actions form { display:inline; }
.iapm-actions .btn { margin:1px; }
.iapm-toasts { position:fixed; top:64px; right:16px; z-index:1080; width:340px; max-width:90vw; }
.iapm-toasts .alert { box-shadow:0 2px 8px rgba(0,0,0,.2); }
.iapm-spark { vertical-align:middle; }
.iapm-nav .nav-pills { margin-bottom:6px; }
.iapm-quickfind { max-width:210px; }
@media (max-width:768px) {
    .iapm-nav .nav-pills > li { float:none; display:inline-block; }
    .iapm-hide-sm { display:none; }
    .iapm-truncate { max-width:130px; }
}
/* Sticky header for long tables (opt in with .iapm-sticky on the table) */
.iapm-sticky thead th { position:sticky; top:0; z-index:2; }
</style>

<div class="iapm-toasts" id="iapm-toasts"></div>

<script>
(function () {
    // --- Toasts: promote flagged flash alerts into dismissible toasts ---
    var host = document.getElementById('iapm-toasts');
    document.querySelectorAll('.iapm-toast').forEach(function (el) {
        host.appendChild(el);
        if (! el.classList.contains('alert-danger')) {
            setTimeout(function () { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 400); }, 6000);
        }
    });

    // --- Button loading state on submit (forms/buttons with data-iapm-busy) ---
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.matches && form.matches('[data-iapm-busy]')) {
            var btn = form.querySelector('button[type=submit], button:not([type])');
            if (btn && ! btn.disabled) {
                btn.dataset.label = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + (btn.dataset.busy || 'Working…');
                // Safety: re-enable if the navigation is cancelled.
                setTimeout(function () { if (btn.disabled) { btn.disabled = false; btn.innerHTML = btn.dataset.label; } }, 15000);
            }
        }
    }, true);

    // --- Click-to-insert placeholder chips (P2-1 / P4-4) ---
    document.querySelectorAll('[data-iapm-chip-target]').forEach(function (group) {
        var target = document.querySelector(group.dataset.iapmChipTarget);
        if (! target) { return; }
        group.addEventListener('click', function (e) {
            var chip = e.target.closest('[data-iapm-chip]');
            if (! chip) { return; }
            // Built from single braces on purpose: a literal '{{' in this file
            // would be compiled by Blade as an echo rather than emitted as JS.
            var token = '{' + '{ ' + chip.dataset.iapmChip + ' }' + '}';
            var start = target.selectionStart || 0;
            var end = target.selectionEnd || 0;
            target.value = target.value.slice(0, start) + token + target.value.slice(end);
            // Leave the caret after the inserted token so several can be added
            // in a row without reaching for the mouse again.
            target.selectionStart = target.selectionEnd = start + token.length;
            target.focus();
            target.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    // --- Character and SMS-segment counter (P4-4) ---
    // The primary destination type is an SMS gateway, so message length is
    // operationally significant: GSM-7 fits 160 characters in one segment, and
    // 153 per segment once a message is split.
    document.querySelectorAll('[data-iapm-sms-counter]').forEach(function (field) {
        var readout = document.createElement('p');
        readout.className = 'iapm-hint iapm-sms-counter';
        readout.setAttribute('aria-live', 'polite');
        field.insertAdjacentElement('afterend', readout);
        function update() {
            var length = field.value.length;
            if (! length) { readout.textContent = 'Empty — the built-in default for this phase is used.'; return; }
            var segments = length <= 160 ? 1 : Math.ceil(length / 153);
            readout.textContent = length + ' character' + (length === 1 ? '' : 's') + ' · ' + segments + ' SMS segment' + (segments === 1 ? '' : 's') +
                (segments > 1 ? ' (each segment is billed separately)' : '');
        }
        field.addEventListener('input', update);
        update();
    });

    // --- Selects that navigate to their chosen option's URL (per-page, P1-6) ---
    document.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('select[data-iapm-navigate]') && e.target.value) {
            window.location.href = e.target.value;
        }
    });

    // --- Header quick-find suggestions (P1-2) ---
    // Distinct from the field type-aheads above: this one navigates rather than
    // filling a hidden id, so picking a result lands on that exact interface.
    document.querySelectorAll('[data-iapm-quickfind]').forEach(function (form) {
        var input = form.querySelector('input[name=search]');
        var results = form.querySelector('[data-iapm-quickfind-results]');
        var timer = null;

        function close() { results.style.display = 'none'; results.innerHTML = ''; input.setAttribute('aria-expanded', 'false'); }

        input.addEventListener('input', function () {
            var term = input.value.trim();
            clearTimeout(timer);
            if (term.length < 2) { close(); return; }
            timer = setTimeout(function () {
                fetch(form.dataset.iapmQuickfind + '?q=' + encodeURIComponent(term), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : []; })
                    .then(function (list) {
                        results.innerHTML = '';
                        if (! list.length) { close(); return; }
                        list.forEach(function (item) {
                            var a = document.createElement('a');
                            a.className = 'list-group-item';
                            a.setAttribute('role', 'option');
                            a.textContent = item.label;
                            a.href = form.dataset.iapmQuickfindTarget + '?port_id=' + encodeURIComponent(item.id);
                            results.appendChild(a);
                        });
                        results.style.display = 'block';
                        input.setAttribute('aria-expanded', 'true');
                    })
                    .catch(close);
            }, 200);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { close(); }
            // Enter keeps its existing behaviour: submit the name search.
        });
        document.addEventListener('click', function (e) { if (! form.contains(e.target)) { close(); } });
    });

    // --- Delegated confirmation for destructive actions (P3-6) ---
    // A delegated listener rather than inline onsubmit/onclick, so the plugin
    // stays usable under a Content-Security-Policy without 'unsafe-inline'.
    // data-iapm-confirm may sit on the form or on the submitting control.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (! form.matches || ! form.matches('form')) { return; }
        var source = (e.submitter && e.submitter.dataset && e.submitter.dataset.iapmConfirm) ? e.submitter : form;
        var message = source.dataset ? source.dataset.iapmConfirm : null;
        if (message && ! window.confirm(message)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    // --- Name-for-id type-ahead (P1-2) ---
    // One implementation for every picker; markup comes from partials/typeahead.
    document.querySelectorAll('[data-iapm-typeahead]').forEach(function (root) {
        var input = root.querySelector('[data-iapm-typeahead-input]');
        var hidden = root.querySelector('[data-iapm-typeahead-value]');
        var results = root.querySelector('[data-iapm-typeahead-results]');
        var endpoint = root.dataset.iapmTypeahead;
        var dependsOn = root.dataset.iapmTypeaheadDepends;
        var timer = null;

        function close() { results.style.display = 'none'; results.innerHTML = ''; input.setAttribute('aria-expanded', 'false'); }

        // The port picker keeps a visible raw-id box alongside the search, for
        // operators who already have the number. data-iapm-mirror keeps the two
        // in step in both directions rather than making them rival inputs.
        var mirror = hidden.dataset.iapmMirror ? document.querySelector(hidden.dataset.iapmMirror) : null;

        function choose(item) {
            input.value = item.label;
            hidden.value = item.id;
            if (mirror) { mirror.value = item.id; }
            close();
            // Filter bars submit on change; let listeners know the id moved.
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (mirror) {
            mirror.addEventListener('input', function () {
                // A hand-typed id wins; the stale label beside it would lie.
                if (mirror.value !== hidden.value) { hidden.value = mirror.value; input.value = ''; }
            });
        }

        input.addEventListener('input', function () {
            // Clearing the box clears the id: a stale id behind an edited label
            // is how these pickers silently filter by the wrong thing.
            hidden.value = '';
            var term = input.value.trim();
            clearTimeout(timer);
            if (term === '') { close(); return; }
            timer = setTimeout(function () {
                var url = endpoint + '?q=' + encodeURIComponent(term);
                if (dependsOn) {
                    var dep = document.getElementById(dependsOn);
                    if (dep && dep.value) { url += '&device_id=' + encodeURIComponent(dep.value); }
                }
                fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : []; })
                    .then(function (list) {
                        results.innerHTML = '';
                        if (! list.length) {
                            var none = document.createElement('span');
                            none.className = 'list-group-item iapm-hint';
                            none.textContent = 'No matches';
                            results.appendChild(none);
                        }
                        list.forEach(function (item) {
                            var a = document.createElement('a');
                            a.href = '#';
                            a.className = 'list-group-item';
                            a.setAttribute('role', 'option');
                            a.textContent = item.label;
                            a.addEventListener('click', function (e) { e.preventDefault(); choose(item); });
                            results.appendChild(a);
                        });
                        results.style.display = 'block';
                        input.setAttribute('aria-expanded', 'true');
                    })
                    .catch(close);
            }, 200);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { close(); return; }
            if (e.key !== 'ArrowDown' && e.key !== 'Enter') { return; }
            var first = results.querySelector('a');
            if (! first) { return; }
            e.preventDefault();
            first.focus();
            if (e.key === 'Enter') { first.click(); }
        });

        document.addEventListener('click', function (e) {
            if (! root.contains(e.target)) { close(); }
        });
    });

    // --- Ingestion token reveal / copy (P0-5) ---
    // Slots carry the surrounding text in data-iapm-token-template with a
    // __TOKEN__ marker, so one mechanism serves both the bare token field on
    // Settings and the paste-ready header block on the Setup Helper.
    function tokenSlots() { return document.querySelectorAll('[data-iapm-token-template]'); }
    function paintToken(value) {
        tokenSlots().forEach(function (slot) {
            var text = slot.dataset.iapmTokenTemplate.replace('__TOKEN__', value);
            if ('value' in slot && slot.tagName !== 'DIV') { slot.value = text; } else { slot.textContent = text; }
        });
    }
    document.querySelectorAll('[data-iapm-reveal-token]').forEach(function (btn) {
        var mask = btn.dataset.iapmTokenMask || '••••••••••••••••';
        btn.addEventListener('click', function () {
            if (btn.dataset.shown === '1') {
                paintToken(mask);
                btn.dataset.shown = '0';
                btn.innerHTML = '<i class="fa fa-eye"></i> Reveal';
                return;
            }
            btn.disabled = true;
            fetch(btn.dataset.iapmRevealToken, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { if (! r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                .then(function (data) {
                    paintToken(data.token);
                    btn.dataset.shown = '1';
                    btn.innerHTML = '<i class="fa fa-eye-slash"></i> Hide';
                })
                .catch(function (err) {
                    btn.innerHTML = '<i class="fa fa-exclamation-triangle"></i> Unavailable';
                    btn.title = 'Could not read the token (' + err.message + '). You may not have permission to manage IAPM settings.';
                })
                .finally(function () { btn.disabled = false; });
        });
    });

    // Copy a literal value (per-row ids in tables, where a selector would need
    // a unique element id for every row).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-iapm-copy-text]');
        if (! btn) { return; }
        e.preventDefault();
        navigator.clipboard.writeText(btn.dataset.iapmCopyText).then(function () {
            var original = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-check"></i>';
            setTimeout(function () { btn.innerHTML = original; }, 1200);
        });
    });

    // Copy the live contents of the element named by data-copy.
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        if (btn.dataset.iapmCopyBound === '1') { return; }
        btn.dataset.iapmCopyBound = '1';
        btn.addEventListener('click', function () {
            var el = document.querySelector(btn.dataset.copy);
            if (! el) { return; }
            var text = ('value' in el && el.tagName !== 'DIV') ? el.value : el.textContent;
            navigator.clipboard.writeText(text).then(function () {
                var original = btn.innerHTML;
                btn.innerHTML = '<i class="fa fa-check"></i> Copied';
                setTimeout(function () { btn.innerHTML = original; }, 1500);
            });
        });
    });

    // --- Auto-refresh (opt in with an element #iapm-autorefresh[data-interval]) ---
    var ar = document.getElementById('iapm-autorefresh');
    if (ar) {
        var key = 'iapm.autorefresh.' + location.pathname;
        var on = localStorage.getItem(key) === '1';
        var interval = parseInt(ar.dataset.interval || '30', 10);
        var box = ar.querySelector('input[type=checkbox]');
        var stamp = ar.querySelector('.iapm-updated');
        var loadedAt = Date.now();
        if (box) { box.checked = on; box.addEventListener('change', function () { localStorage.setItem(key, box.checked ? '1' : '0'); schedule(); }); }
        function tick() { if (stamp) { var s = Math.round((Date.now() - loadedAt) / 1000); stamp.textContent = 'updated ' + s + 's ago'; } }
        var timer = null, poll = setInterval(tick, 1000);
        function schedule() { if (timer) clearTimeout(timer); if (box && box.checked) { timer = setTimeout(function () { location.reload(); }, interval * 1000); } }
        schedule();
    }
})();
</script>
