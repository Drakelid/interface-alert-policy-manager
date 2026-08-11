{{--
    Auto-refresh control (P4-3).

    The old checkbox said only "Auto-refresh": no interval, no indication of when
    the page last loaded, and no way to change the cadence. It now states both
    and lets the operator pick, remembered per page in localStorage.
--}}
<span id="iapm-autorefresh" data-interval="30">
    <label class="iapm-hint" style="font-weight:normal;" for="iapm-autorefresh-box">
        <input type="checkbox" id="iapm-autorefresh-box"> Auto-refresh
    </label>
    <label class="sr-only" for="iapm-autorefresh-interval">Refresh interval</label>
    <select class="form-control input-sm" id="iapm-autorefresh-interval" data-iapm-refresh-interval style="width:auto;display:inline-block;">
        @foreach([15=>'15s',30=>'30s',60=>'1m',300=>'5m'] as $seconds=>$label)
        <option value="{{ $seconds }}" @selected($seconds===30)>every {{ $label }}</option>
        @endforeach
    </select>
    <span class="iapm-hint small iapm-updated" aria-live="polite"></span>
</span>
