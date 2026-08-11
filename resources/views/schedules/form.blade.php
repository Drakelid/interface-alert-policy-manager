@extends('layouts.librenmsv1')
@section('title', $schedule->exists ? 'Edit IAPM Schedule' : 'Create IAPM Schedule')
@section('content')
@php($iapmDayNames = ['mon'=>'Monday','tue'=>'Tuesday','wed'=>'Wednesday','thu'=>'Thursday','fri'=>'Friday','sat'=>'Saturday','sun'=>'Sunday'])
@php($iapmMode = old('mode', $definition['mode'] ?? 'always'))
@php($iapmDays = old('days', $definition['days'] ?? []))
<div class="container-fluid">
    @include('iapm::partials.nav')
    <h1 class="iapm-page-title">{{ $schedule->exists ? 'Edit' : 'Create' }} Schedule</h1>

    {{-- P1-5: this page used to require hand-writing {"mode":…,"days":{…}} into a
         textarea, which is the most likely reason the (optional) schedule step gets
         skipped — losing out-of-hours suppression entirely. The weekday rows below
         are the real form fields, so the editor works without JavaScript; the JSON
         view is an advanced escape hatch kept in sync in both directions. --}}
    <p class="iapm-hint" style="max-width:70em;">A schedule decides <strong>when a policy is allowed to notify</strong>. Attach it to a policy under <em>Policies → Schedule</em>. Outside the permitted window, incidents are still recorded but suppressed with reason <code>outside_schedule</code>.</p>

    @if($schedule->exists)
    <div class="alert {{ $inWindow ? 'alert-success' : 'alert-info' }}" data-iapm-window="{{ $inWindow ? 'open' : 'closed' }}">
        <i class="fa fa-{{ $inWindow ? 'bell' : 'bell-slash' }}"></i>
        <strong>Right now this schedule {{ $inWindow ? 'permits' : 'suppresses' }} notifications.</strong>
        Local time in {{ $schedule->timezone }} is {{ $localTime }}.
        @unless($schedule->enabled)<span class="iapm-hint">The schedule is disabled, which suppresses everything it is attached to.</span>@endunless
    </div>
    @endif

    <form method="post" action="{{ $schedule->exists ? route('iapm.schedules.update',$schedule) : route('iapm.schedules.store') }}" id="iapm-schedule-form">
        @csrf
        @if($schedule->exists)@method('PUT')@endif

        <div class="row"><div class="col-md-7">
            <div class="form-group">
                <label for="iapm-sched-name">Name</label>
                <input class="form-control" id="iapm-sched-name" required name="name" value="{{ old('name',$schedule->name) }}" placeholder="e.g. Business hours (NOC staffed)">
            </div>

            <div class="form-group">
                <label for="iapm-sched-tz">Timezone</label>
                {{-- P1-5: this was a free-text box defaulting to UTC, so a typo
                     silently shifted every window. --}}
                <select class="form-control" id="iapm-sched-tz" name="timezone" required>
                    @foreach($timezones as $tz)<option value="{{ $tz }}" @selected(old('timezone',$schedule->timezone ?: config('app.timezone'))===$tz)>{{ $tz }}</option>@endforeach
                </select>
                <p class="iapm-hint">The times below are interpreted in this zone, including daylight-saving changes.</p>
            </div>

            <fieldset class="form-group">
                <legend style="font-size:15px;">When does this schedule permit notifications?</legend>
                @php($iapmModeHelp = [
                    'always' => 'Always notify. The weekday times below are ignored.',
                    'business_hours' => 'Notify only INSIDE the times below.',
                    'after_hours' => 'Notify only OUTSIDE the times below — define your business hours and this covers everything else, including weekends.',
                    'custom' => 'Notify only inside the times below. Behaves the same as business hours; use it to label an arbitrary window.',
                ])
                @foreach($modes as $mode)
                <div class="radio" style="margin:4px 0;">
                    <label for="iapm-mode-{{ $mode }}">
                        <input type="radio" name="mode" id="iapm-mode-{{ $mode }}" value="{{ $mode }}" @checked($iapmMode===$mode) data-iapm-mode>
                        <strong>{{ ucfirst(str_replace('_',' ',$mode)) }}</strong>
                        <span class="iapm-hint">&mdash; {{ $iapmModeHelp[$mode] }}</span>
                    </label>
                </div>
                @endforeach
                <p class="iapm-hint" style="margin-top:6px;">
                    <i class="fa fa-info-circle"></i>
                    <strong>After hours is the exact inverse of business hours.</strong> Both read the same weekday times; business hours permits notifications inside them, after hours permits them outside. A policy that should only page overnight and at weekends wants <em>after hours</em> with your normal working day entered below.
                </p>
            </fieldset>

            <div id="iapm-day-editor" @if($iapmMode==='always') style="display:none;" @endif>
                <span class="iapm-field-legend">Weekly times</span>
                <p class="iapm-hint">Add one or more ranges per day. A range that ends before it starts (e.g. 22:00&ndash;06:00) wraps past midnight. Leave a day empty for no window that day.</p>
                <div class="iapm-table-wrap"><table class="table table-condensed" id="iapm-day-table">
                    <thead><tr><th style="width:9em;">Day</th><th>Time ranges</th><th style="width:6em;"></th></tr></thead>
                    <tbody>
                    @foreach($days as $day)
                    @php($iapmPeriods = $iapmDays[$day] ?? [])
                    <tr data-iapm-day="{{ $day }}">
                        <th scope="row">{{ $iapmDayNames[$day] }}</th>
                        <td data-iapm-periods>
                            @forelse($iapmPeriods as $index => $period)
                            <div class="iapm-period">
                                <label class="sr-only" for="iapm-{{ $day }}-{{ $index }}-start">{{ $iapmDayNames[$day] }} range {{ $index+1 }} start</label>
                                <input type="time" class="form-control input-sm" id="iapm-{{ $day }}-{{ $index }}-start" name="days[{{ $day }}][{{ $index }}][start]" value="{{ $period['start'] ?? '' }}">
                                <span aria-hidden="true">&ndash;</span>
                                <label class="sr-only" for="iapm-{{ $day }}-{{ $index }}-end">{{ $iapmDayNames[$day] }} range {{ $index+1 }} end</label>
                                <input type="time" class="form-control input-sm" id="iapm-{{ $day }}-{{ $index }}-end" name="days[{{ $day }}][{{ $index }}][end]" value="{{ $period['end'] ?? '' }}">
                                <button type="button" class="btn btn-default btn-xs" data-iapm-remove-period aria-label="Remove this range">&times;</button>
                            </div>
                            @empty
                            @endforelse
                        </td>
                        <td>
                            <button type="button" class="btn btn-default btn-xs" data-iapm-add-period><i class="fa fa-plus"></i> Range</button>
                        </td>
                    </tr>
                    @endforeach
                    </tbody>
                </table></div>
                <button type="button" class="btn btn-default btn-sm" id="iapm-fill-weekdays"><i class="fa fa-magic"></i> Fill Mon&ndash;Fri 08:00&ndash;16:00</button>
            </div>

            <div class="form-group" style="margin-top:14px;">
                <input type="hidden" name="enabled" value="0">
                <div class="checkbox"><label for="iapm-sched-enabled"><input type="checkbox" id="iapm-sched-enabled" name="enabled" value="1" @checked(old('enabled',$schedule->exists?$schedule->enabled:true))> Enabled</label></div>
                <p class="iapm-hint">A disabled schedule suppresses every policy attached to it.</p>
            </div>
        </div>

        <div class="col-md-5">
            <div class="panel panel-default">
                <div class="panel-heading" role="button" data-toggle="collapse" data-target="#iapm-json-panel" style="cursor:pointer;">
                    <i class="fa fa-code"></i> Advanced: JSON view
                </div>
                <div id="iapm-json-panel" class="collapse">
                    <div class="panel-body">
                        <div class="checkbox"><label for="iapm-advanced-json"><input type="checkbox" id="iapm-advanced-json" name="advanced_json" value="1" @checked(old('advanced_json'))> Save from this JSON instead of the editor</label></div>
                        <p class="iapm-hint">Leave unticked and the weekday editor is authoritative; this box just mirrors it. Tick it to hand-write a definition the editor cannot express.</p>
                        <label class="sr-only" for="iapm-schedule-json">Schedule JSON</label>
                        <textarea class="form-control" id="iapm-schedule-json" name="schedule_json" rows="16" style="font-family:monospace;">{{ old('schedule_json', json_encode($definition, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) }}</textarea>
                        <button type="button" class="btn btn-default btn-sm" id="iapm-json-apply" style="margin-top:6px;"><i class="fa fa-arrow-left"></i> Apply JSON to the editor</button>
                    </div>
                </div>
            </div>
        </div></div>

        <div class="iapm-form-footer">
            <button class="btn btn-primary"><i class="fa fa-save"></i> Save schedule</button>
            <a class="btn btn-default" href="{{ route('iapm.schedules.index') }}">Cancel</a>
        </div>
    </form>

    @if($schedule->exists)
    <form method="post" action="{{ route('iapm.schedules.destroy',$schedule) }}" style="margin-top:10px;" data-iapm-confirm="Delete the schedule &quot;{{ $schedule->name }}&quot;? Policies using it will fall back to notifying 24/7.">@csrf @method('DELETE')
        <button class="btn btn-danger"><i class="fa fa-trash"></i> Delete schedule</button>
    </form>
    @endif
</div>

<script>
(function () {
    var form = document.getElementById('iapm-schedule-form');
    if (! form) { return; }
    var table = document.getElementById('iapm-day-table');
    var editor = document.getElementById('iapm-day-editor');
    var json = document.getElementById('iapm-schedule-json');
    var DAYS = @json($days);
    var DAY_NAMES = @json($iapmDayNames);

    function currentMode() {
        var checked = form.querySelector('[data-iapm-mode]:checked');
        return checked ? checked.value : 'always';
    }

    function periodRow(day, index, start, end) {
        var wrap = document.createElement('div');
        wrap.className = 'iapm-period';
        wrap.innerHTML =
            '<label class="sr-only" for="iapm-' + day + '-' + index + '-start">' + DAY_NAMES[day] + ' range ' + (index + 1) + ' start</label>' +
            '<input type="time" class="form-control input-sm" id="iapm-' + day + '-' + index + '-start" name="days[' + day + '][' + index + '][start]" value="' + (start || '') + '">' +
            '<span aria-hidden="true">–</span>' +
            '<label class="sr-only" for="iapm-' + day + '-' + index + '-end">' + DAY_NAMES[day] + ' range ' + (index + 1) + ' end</label>' +
            '<input type="time" class="form-control input-sm" id="iapm-' + day + '-' + index + '-end" name="days[' + day + '][' + index + '][end]" value="' + (end || '') + '">' +
            '<button type="button" class="btn btn-default btn-xs" data-iapm-remove-period aria-label="Remove this range">×</button>';
        return wrap;
    }

    // Names carry the index, so they are rewritten after any add or remove.
    function reindex(cell, day) {
        cell.querySelectorAll('.iapm-period').forEach(function (row, index) {
            var inputs = row.querySelectorAll('input[type=time]');
            var labels = row.querySelectorAll('label');
            ['start', 'end'].forEach(function (part, i) {
                inputs[i].name = 'days[' + day + '][' + index + '][' + part + ']';
                inputs[i].id = 'iapm-' + day + '-' + index + '-' + part;
                labels[i].setAttribute('for', inputs[i].id);
                labels[i].textContent = DAY_NAMES[day] + ' range ' + (index + 1) + ' ' + part;
            });
        });
    }

    function readEditor() {
        var days = {};
        DAYS.forEach(function (day) {
            var row = table.querySelector('[data-iapm-day="' + day + '"]');
            if (! row) { return; }
            var periods = [];
            row.querySelectorAll('.iapm-period').forEach(function (p) {
                var inputs = p.querySelectorAll('input[type=time]');
                if (inputs[0].value && inputs[1].value) { periods.push({ start: inputs[0].value, end: inputs[1].value }); }
            });
            if (periods.length) { days[day] = periods; }
        });
        return { mode: currentMode(), days: days };
    }

    function syncJson() { json.value = JSON.stringify(readEditor(), null, 2); }

    function applyJson() {
        var parsed;
        try { parsed = JSON.parse(json.value); } catch (e) {
            window.alert('That is not valid JSON: ' + e.message);
            return;
        }
        var mode = form.querySelector('[data-iapm-mode][value="' + (parsed.mode || 'always') + '"]');
        if (mode) { mode.checked = true; }
        DAYS.forEach(function (day) {
            var cell = table.querySelector('[data-iapm-day="' + day + '"] [data-iapm-periods]');
            if (! cell) { return; }
            cell.innerHTML = '';
            ((parsed.days || {})[day] || []).forEach(function (period, index) {
                cell.appendChild(periodRow(day, index, period.start, period.end));
            });
        });
        toggleEditor();
    }

    function toggleEditor() { editor.style.display = currentMode() === 'always' ? 'none' : ''; }

    table.addEventListener('click', function (e) {
        var add = e.target.closest('[data-iapm-add-period]');
        if (add) {
            var row = add.closest('[data-iapm-day]');
            var cell = row.querySelector('[data-iapm-periods]');
            cell.appendChild(periodRow(row.dataset.iapmDay, cell.querySelectorAll('.iapm-period').length, '08:00', '16:00'));
            reindex(cell, row.dataset.iapmDay);
            syncJson();
            return;
        }
        var remove = e.target.closest('[data-iapm-remove-period]');
        if (remove) {
            var parent = remove.closest('[data-iapm-day]');
            remove.closest('.iapm-period').remove();
            reindex(parent.querySelector('[data-iapm-periods]'), parent.dataset.iapmDay);
            syncJson();
        }
    });

    table.addEventListener('input', syncJson);
    form.querySelectorAll('[data-iapm-mode]').forEach(function (radio) {
        radio.addEventListener('change', function () { toggleEditor(); syncJson(); });
    });
    document.getElementById('iapm-json-apply').addEventListener('click', applyJson);
    document.getElementById('iapm-fill-weekdays').addEventListener('click', function () {
        ['mon', 'tue', 'wed', 'thu', 'fri'].forEach(function (day) {
            var cell = table.querySelector('[data-iapm-day="' + day + '"] [data-iapm-periods]');
            cell.innerHTML = '';
            cell.appendChild(periodRow(day, 0, '08:00', '16:00'));
        });
        syncJson();
    });

    toggleEditor();
    syncJson();
})();
</script>
@endsection
