@extends('layouts.librenmsv1') @section('title','IAPM Schedules') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Schedules <a class="btn btn-primary btn-sm" href="{{ route('iapm.schedules.create') }}"><i class="fa fa-plus"></i> Create</a></h2>
<p class="text-muted">Optional. A schedule restricts a policy's notifications to certain hours (business hours, after-hours, or a custom weekly window). Policies without a schedule notify 24/7.</p>
@if($schedules->count())
<div class="table-responsive"><table class="table table-hover">
<thead><tr><th>Name</th><th>Timezone</th><th>Mode</th><th>Status</th><th>Policies</th></tr></thead>
<tbody>@foreach($schedules as $s)<tr>
<td><a href="{{ route('iapm.schedules.edit',$s) }}">{{ $s->name }}</a></td>
<td>{{ $s->timezone }}</td>
<td>{{ $s->schedule_json['mode'] ?? 'always' }}</td>
<td>@if($s->enabled)<span class="label label-success">Enabled</span>@else<span class="label label-default">Disabled</span>@endif</td>
<td>{{ $s->policies_count }}</td>
</tr>@endforeach</tbody></table></div>
{{ $schedules->links() }}
@else
@include('iapm::partials.empty-state',['title'=>'No schedules yet','body'=>'Schedules are optional. Add one only if you want a policy to notify during specific hours; otherwise policies notify around the clock.','route'=>route('iapm.schedules.create'),'action'=>'Create a schedule'])
@endif
</div>@endsection
