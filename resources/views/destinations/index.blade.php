@extends('layouts.librenmsv1') @section('title','IAPM Destinations') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Destinations <a class="btn btn-primary btn-sm" href="{{ route('iapm.destinations.create') }}"><i class="fa fa-plus"></i> Create</a></h2>
@include('iapm::partials.step-header',['step'=>1,'total'=>4,'title'=>'Create a delivery destination','desc'=>'A destination is where notifications are sent — the SMS gateway, or a generic webhook. Create this first: policy actions send to a destination. Secrets are encrypted; you can send a test after saving.','nextRoute'=>route('iapm.policies.index'),'nextLabel'=>'Policies'])
@if($destinations->count())
<form id="iapm-bulk-destinations" method="post" action="{{ route('iapm.destinations.bulk-destroy') }}" data-iapm-confirm="Delete the selected destinations? Their stored credentials are erased. Any still used by policy actions are skipped.">@csrf @method('DELETE')
    <button class="btn btn-danger btn-sm" style="margin-bottom:8px;" data-iapm-bulk-button="destinations" disabled><i class="fa fa-trash"></i> Delete selected<span data-iapm-bulk-count></span></button>
</form>
<div class="table-responsive" data-iapm-bulk-scope="destinations"><table class="table table-hover">
<thead><tr><th style="width:2em;"><input type="checkbox" onclick="document.querySelectorAll('.iapm-bulk').forEach(e=>e.checked=this.checked)"></th><th>Name</th><th>Type</th><th>Status</th><th>Used by actions</th><th></th></tr></thead>
<tbody>@foreach($destinations as $d)<tr>
<td><input class="iapm-bulk" type="checkbox" form="iapm-bulk-destinations" name="ids[]" value="{{ $d->id }}"></td>
<td><a href="{{ route('iapm.destinations.edit',$d) }}">{{ $d->name }}</a></td>
<td>{{ $d->type === 'sms_gateway' ? 'SMS gateway' : 'Generic webhook' }}</td>
<td>@if($d->enabled)<span class="label label-success">Enabled</span>@else<span class="label label-default">Disabled</span>@endif</td>
<td>{{ $d->actions_count }}</td>
{{-- P1-4: Test and Clone were buttons but editing was only the row name. --}}
<td class="iapm-actions" style="white-space:nowrap;">
    <a class="btn btn-default btn-xs" href="{{ route('iapm.destinations.edit',$d) }}"><i class="fa fa-pencil"></i> Edit</a>
    <a class="btn btn-default btn-xs" href="{{ route('iapm.destinations.edit',$d) }}#test"><i class="fa fa-paper-plane"></i> Test</a>
    <form method="post" action="{{ route('iapm.destinations.clone',$d) }}" style="display:inline;">@csrf<button class="btn btn-default btn-xs"><i class="fa fa-copy"></i> Clone</button></form>
</td>
</tr>@endforeach</tbody></table></div>
{{ $destinations->links() }}
@else
@include('iapm::partials.empty-state',['title'=>'No destinations yet','body'=>'Create the SMS gateway destination so IAPM can deliver alerts. Secrets are encrypted at rest; you can send a test message after saving.','route'=>route('iapm.destinations.create'),'action'=>'Create SMS destination'])
@endif
</div>@endsection
