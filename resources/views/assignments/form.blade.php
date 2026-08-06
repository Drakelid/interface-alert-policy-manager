@extends('layouts.librenmsv1') @section('title','IAPM Assignment') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>{{ $assignment->exists?'Edit':'Create' }} Assignment</h2>
<p class="text-muted">Map interfaces to a policy. Choose a type below and only the relevant fields appear. Use <strong>Preview match count</strong> to see how many interfaces it currently affects before saving.</p>

@php($selectedType = old('assignment_type',$assignment->assignment_type?->value ?? 'default'))
@php($selectedGroups = old('device_group_ids', $assignment->deviceGroups->pluck('device_group_id')->map(fn($id)=>(string)$id)->all()))
<form method="post" action="{{ $assignment->exists?route('iapm.assignments.update',$assignment):route('iapm.assignments.store') }}" id="iapm-assignment-form">@csrf @if($assignment->exists)@method('PUT')@endif

<div class="row"><div class="col-md-7">
<div class="form-group"><label>Policy</label>
    <select name="policy_id" class="form-control" required>@foreach($policies as $p)<option value="{{ $p->id }}" @selected(old('policy_id',$assignment->policy_id)==$p->id)>{{ $p->name }}@unless($p->enabled) (disabled)@endunless</option>@endforeach</select>
    <p class="help-block">The policy applied to interfaces this assignment matches.</p>
</div>

<div class="form-group"><label>Match by</label>
    <select name="assignment_type" id="iapm-type" class="form-control">
        @foreach(['default'=>'Default (everything not matched more specifically)','port'=>'Specific port','port_group'=>'Port group','device'=>'Device','device_group'=>'Device group(s)','location'=>'Location','ifalias_regex'=>'Interface description (ifAlias) regex','ifname_regex'=>'Interface name (ifName) regex','interface_type'=>'Interface type'] as $v=>$label)
        <option value="{{ $v }}" @selected($selectedType===$v)>{{ $label }}</option>@endforeach
    </select>
</div>

{{-- Default catch-all warning --}}
<div class="alert alert-info iapm-field" data-types="default"><i class="fa fa-info-circle"></i> A <strong>default</strong> assignment applies this policy to <strong>every interface</strong> not matched by a more specific assignment. Usually you want only one.</div>

{{-- Device dropdown --}}
<div class="form-group iapm-field" data-types="device">
    <label>Device</label>
    <select name="assignment_reference" class="form-control iapm-devsel" disabled>
        <option value="">— select a device —</option>
        @foreach($devices as $d)<option value="{{ $d->device_id }}" @selected(old('assignment_reference',$assignment->assignment_reference)==$d->device_id)>{{ $d->hostname }}@if($d->sysName && $d->sysName!==$d->hostname) ({{ $d->sysName }})@endif</option>@endforeach
    </select>
    <p class="help-block">All interfaces on this device.</p>
</div>

{{-- Port group dropdown --}}
<div class="form-group iapm-field" data-types="port_group">
    <label>Port group</label>
    <select name="assignment_reference" class="form-control iapm-pgsel" disabled>
        <option value="">— select a port group —</option>
        @foreach($portGroups as $g)<option value="{{ $g->id }}" @selected(old('assignment_reference',$assignment->assignment_reference)==$g->id)>{{ $g->name }}</option>@endforeach
    </select>
    <p class="help-block">All interfaces in this LibreNMS port group.</p>
</div>

{{-- Specific port (by numeric id — there can be too many to list) --}}
<div class="form-group iapm-field" data-types="port">
    <label>Port ID</label>
    <input name="assignment_reference" id="iapm-ref" class="form-control" value="{{ old('assignment_reference',$assignment->assignment_reference) }}" placeholder="e.g. 1143">
    <p class="help-block">The stable LibreNMS <code>port_id</code>. Find it on the <a href="{{ route('iapm.matrix') }}" target="_blank">Interface Matrix</a>.</p>
</div>

{{-- Location dropdown --}}
<div class="form-group iapm-field" data-types="location">
    <label>Location</label>
    <select name="assignment_reference" class="form-control iapm-locsel" disabled>
        <option value="">— select a location —</option>
        @foreach($locations as $loc)<option value="{{ $loc->id }}" @selected(old('assignment_reference',$assignment->assignment_reference)==$loc->id)>{{ $loc->location }}</option>@endforeach
    </select>
    <p class="help-block">All interfaces on devices at this location.</p>
</div>

{{-- Interface type --}}
<div class="form-group iapm-field" data-types="interface_type">
    <label>Interface type (ifType)</label>
    <input name="assignment_reference" class="form-control iapm-typeval" disabled value="{{ old('assignment_reference',$assignment->assignment_reference) }}" placeholder="e.g. ethernetCsmacd, sonet, gpon">
    <p class="help-block">Matches the SNMP <code>ifType</code> string exactly.</p>
</div>

{{-- Regex --}}
<div class="form-group iapm-field" data-types="ifalias_regex,ifname_regex">
    <label>Regular expression</label>
    <input name="match_expression" id="iapm-regex" class="form-control iapm-regexval" disabled value="{{ old('match_expression',$assignment->match_expression) }}" placeholder="/^CUST:/">
    <p class="help-block">PCRE with delimiters, e.g. <code>/^xe-/</code>. Validated when you save.</p>
</div>

{{-- Device groups multi-select + mode --}}
<div class="form-group iapm-field" data-types="device_group">
    <label>Device groups</label>
    <select name="device_group_ids[]" class="form-control iapm-groupsel" multiple size="6" disabled>
        @foreach($deviceGroups as $g)<option value="{{ $g->id }}" @selected(in_array((string)$g->id,$selectedGroups,true))>{{ $g->name }}</option>@endforeach
    </select>
    <p class="help-block">Hold Ctrl/Cmd to select several. Mode below controls how multiple groups combine.</p>
</div>

<div class="form-group iapm-field" data-types="device_group">
    <label>Group match mode</label>
    <select name="match_mode" class="form-control">
        <option value="any" @selected(old('match_mode',$assignment->match_mode ?? 'any')==='any')>Match ANY selected group</option>
        <option value="all" @selected(old('match_mode',$assignment->match_mode)==='all')>Match ALL selected groups</option>
        <option value="exclude" @selected(old('match_mode',$assignment->match_mode)==='exclude')>EXCLUDE selected groups</option>
    </select>
</div>
{{-- match_mode still needs a value for non-group types --}}
<input type="hidden" name="match_mode" id="iapm-mode-fallback" value="any" disabled>

<div class="form-group iapm-field" data-types="port,port_group,device,device_group,location,ifalias_regex,ifname_regex,interface_type">
    <label>Receivers (optional, one per line)</label>
    <textarea name="receivers_text" class="form-control" rows="2">{{ old('receivers_text',implode("\n",$assignment->metadata_json['receivers']??[])) }}</textarea>
    <p class="help-block">Overrides the policy/destination receiver for interfaces matched here.</p>
</div>

<div class="form-group"><label>Priority</label>
    <input type="number" name="priority" class="form-control" value="{{ old('priority',$assignment->priority??0) }}">
    <p class="help-block">Higher wins among assignments of the same type.</p>
</div>

<input type="hidden" name="enabled" value="0">
<div class="checkbox"><label><input type="checkbox" name="enabled" value="1" @checked(old('enabled',$assignment->exists?$assignment->enabled:true))> Enabled</label></div>

<button class="btn btn-primary">Save</button>
<button type="button" class="btn btn-default" id="iapm-preview-btn"><i class="fa fa-search"></i> Preview match count</button>
<span id="iapm-preview-result" class="text-muted" style="margin-left:8px;"></span>
</div></div>
</form>

@if($assignment->exists)<form method="post" action="{{ route('iapm.assignments.destroy',$assignment) }}" onsubmit="return confirm('Delete this assignment?')" style="margin-top:10px;">@csrf @method('DELETE')<button class="btn btn-danger">Delete assignment</button></form>@endif
</div>

<script>
(function () {
    var typeSelect = document.getElementById('iapm-type');
    var form = document.getElementById('iapm-assignment-form');

    function sync() {
        var type = typeSelect.value;
        // Show only fields whose data-types includes the current type.
        form.querySelectorAll('.iapm-field').forEach(function (el) {
            var show = el.dataset.types.split(',').indexOf(type) !== -1;
            el.style.display = show ? '' : 'none';
            // Disable hidden inputs so their (stale) values are not submitted.
            el.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = ! show; });
        });
        // match_mode: the group select owns it for device_group; otherwise submit a hidden default.
        document.getElementById('iapm-mode-fallback').disabled = (type === 'device_group');
    }

    typeSelect.addEventListener('change', sync);
    sync();

    document.getElementById('iapm-preview-btn').addEventListener('click', function () {
        var result = document.getElementById('iapm-preview-result');
        result.textContent = 'Evaluating…';
        var body = new URLSearchParams(new FormData(form));
        // On the edit form the hidden _method=PUT would spoof this POST into a PUT
        // and hit the resource update route instead of the preview route — drop it.
        body.delete('_method');
        fetch('{{ route('iapm.assignments.preview') }}', {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        }).then(function (r) { return r.json().then(function (d) { return {ok: r.ok, d: d}; }); }).then(function (res) {
            if (!res.ok) { result.textContent = (res.d && res.d.message) ? res.d.message : 'Preview failed (check the fields).'; return; }
            if (res.d.error) { result.textContent = res.d.error; return; }
            var devices = (res.d.devices || 0) + ' device(s)';
            result.textContent = 'Matches ' + res.d.count + ' interface(s) across ' + devices + (res.d.capped ? ' (sampled; real total is higher)' : '') + '.';
        }).catch(function () { result.textContent = 'Preview failed.'; });
    });
})();
</script>
@endsection
