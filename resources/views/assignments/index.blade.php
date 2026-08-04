@extends('layouts.librenmsv1') @section('title','IAPM Assignments') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Assignments <a class="btn btn-primary btn-sm" href="{{ route('iapm.assignments.create') }}"><i class="fa fa-plus"></i> Create</a></h2>
@include('iapm::partials.step-header',['step'=>4,'total'=>4,'title'=>'Map interfaces to policies','desc'=>'An assignment maps interfaces to a policy. More specific types win (port &gt; port-group &gt; device &gt; device-group &gt; location &gt; regex &gt; type &gt; default). Add a <strong>default</strong> assignment so nothing is left uncovered. Then finish with the Setup Helper to point LibreNMS at IAPM.','prevRoute'=>route('iapm.schedules.index'),'prevLabel'=>'Schedules','nextRoute'=>route('iapm.setup-helper'),'nextLabel'=>'Setup Helper'])
@if($assignments->count())
<form id="iapm-bulk-assignments" method="post" action="{{ route('iapm.assignments.bulk-destroy') }}" onsubmit="return confirm('Delete the selected assignments?')">@csrf @method('DELETE')
    <button class="btn btn-danger btn-sm" style="margin-bottom:8px;"><i class="fa fa-trash"></i> Delete selected</button>
</form>
<div class="table-responsive"><table class="table table-hover">
<thead><tr><th style="width:2em;"><input type="checkbox" onclick="document.querySelectorAll('.iapm-bulk').forEach(e=>e.checked=this.checked)"></th><th>Policy</th><th>Type</th><th>Reference / expression</th><th>Mode</th><th>Priority</th><th>Status</th></tr></thead>
<tbody>@foreach($assignments as $a)<tr>
<td><input class="iapm-bulk" type="checkbox" form="iapm-bulk-assignments" name="ids[]" value="{{ $a->id }}"></td>
<td><a href="{{ route('iapm.assignments.edit',$a) }}">{{ $a->policy->name }}</a></td>
<td>{{ str_replace('_',' ',$a->assignment_type->value) }}</td>
<td>{{ $a->assignment_reference ?: $a->match_expression ?: ($a->deviceGroups->count() ? $a->deviceGroups->count().' device group(s)' : '—') }}</td>
<td>{{ $a->match_mode }}</td>
<td>{{ $a->priority }}</td>
<td>@if($a->enabled)<span class="label label-success">Enabled</span>@else<span class="label label-default">Disabled</span>@endif</td>
</tr>@endforeach</tbody></table></div>
{{ $assignments->links() }}
@else
@include('iapm::partials.empty-state',['title'=>'No assignments yet','body'=>'Create at least a default assignment so every down interface resolves to a policy. You can add more specific assignments (device, group, regex) on top.','route'=>route('iapm.assignments.create'),'action'=>'Create your first assignment'])
@endif
</div>@endsection
