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
