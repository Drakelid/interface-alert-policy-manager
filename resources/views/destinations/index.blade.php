@extends('layouts.librenmsv1') @section('title','IAPM Destinations') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Destinations <a class="btn btn-primary btn-sm" href="{{ route('iapm.destinations.create') }}"><i class="fa fa-plus"></i> Create</a></h2>
<p class="text-muted">A destination is where notifications are delivered — the SMS gateway, or a generic webhook. Policy actions send to a destination.</p>
@if($destinations->count())
<div class="table-responsive"><table class="table table-hover">
<thead><tr><th>Name</th><th>Type</th><th>Status</th><th>Used by actions</th><th></th></tr></thead>
<tbody>@foreach($destinations as $d)<tr>
<td><a href="{{ route('iapm.destinations.edit',$d) }}">{{ $d->name }}</a></td>
<td>{{ $d->type === 'sms_gateway' ? 'SMS gateway' : 'Generic webhook' }}</td>
<td>@if($d->enabled)<span class="label label-success">Enabled</span>@else<span class="label label-default">Disabled</span>@endif</td>
<td>{{ $d->actions_count }}</td>
<td>
<a class="btn btn-default btn-xs" href="{{ route('iapm.destinations.edit',$d) }}#test">Test</a>
<form method="post" action="{{ route('iapm.destinations.clone',$d) }}" style="display:inline;">@csrf<button class="btn btn-default btn-xs">Clone disabled</button></form>
</td>
</tr>@endforeach</tbody></table></div>
{{ $destinations->links() }}
@else
@include('iapm::partials.empty-state',['title'=>'No destinations yet','body'=>'Create the SMS gateway destination so IAPM can deliver alerts. Secrets are encrypted at rest; you can send a test message after saving.','route'=>route('iapm.destinations.create'),'action'=>'Create SMS destination'])
@endif
</div>@endsection
