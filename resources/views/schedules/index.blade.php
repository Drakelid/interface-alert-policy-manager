@extends('layouts.librenmsv1') @section('title','IAPM Schedules') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h1 class="iapm-page-title">Schedules <a class="btn btn-primary btn-sm" href="{{ route('iapm.schedules.create') }}"><i class="fa fa-plus"></i> Create</a></h1>
@include('iapm::partials.step-header',['step'=>3,'total'=>4,'title'=>'Add schedules (optional)','desc'=>'Optional. A schedule restricts a policy&#39;s notifications to certain hours (business hours, after-hours, or a custom weekly window). Skip this if policies should notify 24/7.','prevRoute'=>route('iapm.policies.index'),'prevLabel'=>'Policies','nextRoute'=>route('iapm.assignments.index'),'nextLabel'=>'Assignments'])
@if($schedules->count())
<form id="iapm-bulk-schedules" method="post" action="{{ route('iapm.schedules.bulk-destroy') }}" data-iapm-confirm="Delete the selected schedules? Any used by policies are skipped.">@csrf @method('DELETE')
    <button class="btn btn-danger btn-sm" style="margin-bottom:8px;"><i class="fa fa-trash"></i> Delete selected</button>
</form>
<div class="table-responsive"><table class="table table-hover">
<thead><tr><th style="width:2em;"><input type="checkbox" aria-label="Select all schedules on this page" data-iapm-toggle-all=".iapm-bulk"></th><th>Name</th><th>Timezone</th><th>Mode</th><th>Status</th><th>Policies</th></tr></thead>
<tbody>@foreach($schedules as $s)<tr>
<td><input class="iapm-bulk" type="checkbox" form="iapm-bulk-schedules" name="ids[]" value="{{ $s->id }}" aria-label="Select schedule {{ $s->name }}"></td>
<td><a href="{{ route('iapm.schedules.edit',$s) }}">{{ $s->name }}</a></td>
<td>{{ $s->timezone }}</td>
<td>{{ $s->schedule_json['mode'] ?? 'always' }}</td>
<td>@if($s->enabled)<span class="label label-success">Enabled</span>@else<span class="label label-default">Disabled</span>@endif</td>
<td>{{ $s->policies_count }}</td>
</tr>@endforeach</tbody></table></div>
{{ $schedules->links() }}
@else
@include('iapm::partials.empty-state',['title'=>'No schedules yet','body'=>'Schedules are optional. Add one only if you want a policy to notify during specific hours; otherwise policies notify around the clock.','route'=>route('iapm.schedules.create'),'action'=>'Create a schedule','secondaryRoute'=>route('iapm.assignments.index'),'secondaryAction'=>'Skip to Assignments'])
@endif
</div>@endsection
