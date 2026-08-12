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
.iapm-editor-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; align-items:start; }
.iapm-editor-grid .panel { margin-bottom:0; }
.iapm-editor-grid .panel-heading { font-weight:600; }
.iapm-editor-grid .panel-body > :last-child { margin-bottom:0; }
.iapm-compact-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:0 14px; }
.iapm-compact-grid .iapm-narrow-field { max-width:none; }
.iapm-code-example { display:block; padding:7px 9px; margin:6px 0; white-space:pre-wrap; overflow-wrap:anywhere; }
/* Rows of separate action forms. Inline forms have no gap of their own, which
   is how "Unacknowledge" and "Reconcile now" ended up touching (P2-6). */
.iapm-action-row { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
.iapm-action-row form { display:flex; align-items:center; gap:6px; margin:0; }
/* Settings jump list and sticky save (P2-8) */
.iapm-section-nav { display:flex; flex-wrap:wrap; gap:6px 14px; align-items:center; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid rgba(128,128,128,.25); }
.iapm-sticky-save { position:sticky; bottom:0; z-index:5; display:flex; gap:10px; align-items:center; padding:10px 12px; margin-top:14px;
    background:rgba(255,255,255,.96); border-top:1px solid rgba(128,128,128,.35); box-shadow:0 -2px 6px rgba(0,0,0,.08); }
.dark .iapm-sticky-save { background:rgba(30,34,38,.96); }
/* Anchored sections must not land under the sticky nav bar. */
[id]:target { scroll-margin-top:70px; }
.iapm-chips { display:flex; flex-wrap:wrap; gap:4px; align-items:center; margin-top:6px; }
.iapm-chips .iapm-chip { font-family:monospace; font-size:11px; }
.iapm-sms-counter { margin:4px 0 0; font-variant-numeric:tabular-nums; }
/* P4-4: an inline <code> holding a brace-delimited placeholder has no break
   opportunity, so it pushed past the container's right edge instead of
   wrapping. (Spelling the token out here would be compiled as a Blade echo.) */
.iapm-wrap-code code { white-space:normal; overflow-wrap:anywhere; }
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
/* A group heading that is not a control label: using <label> for it made the
   label point at nothing, which is worse than no label at all (P3-1). */
.iapm-field-legend { display:block; font-weight:600; margin-bottom:3px; }
.iapm-toolbar { margin-bottom:10px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.iapm-toolbar .spacer { flex:1 1 auto; }
.iapm-num { text-align:right; font-variant-numeric:tabular-nums; }
.iapm-truncate { max-width:280px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.iapm-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
/* P4-1: nine tiles on a 12-column Bootstrap grid wrapped 5-then-4, and each
   had a large empty area to the right of its number, so nine figures consumed
   most of the viewport. An auto-fitting grid wraps evenly at any width and the
   tiles are tight enough to read as a strip. It also fixes the Dry-run
   Comparison page, where the ninth card sat alone on its own row. */
.iapm-tile-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:8px; margin-bottom:12px; }
.iapm-tile-grid > a { text-decoration:none; display:block; }
.iapm-tile-grid .panel { margin-bottom:0; height:100%; }
.iapm-tile-grid .panel-heading { padding:5px 10px; font-size:12px; line-height:1.3; }
.iapm-tile-grid .panel-body { padding:6px 10px 8px; }
.iapm-tile-grid .panel-body strong { font-size:20px; line-height:1.1; }
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
/* P4-7: the chart inherits currentColor, so it follows the theme's text colour
   in both light and dark rather than needing a palette of its own. */
.iapm-chart { display:block; max-width:100%; }
.iapm-chart-empty { text-align:center; padding:24px 16px; }
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
    function initializeIapmControls() {
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

    // --- Declarative replacements for inline handlers (P3-6) ---
    // Inline onclick/onchange would require 'unsafe-inline' in a CSP.
    document.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('[data-iapm-submit-on-change]') && e.target.form) {
            e.target.form.submit();
        }
    });
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest && e.target.closest('[data-iapm-toggle-all]');
        if (! toggle) { return; }
        var scope = toggle.closest('table') || document;
        scope.querySelectorAll(toggle.dataset.iapmToggleAll).forEach(function (box) {
            box.checked = toggle.checked;
        });
        // Let the bulk-selection watcher recount.
        scope.dispatchEvent(new Event('change', { bubbles: true }));
    });

    // --- Bulk actions follow the selection (P2-11) ---
    // A prominent red "Delete selected" that is enabled with nothing ticked is
    // an invitation to click it and find out. The button stays disabled until
    // something is selected and says how many.
    document.querySelectorAll('[data-iapm-bulk-scope]').forEach(function (scope) {
        var checkboxes = function () { return scope.querySelectorAll('input[type=checkbox].iapm-bulk, input[type=checkbox].iapm-port'); };
        var buttons = document.querySelectorAll('[data-iapm-bulk-button="' + scope.dataset.iapmBulkScope + '"]');
        if (! buttons.length) { return; }

        function update() {
            var selected = 0;
            checkboxes().forEach(function (box) { if (box.checked) { selected++; } });
            buttons.forEach(function (button) {
                button.disabled = selected === 0;
                var label = button.querySelector('[data-iapm-bulk-count]');
                if (label) { label.textContent = selected ? ' (' + selected + ')' : ''; }
                button.title = selected === 0 ? 'Select at least one row first' : '';
            });
        }

        scope.addEventListener('change', function (e) {
            if (e.target.matches('input[type=checkbox]')) { update(); }
        });
        update();
    });

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

    // --- Template source-length counter (P4-4) ---
    // Segment count can only be known after placeholders and conditional blocks
    // render. Delivery enforces one GSM-7 or Unicode SMS segment server-side.
    document.querySelectorAll('[data-iapm-sms-counter]').forEach(function (field) {
        var readout = document.createElement('p');
        readout.className = 'iapm-hint iapm-sms-counter';
        readout.setAttribute('aria-live', 'polite');
        field.insertAdjacentElement('afterend', readout);
        function update() {
            var length = field.value.length;
            if (! length) { readout.textContent = 'Empty — the built-in default for this phase is used.'; return; }
            readout.textContent = length + ' template character' + (length === 1 ? '' : 's') + ' · delivery is capped at one SMS segment after rendering.';
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
    // The assets partial is rendered from the navigation, before the rest of
    // the page markup. Delegate clicks so controls appearing later in the DOM
    // (including the Settings token controls) are still wired up.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-iapm-reveal-token]');
        if (! btn) { return; }
        e.preventDefault();
        var mask = btn.dataset.iapmTokenMask || '••••••••••••••••';
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
                btn.title = '';
            })
            .catch(function (err) {
                btn.innerHTML = '<i class="fa fa-exclamation-triangle"></i> Unavailable';
                btn.title = 'Could not read the token (' + err.message + '). You may not have permission to manage IAPM settings.';
            })
            .finally(function () { btn.disabled = false; });
    });

    // navigator.clipboard is unavailable on plain HTTP and can be rejected by
    // browser permissions. Keep copy functional on those LibreNMS installs by
    // falling back to the long-supported selection API.
    function legacyCopy(text) {
        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            area.setSelectionRange(0, area.value.length);
            try {
                if (document.execCommand('copy')) { resolve(); } else { reject(new Error('Copy command was rejected')); }
            } catch (err) {
                reject(err);
            } finally {
                document.body.removeChild(area);
            }
        });
    }
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).catch(function () { return legacyCopy(text); });
        }

        return legacyCopy(text);
    }
    function showCopied(btn, includeLabel) {
        var original = btn.innerHTML;
        btn.innerHTML = '<i class="fa fa-check"></i>' + (includeLabel ? ' Copied' : '');
        setTimeout(function () { btn.innerHTML = original; }, includeLabel ? 1500 : 1200);
    }
    function showCopyFailure(btn, err) {
        btn.title = 'Could not copy automatically (' + err.message + '). Select the value and copy it manually.';
    }

    // Delegation is required here for the same reason as token reveal: most
    // copy buttons are parsed after this shared partial runs.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-iapm-copy-text], [data-copy]');
        if (! btn) { return; }
        e.preventDefault();
        var text;
        var includeLabel = false;
        if (btn.hasAttribute('data-iapm-copy-text')) {
            text = btn.dataset.iapmCopyText;
        } else {
            var el = document.querySelector(btn.dataset.copy);
            if (! el) { return; }
            text = ('value' in el && el.tagName !== 'DIV') ? el.value : el.textContent;
            includeLabel = true;
        }
        copyText(text)
            .then(function () { btn.title = ''; showCopied(btn, includeLabel); })
            .catch(function (err) { showCopyFailure(btn, err); });
    });

    // --- Auto-refresh (opt in with an element #iapm-autorefresh[data-interval]) ---
    // P4-3: the checkbox gave no indication of its interval, when the page last
    // loaded, or when the next refresh was due. All three are now shown, and the
    // interval is selectable and remembered per page.
    var ar = document.getElementById('iapm-autorefresh');
    if (ar) {
        var key = 'iapm.autorefresh.' + location.pathname;
        var intervalKey = key + '.interval';
        var box = ar.querySelector('input[type=checkbox]');
        var intervalSelect = ar.querySelector('[data-iapm-refresh-interval]');
        var stamp = ar.querySelector('.iapm-updated');
        var loadedAt = Date.now();
        var timer = null;

        function interval() {
            var stored = parseInt(localStorage.getItem(intervalKey) || '', 10);
            var chosen = intervalSelect ? parseInt(intervalSelect.value, 10) : stored;
            return chosen || stored || parseInt(ar.dataset.interval || '30', 10);
        }

        if (intervalSelect) {
            var stored = localStorage.getItem(intervalKey);
            if (stored) { intervalSelect.value = stored; }
            intervalSelect.addEventListener('change', function () {
                localStorage.setItem(intervalKey, intervalSelect.value);
                schedule(); tick();
            });
        }
        if (box) {
            box.checked = localStorage.getItem(key) === '1';
            box.addEventListener('change', function () {
                localStorage.setItem(key, box.checked ? '1' : '0');
                schedule(); tick();
            });
        }

        function tick() {
            if (! stamp) { return; }
            var age = Math.round((Date.now() - loadedAt) / 1000);
            var text = 'loaded ' + age + 's ago';
            if (box && box.checked) {
                text += ' · next refresh in ' + Math.max(0, interval() - age) + 's';
            } else {
                text += ' · auto-refresh off';
            }
            stamp.textContent = text;
        }

        function schedule() {
            if (timer) { clearTimeout(timer); timer = null; }
            if (box && box.checked) { timer = setTimeout(function () { location.reload(); }, interval() * 1000); }
        }

        setInterval(tick, 1000);
        schedule();
        tick();
    }
    }

    // This partial is emitted by the navigation before each page's controls.
    // Initializing after parsing makes every eager widget (port pickers,
    // counters, quick-find, bulk controls and auto-refresh) see the full page.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeIapmControls);
    } else {
        initializeIapmControls();
    }
})();
</script>
