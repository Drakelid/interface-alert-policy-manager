@extends('layouts.librenmsv1') @section('title','IAPM Policies') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Policies <a class="btn btn-primary btn-sm" href="{{ route('iapm.policies.create') }}"><i class="fa fa-plus"></i> Create</a></h2>
@include('iapm::partials.step-header',['step'=>2,'total'=>4,'title'=>'Create a policy','desc'=>'A policy decides <em>when</em> an interface-down incident notifies — trigger delay, failed-poll count, repeats, escalation, and recovery. After saving, add notification <strong>actions</strong> pointing at a destination.','prevRoute'=>route('iapm.destinations.index'),'prevLabel'=>'Destinations','nextRoute'=>route('iapm.schedules.index'),'nextLabel'=>'Schedules'])
@if($policies->count())
<form id="iapm-bulk-policies" method="post" action="{{ route('iapm.policies.bulk-destroy') }}" onsubmit="return confirm('Delete the selected policies? Any still referenced by assignments or active incidents are skipped.')">@csrf @method('DELETE')
    <button class="btn btn-danger btn-sm" style="margin-bottom:8px;"><i class="fa fa-trash"></i> Delete selected</button>
</form>
<div class="table-responsive"><table class="table table-hover">
<thead><tr><th style="width:2em;"><input type="checkbox" onclick="document.querySelectorAll('.iapm-bulk').forEach(e=>e.checked=this.checked)"></th><th>Name</th><th>Severity</th><th>Priority</th><th>Status</th><th>Assignments</th><th>Actions</th><th></th></tr></thead>
<tbody>@foreach($policies as $p)<tr>
<td><input class="iapm-bulk" type="checkbox" form="iapm-bulk-policies" name="ids[]" value="{{ $p->id }}"></td>
<td><a href="{{ route('iapm.policies.edit',$p) }}">{{ $p->name }}</a></td>
<td>{{ $p->severity->value }}</td>
<td>{{ $p->priority }}</td>
<td>@if($p->enabled)<span class="label label-success">Enabled</span>@else<span class="label label-default">Disabled</span>@endif</td>
<td>@if($p->assignments_count)<a href="{{ route('iapm.assignments.index') }}">{{ $p->assignments_count }}</a>@else<span class="text-warning" title="No interfaces use this policy">0</span>@endif</td>
<td>@if($p->enabled_actions_count>0){{ $p->enabled_actions_count }}@else<span class="label label-warning" title="This policy has no enabled notification action — matched interfaces will not page anyone."><i class="fa fa-bell-slash"></i> won't notify</span>@endif</td>
{{-- P1-4: the row name was the only way to reach the editor and carried no
     visual signal that it was an affordance at all. --}}
<td class="iapm-actions" style="white-space:nowrap;">
    <a class="btn btn-default btn-xs" href="{{ route('iapm.policies.edit',$p) }}"><i class="fa fa-pencil"></i> Edit</a>
    <form method="post" action="{{ route('iapm.policies.clone',$p) }}" style="display:inline;">@csrf<button class="btn btn-default btn-xs"><i class="fa fa-copy"></i> Clone</button></form>
</td>
</tr>@endforeach</tbody></table></div>
{{ $policies->links() }}
@else
@include('iapm::partials.empty-state',['title'=>'No policies yet','body'=>'Create a policy to describe how interface-down alerts should behave — trigger delay, repeats, escalation, and recovery notifications.','route'=>route('iapm.policies.create'),'action'=>'Create your first policy'])
@endif
</div>@endsection
