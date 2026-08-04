@extends('layouts.librenmsv1') @section('title','IAPM Assignment') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h2>{{ $assignment->exists?'Edit':'Create' }} Assignment</h2>
<form method="post" action="{{ $assignment->exists?route('iapm.assignments.update',$assignment):route('iapm.assignments.store') }}">@csrf @if($assignment->exists)@method('PUT')@endif
<div class="form-group"><label>Policy</label><select name="policy_id" class="form-control">@foreach($policies as $p)<option value="{{ $p->id }}" @selected(old('policy_id',$assignment->policy_id)==$p->id)>{{ $p->name }}</option>@endforeach</select></div>
<div class="form-group"><label>Type</label><select name="assignment_type" class="form-control">@foreach(['port','port_group','device','device_group','location','ifalias_regex','ifname_regex','interface_type','default'] as $v)<option @selected(old('assignment_type',$assignment->assignment_type?->value)===$v)>{{ $v }}</option>@endforeach</select></div>
<div class="form-group"><label>Reference ID/value</label><input name="assignment_reference" class="form-control" value="{{ old('assignment_reference',$assignment->assignment_reference) }}"></div>
<div class="form-group"><label>Regex expression</label><input name="match_expression" class="form-control" value="{{ old('match_expression',$assignment->match_expression) }}"></div>
<div class="form-group"><label>Device group IDs</label><textarea name="device_group_ids_text" class="form-control">{{ old('device_group_ids_text',$assignment->deviceGroups->pluck('device_group_id')->implode("\n")) }}</textarea><p class="help-block">Separate IDs with spaces, commas, or newlines.</p></div>
<div class="form-group"><label>Receiver metadata (one per line)</label><textarea name="receivers_text" class="form-control">{{ old('receivers_text',implode("\n",$assignment->metadata_json['receivers']??[])) }}</textarea></div>
<div class="form-group"><label>Group mode</label><select name="match_mode" class="form-control">@foreach(['any','all','exclude'] as $v)<option @selected(old('match_mode',$assignment->match_mode??'any')===$v)>{{ $v }}</option>@endforeach</select></div>
<div class="form-group"><label>Priority</label><input type="number" name="priority" class="form-control" value="{{ old('priority',$assignment->priority??0) }}"></div><input type="hidden" name="enabled" value="0"><label><input type="checkbox" name="enabled" value="1" @checked(old('enabled',$assignment->exists?$assignment->enabled:true))> Enabled</label><br>
<button class="btn btn-primary">Save</button> <button type="button" class="btn btn-default" id="iapm-preview-btn">Preview match count</button> <span id="iapm-preview-result" class="text-muted"></span></form>
@if($assignment->exists)<form method="post" action="{{ route('iapm.assignments.destroy',$assignment) }}" onsubmit="return confirm('Delete assignment?')">@csrf @method('DELETE')<button class="btn btn-danger">Delete</button></form>@endif</div>
<script>
document.getElementById('iapm-preview-btn').addEventListener('click', function () {
    var form = document.forms[0];
    var result = document.getElementById('iapm-preview-result');
    result.textContent = 'Evaluating…';
    var body = new URLSearchParams(new FormData(form));
    body.set('_method', 'POST');
    fetch('{{ route('iapm.assignments.preview') }}', {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
        body: body.toString()
    }).then(function (r) { return r.json().then(function (d) { return {ok: r.ok, d: d}; }); }).then(function (res) {
        if (!res.ok) { result.textContent = (res.d && res.d.message) ? res.d.message : 'Preview failed (check the assignment fields).'; return; }
        if (res.d.error) { result.textContent = res.d.error; return; }
        result.textContent = 'Matches ' + res.d.count + ' interface(s)' + (res.d.capped ? ' (sampled; the real total is higher)' : '') + '.';
    }).catch(function () { result.textContent = 'Preview failed.'; });
});
</script>
@endsection
