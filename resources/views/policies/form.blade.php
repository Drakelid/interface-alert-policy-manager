@extends('layouts.librenmsv1') @section('title',$policy->exists?'Edit IAPM Policy':'Create IAPM Policy') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h2>{{ $policy->exists?'Edit':'Create' }} Policy</h2>
<p class="text-muted">Timing controls the incident lifecycle: <strong>trigger delay</strong> + <strong>failed polls</strong> must both be satisfied before an incident becomes active and notifies; <strong>repeat</strong>/<strong>maximum repeats</strong> govern reminders; <strong>recovery hold-down</strong> is how long an interface must stay up before it's marked recovered. Set 0 for immediate. Add notification <em>actions</em> below after saving.</p>
<form method="post" action="{{ $policy->exists?route('iapm.policies.update',$policy):route('iapm.policies.store') }}">@csrf @if($policy->exists)@method('PUT')@endif<div class="row"><div class="col-md-6">
@php
$fields = [
  'name'=>['label'=>'Name','type'=>'text','required'=>true],
  'description'=>['label'=>'Description','type'=>'textarea'],
  'priority'=>['label'=>'Priority','type'=>'number','help'=>'Higher wins among assignments of the same type.'],
  'default_receiver'=>['label'=>'Default receiver','type'=>'text','help'=>'Fallback receiver when nothing more specific resolves.'],
  'trigger_after_seconds'=>['label'=>'Trigger delay','unit'=>'seconds','type'=>'number','min'=>0,'seconds'=>true,'help'=>'Wait this long after first seeing the interface down before it can notify. 0 = immediate.'],
  'failed_poll_count'=>['label'=>'Failed polls','type'=>'number','min'=>1,'help'=>'Consecutive down observations required before triggering.'],
  'recovery_after_seconds'=>['label'=>'Recovery hold-down','unit'=>'seconds','type'=>'number','min'=>0,'seconds'=>true,'help'=>'Interface must stay up this long before it is marked recovered. 0 = immediate.'],
  'repeat_seconds'=>['label'=>'Repeat every','unit'=>'seconds','type'=>'number','min'=>0,'seconds'=>true,'help'=>'Re-send reminders at this interval. Blank = no repeat.'],
  'maximum_repeats'=>['label'=>'Maximum repeats','type'=>'number','min'=>0,'help'=>'Cap on reminder re-sends. Blank = unlimited.'],
];
@endphp
@foreach($fields as $key => $f)
<div class="form-group">
    <label>{{ $f['label'] }}@if(!empty($f['unit'])) <span class="text-muted">({{ $f['unit'] }})</span>@endif</label>
    @if($f['type']==='textarea')
    <textarea class="form-control" name="{{ $key }}">{{ old($key,$policy->$key) }}</textarea>
    @else
    <input class="form-control {{ !empty($f['seconds'])?'iapm-seconds':'' }}" type="{{ $f['type'] }}" name="{{ $key }}" value="{{ old($key,$policy->$key) }}"{{ isset($f['min']) ? ' min='.$f['min'] : '' }}{{ !empty($f['required']) ? ' required' : '' }}>
    @if(!empty($f['seconds']))<span class="help-block iapm-seconds-hint text-info" style="display:inline;margin-left:6px;"></span>@endif
    @endif
    @if(!empty($f['help']))<p class="help-block">{{ $f['help'] }}</p>@endif
</div>
@endforeach
<div class="form-group"><label>Severity</label><select class="form-control" name="severity">@foreach(['info','warning','critical'] as $v)<option @selected(old('severity',$policy->severity?->value??'critical')===$v)>{{ $v }}</option>@endforeach</select></div></div><div class="col-md-6">
@foreach(['enabled','notifications_enabled','notify_recovery','suppress_device_down','suppress_admin_down','suppress_ignored_port','suppress_disabled_port','suppress_deleted_port','suppress_maintenance','suppress_parent_down'] as $key)<input type="hidden" name="{{ $key }}" value="0"><div class="checkbox"><label><input type="checkbox" name="{{ $key }}" value="1" @checked(old($key,$policy->exists?$policy->$key:true))> {{ str_replace('_',' ',ucfirst($key)) }}</label></div>@endforeach
<input type="hidden" name="suppress_uplink_down" value="0"><div class="checkbox"><label><input type="checkbox" name="suppress_uplink_down" value="1" @checked(old('suppress_uplink_down',$policy->exists?$policy->suppress_uplink_down:false))> Suppress when uplink down <span class="help-block" style="display:inline">(root-cause: needs an uplink port group set in Settings)</span></label></div>
<div class="form-group"><label>Schedule</label><select class="form-control" name="business_schedule_id"><option value="">24/7</option>@foreach($schedules as $s)<option value="{{ $s->id }}" @selected(old('business_schedule_id',$policy->business_schedule_id)==$s->id)>{{ $s->name }}</option>@endforeach</select></div>
<fieldset style="margin-top:10px;"><legend style="font-size:14px;">Flap dampening <small class="text-muted">(optional)</small></legend>
<p class="help-block">When an interface cycles down/up faster than the threshold, send one "flapping" notice and dampen the rest until it stabilises. Leave threshold blank to disable.</p>
@foreach(['flap_threshold'=>['Flap threshold (down/up cycles)',false],'flap_window_seconds'=>['Flap window (seconds)',true],'flap_settle_seconds'=>['Settle period (seconds)',true]] as $key=>$meta)<div class="form-group"><label>{{ $meta[0] }}</label><input class="form-control{{ $meta[1]?' iapm-seconds':'' }}" type="number" min="0" name="{{ $key }}" value="{{ old($key,$policy->$key) }}">@if($meta[1])<span class="help-block iapm-seconds-hint text-info" style="display:inline;margin-left:6px;"></span>@endif</div>@endforeach
</fieldset>
</div></div><button class="btn btn-primary">Save</button></form>
@if($policy->exists)<hr><h3>Notification actions <a class="btn btn-primary btn-sm" href="{{ route('iapm.actions.create',$policy) }}">Add action</a></h3>
@php($enabledActionCount = $policy->actions()->where('enabled',true)->count())
@if($enabledActionCount===0)<div class="alert alert-warning"><i class="fa fa-bell-slash"></i> <strong>This policy won't notify anyone yet.</strong> It has no enabled notification action, so matched interfaces will trigger incidents silently. <a href="{{ route('iapm.actions.create',$policy) }}">Add an action</a> pointing at a destination (e.g. your SMS gateway).</div>@endif
<p class="text-muted">Build an <strong>escalation chain</strong> by adding multiple <em>escalation</em> actions with increasing delays and different destinations/receivers (e.g. 10m → primary, 20m → secondary, 30m → manager). Delays are measured from when the incident triggered; acknowledging the incident stops further escalation.</p><table class="table"><thead><tr><th>Phase</th><th>Destination</th><th>Delay</th><th>Repeat</th><th>Maximum</th><th>Status</th></tr></thead><tbody>@foreach($policy->actions()->with('destination')->orderBy('sort_order')->get() as $action)<tr><td><a href="{{ route('iapm.actions.edit',$action) }}">{{ $action->phase->value }}</a></td><td>{{ $action->destination?->name }}</td><td>{{ $action->delay_seconds }}</td><td>{{ $action->repeat_seconds }}</td><td>{{ $action->maximum_sends }}</td><td>{{ $action->enabled?'Enabled':'Disabled' }}</td></tr>@endforeach</tbody></table>
<form method="post" action="{{ route('iapm.policies.clone',$policy) }}">@csrf<button class="btn btn-default">Clone policy</button></form>
<form method="post" action="{{ route('iapm.policies.destroy',$policy) }}" onsubmit="return confirm('Delete this policy?')">@csrf @method('DELETE')
@if(($openIncidentCount??0)>0)<div class="form-group"><label>This policy has {{ $openIncidentCount }} active incident(s). Migrate them to</label><select name="migrate_to" class="form-control" required><option value="">Select a policy…</option>@foreach($otherPolicies as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>@endif
<button class="btn btn-danger">Delete</button></form>@endif</div>
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
