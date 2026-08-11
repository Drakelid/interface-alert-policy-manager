@extends('layouts.librenmsv1') @section('title',$policy->exists?'Edit IAPM Policy':'Create IAPM Policy') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h1 class="iapm-page-title">{{ $policy->exists?'Edit':'Create' }} Policy</h1>
<p class="iapm-hint">Timing controls the incident lifecycle: <strong>trigger delay</strong> + <strong>down observations</strong> must both be satisfied before an incident becomes active and notifies; <strong>repeat</strong>/<strong>maximum repeats</strong> govern reminders; <strong>recovery hold-down</strong> is how long an interface must stay up before it's marked recovered. Set 0 for immediate. Detection can never be faster than your LibreNMS poll interval (300 seconds by default), so timings below that round up to the next poll. Add notification <em>actions</em> below after saving.</p>
<form method="post" action="{{ $policy->exists?route('iapm.policies.update',$policy):route('iapm.policies.store') }}">@csrf @if($policy->exists)@method('PUT')@endif<div class="row"><div class="col-md-6">
@php
$fields = [
  'name'=>['label'=>'Name','type'=>'text','required'=>true],
  'description'=>['label'=>'Description','type'=>'textarea'],
  'priority'=>['label'=>'Priority','type'=>'number','help'=>'Higher wins among assignments of the same type.'],
  'default_receiver'=>['label'=>'Default receiver','type'=>'text','help'=>'Fallback receiver when nothing more specific resolves.'],
  'trigger_after_seconds'=>['label'=>'Trigger delay','unit'=>'seconds','type'=>'number','min'=>0,'seconds'=>true,'help'=>'Wait this long after first seeing the interface down before it can notify. 0 = immediate, so a single poll sample notifies. Use a multiple of your LibreNMS poll interval (300 seconds by default) to require that many polls of confirmation.'],
  // P2-5: this posted as failed_poll_count while its own help text said it does
  // not count polls. Renamed to match what it actually measures.
  'down_observations'=>['label'=>'Down observations','type'=>'number','min'=>1,'help'=>'Down observations required before triggering. Reconciliation counts one every minute while the interface stays down, so this does NOT count LibreNMS polls — use the trigger delay above for poll-based confirmation.'],
  'recovery_after_seconds'=>['label'=>'Recovery hold-down','unit'=>'seconds','type'=>'number','min'=>0,'seconds'=>true,'help'=>'Interface must stay up this long before it is marked recovered. 0 = immediate. Below one poll interval (300 seconds by default) this just means "at the next poll".'],
  // P2-2: one convention across both forms — blank means unlimited, 0 means
  // none. The action form states the same thing for its own fields.
  'repeat_seconds'=>['label'=>'Repeat every','unit'=>'seconds','type'=>'number','min'=>0,'seconds'=>true,'help'=>'Re-send reminders at this interval. Blank = never repeat. Below one poll interval it re-notifies on unchanged data.'],
  'maximum_repeats'=>['label'=>'Maximum repeats','type'=>'number','min'=>0,'help'=>'Cap on reminder re-sends. Blank = unlimited. 0 = no reminders at all (the first notification is still sent). An action can override this with its own "maximum sends".'],
];
@endphp
{{-- P3-1: this form had 26 labels and not one `for` attribute, leaving most of
     its fields with no programmatic name at all. Every control now has an id and
     its label points at it; help text is linked with aria-describedby. --}}
@foreach($fields as $key => $f)
<div class="form-group">
    <label for="iapm-policy-{{ $key }}">{{ $f['label'] }}@if(!empty($f['unit'])) <span class="iapm-hint">({{ $f['unit'] }})</span>@endif</label>
    @if($f['type']==='textarea')
    <textarea class="form-control" id="iapm-policy-{{ $key }}" name="{{ $key }}"@if(!empty($f['help'])) aria-describedby="iapm-policy-{{ $key }}-help"@endif>{{ old($key,$policy->$key) }}</textarea>
    @else
    <input class="form-control {{ !empty($f['seconds'])?'iapm-seconds':'' }}" id="iapm-policy-{{ $key }}" type="{{ $f['type'] }}" name="{{ $key }}" value="{{ old($key,$policy->$key) }}"{{ isset($f['min']) ? ' min='.$f['min'] : '' }}{{ !empty($f['required']) ? ' required' : '' }}@if(!empty($f['help'])) aria-describedby="iapm-policy-{{ $key }}-help"@endif>
    @if(!empty($f['seconds']))<span class="help-block iapm-seconds-hint text-info" style="display:inline;margin-left:6px;"></span>@endif
    @endif
    @if(!empty($f['help']))<p class="iapm-hint" id="iapm-policy-{{ $key }}-help">{{ $f['help'] }}</p>@endif
</div>
@endforeach
<div class="form-group"><label for="iapm-policy-severity">Severity</label><select class="form-control" id="iapm-policy-severity" name="severity">@foreach(['info','warning','critical'] as $v)<option @selected(old('severity',$policy->severity?->value??'critical')===$v)>{{ $v }}</option>@endforeach</select></div></div><div class="col-md-6">
@foreach(['enabled','notifications_enabled','notify_recovery','suppress_device_down','suppress_admin_down','suppress_ignored_port','suppress_disabled_port','suppress_deleted_port','suppress_maintenance','suppress_parent_down'] as $key)<input type="hidden" name="{{ $key }}" value="0"><div class="checkbox"><label for="iapm-policy-{{ $key }}"><input type="checkbox" id="iapm-policy-{{ $key }}" name="{{ $key }}" value="1" @checked(old($key,$policy->exists?$policy->$key:true))> {{ str_replace('_',' ',ucfirst($key)) }}</label></div>@endforeach
<input type="hidden" name="suppress_uplink_down" value="0"><div class="checkbox"><label for="iapm-policy-suppress_uplink_down"><input type="checkbox" id="iapm-policy-suppress_uplink_down" name="suppress_uplink_down" value="1" @checked(old('suppress_uplink_down',$policy->exists?$policy->suppress_uplink_down:false))> Suppress when uplink down <span class="iapm-hint">(root-cause: needs an uplink port group set in Settings)</span></label></div>
<div class="form-group"><label for="iapm-policy-schedule">Schedule</label><select class="form-control" id="iapm-policy-schedule" name="business_schedule_id"><option value="">24/7</option>@foreach($schedules as $s)<option value="{{ $s->id }}" @selected(old('business_schedule_id',$policy->business_schedule_id)==$s->id)>{{ $s->name }}</option>@endforeach</select></div>
<fieldset style="margin-top:10px;"><legend style="font-size:14px;">Flap dampening <small class="iapm-hint">(optional)</small></legend>
<p class="iapm-hint">When an interface cycles down/up faster than the threshold, send one "flapping" notice and dampen the rest until it stabilises. Leave threshold blank to disable.</p>
@foreach(['flap_threshold'=>['Flap threshold (down/up cycles)',false],'flap_window_seconds'=>['Flap window (seconds)',true],'flap_settle_seconds'=>['Settle period (seconds)',true]] as $key=>$meta)<div class="form-group"><label for="iapm-policy-{{ $key }}">{{ $meta[0] }}</label><input class="form-control{{ $meta[1]?' iapm-seconds':'' }}" id="iapm-policy-{{ $key }}" type="number" min="0" name="{{ $key }}" value="{{ old($key,$policy->$key) }}">@if($meta[1])<span class="help-block iapm-seconds-hint text-info" style="display:inline;margin-left:6px;"></span>@endif</div>@endforeach
</fieldset>
</div></div>
{{-- P2-4: Save used to sit at the bottom of the left column while the right
     column ended much higher, leaving a large dead zone and obscuring that one
     Save commits both columns. It is now a full-width footer under both. --}}
<div class="iapm-form-footer">
    <button class="btn btn-primary"><i class="fa fa-save"></i> Save policy</button>
    <a class="btn btn-default" href="{{ route('iapm.policies.index') }}">Cancel</a>
    <span class="iapm-hint">Saves every field on this page, in both columns.</span>
</div>
</form>
@if($policy->exists)<hr><h2>Notification actions <a class="btn btn-primary btn-sm" href="{{ route('iapm.actions.create',$policy) }}">Add action</a></h2>
@php($enabledActionCount = $policy->actions()->where('enabled',true)->count())
@if($enabledActionCount===0)<div class="alert alert-warning"><i class="fa fa-bell-slash"></i> <strong>This policy won't notify anyone yet.</strong> It has no enabled notification action, so matched interfaces will trigger incidents silently. <a href="{{ route('iapm.actions.create',$policy) }}">Add an action</a> pointing at a destination (e.g. your SMS gateway).</div>@endif
<p class="iapm-hint">Build an <strong>escalation chain</strong> by adding multiple <em>escalation</em> actions with increasing delays and different destinations/receivers (e.g. 10m → primary, 20m → secondary, 30m → manager). Delays are measured from when the incident triggered; acknowledging the incident stops further escalation.</p>{{-- P1-4: only the phase cell was a link, and deleting an action was reachable
     only from inside the action editor. Both are explicit controls now. --}}
<div class="iapm-table-wrap"><table class="table"><thead><tr><th>Phase</th><th>Destination</th><th>Delay</th><th>Repeat</th><th>Maximum</th><th>Status</th><th></th></tr></thead><tbody>
@foreach($policy->actions()->with('destination')->orderBy('sort_order')->get() as $action)<tr>
<td><a href="{{ route('iapm.actions.edit',$action) }}">{{ $action->phase->value }}</a></td>
<td>@if($action->destination)<a href="{{ route('iapm.destinations.edit',$action->destination) }}">{{ $action->destination->name }}</a>@else<span class="text-warning">none</span>@endif</td>
<td>{{ $action->delay_seconds }}</td>
<td>{{ $action->repeat_seconds ?? '—' }}</td>
<td>{{ $action->maximum_sends ?? '—' }}</td>
<td>@if($action->enabled)<span class="label label-success">Enabled</span>@else<span class="label label-default">Disabled</span>@endif</td>
<td class="iapm-actions" style="white-space:nowrap;">
    <a class="btn btn-default btn-xs" href="{{ route('iapm.actions.edit',$action) }}"><i class="fa fa-pencil"></i> Edit</a>
    <form method="post" action="{{ route('iapm.actions.destroy',$action) }}" style="display:inline;" data-iapm-confirm="Delete the {{ $action->phase->value }} action sending to {{ $action->destination?->name ?? 'no destination' }}? This policy will stop notifying through it.">@csrf @method('DELETE')<button class="btn btn-danger btn-xs"><i class="fa fa-trash"></i> Delete</button></form>
</td>
</tr>@endforeach</tbody></table></div>
<hr>
<h2>Manage this policy</h2>
<form method="post" action="{{ route('iapm.policies.clone',$policy) }}" style="margin-bottom:14px;">@csrf<button class="btn btn-default"><i class="fa fa-copy"></i> Clone policy</button> <span class="iapm-hint">Creates a disabled copy with the same timing and actions.</span></form>

{{-- P2-4: the confirmation used to read only "Delete this policy?" — it said
     nothing about the active incidents or whether the migration selection had
     been applied. It now states the consequence, including where the incidents
     go. The single-policy case is handled explicitly rather than offering a
     dropdown with no options. --}}
@php($iapmOpenIncidents = (int) ($openIncidentCount ?? 0))
@php($iapmCanMigrate = $otherPolicies->isNotEmpty())
<div class="panel panel-danger">
    <div class="panel-heading">Delete policy</div>
    <div class="panel-body">
        @if($iapmOpenIncidents > 0 && ! $iapmCanMigrate)
            <p><strong>This policy cannot be deleted yet.</strong> It has {{ $iapmOpenIncidents }} active incident(s), and there is no other policy to move them to &mdash; this is the only policy on this install.</p>
            <p class="iapm-hint">Either <a href="{{ route('iapm.policies.create') }}">create another policy</a> to migrate them to, or wait for the incidents to recover.</p>
            <button class="btn btn-danger" disabled>Delete policy</button>
        @else
            <form method="post" action="{{ route('iapm.policies.destroy',$policy) }}" id="iapm-delete-policy"
                  data-iapm-confirm="@if($iapmOpenIncidents > 0)Delete &quot;{{ $policy->name }}&quot; and move its {{ $iapmOpenIncidents }} active incident(s) to the policy selected above? The incidents keep their history and are re-evaluated under the new policy. This cannot be undone.@else Delete &quot;{{ $policy->name }}&quot;? Its {{ $policy->actions()->count() }} notification action(s) and {{ $policy->assignments()->count() }} assignment(s) are deleted with it, and any interfaces they matched stop being covered until another assignment picks them up. This cannot be undone.@endif">
                @csrf @method('DELETE')
                @if($iapmOpenIncidents > 0)
                <div class="form-group" style="max-width:420px;">
                    <label for="iapm-migrate-to">This policy has {{ $iapmOpenIncidents }} active incident(s). Migrate them to</label>
                    <select name="migrate_to" id="iapm-migrate-to" class="form-control" required>
                        <option value="">Select a policy…</option>
                        @foreach($otherPolicies as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select>
                    <p class="iapm-hint">Required. The incidents are reassigned before the policy is removed.</p>
                </div>
                @else
                <p class="iapm-hint">This policy has no active incidents. Its {{ $policy->actions()->count() }} action(s) and {{ $policy->assignments()->count() }} assignment(s) are deleted with it.</p>
                @endif
                <button class="btn btn-danger"><i class="fa fa-trash"></i> Delete policy</button>
            </form>
        @endif
    </div>
</div>
@endif</div>
<script>
(function () {
    function humanize(v) {
        var s = parseInt(v, 10);
        if (!s || s <= 0) return '';
        if (s < 60) return '= ' + s + 's';
        if (s < 3600) return '≈ ' + (Math.round(s / 6) / 10) + ' min';
        return '≈ ' + (Math.round(s / 360) / 10) + ' h';
    }
    document.querySelectorAll('.iapm-seconds').forEach(function (inp) {
        var hint = inp.parentNode.querySelector('.iapm-seconds-hint');
        if (!hint) return;
        function upd() { hint.textContent = humanize(inp.value); }
        inp.addEventListener('input', upd); upd();
    });
})();
</script>
@endsection
