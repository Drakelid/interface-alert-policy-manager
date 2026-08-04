@extends('layouts.librenmsv1') @section('title','IAPM Assignments') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Assignments <a class="btn btn-primary btn-sm" href="{{ route('iapm.assignments.create') }}"><i class="fa fa-plus"></i> Create</a></h2>
<p class="text-muted">An assignment maps interfaces to a policy. More specific types win (port &gt; port-group &gt; device &gt; device-group &gt; location &gt; regex &gt; type &gt; default). Add a <strong>default</strong> assignment so nothing is left uncovered.</p>
@if($assignments->count())
<div class="table-responsive"><table class="table table-hover">
<thead><tr><th>Policy</th><th>Type</th><th>Reference / expression</th><th>Mode</th><th>Priority</th><th>Status</th></tr></thead>
<tbody>@foreach($assignments as $a)<tr>
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
