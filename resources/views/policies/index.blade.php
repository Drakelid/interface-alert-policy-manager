@extends('layouts.librenmsv1') @section('title','IAPM Policies') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Policies <a class="btn btn-primary btn-sm" href="{{ route('iapm.policies.create') }}"><i class="fa fa-plus"></i> Create</a></h2>
<p class="text-muted">A policy decides <em>when</em> an interface-down incident notifies: trigger delay, failed-poll count, repeats, escalation, and recovery. Assignments then map interfaces to a policy.</p>
@if($policies->count())
<div class="table-responsive"><table class="table table-hover">
<thead><tr><th>Name</th><th>Severity</th><th>Priority</th><th>Status</th><th>Assignments</th><th>Actions</th><th></th></tr></thead>
<tbody>@foreach($policies as $p)<tr>
<td><a href="{{ route('iapm.policies.edit',$p) }}">{{ $p->name }}</a></td>
<td>{{ $p->severity->value }}</td>
<td>{{ $p->priority }}</td>
<td>@if($p->enabled)<span class="label label-success">Enabled</span>@else<span class="label label-default">Disabled</span>@endif</td>
<td>@if($p->assignments_count)<a href="{{ route('iapm.assignments.index') }}">{{ $p->assignments_count }}</a>@else<span class="text-warning" title="No interfaces use this policy">0</span>@endif</td>
<td>{{ $p->actions_count }}</td>
<td><form method="post" action="{{ route('iapm.policies.clone',$p) }}">@csrf<button class="btn btn-default btn-xs">Clone</button></form></td>
</tr>@endforeach</tbody></table></div>
{{ $policies->links() }}
@else
@include('iapm::partials.empty-state',['title'=>'No policies yet','body'=>'Create a policy to describe how interface-down alerts should behave — trigger delay, repeats, escalation, and recovery notifications.','route'=>route('iapm.policies.create'),'action'=>'Create your first policy'])
@endif
</div>@endsection
