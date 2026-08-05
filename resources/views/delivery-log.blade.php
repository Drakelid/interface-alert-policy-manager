@extends('layouts.librenmsv1') @section('title','IAPM Delivery Log') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h2>Delivery Log</h2>
<form class="form-inline" style="margin-bottom:10px;">
    <input class="form-control input-sm" name="incident_id" value="{{ request('incident_id') }}" placeholder="Incident ID">
    <input class="form-control input-sm" name="destination_id" value="{{ request('destination_id') }}" placeholder="Destination ID">
    <select class="form-control input-sm" name="status"><option value="">Any status</option>@foreach(['sent','failed','failed_configuration','dry_run'] as $v)<option @selected(request('status')===$v)>{{ $v }}</option>@endforeach</select>
    <select class="form-control input-sm" name="phase"><option value="">Any phase</option>@foreach(['trigger','escalation','reminder','recovery','acknowledged','flapping','test'] as $v)<option @selected(request('phase')===$v)>{{ $v }}</option>@endforeach</select>
    <button class="btn btn-default btn-sm">Filter</button>
</form>
<div class="iapm-table-wrap"><table class="table table-condensed table-hover">
<thead><tr><th>Time</th><th>Incident</th><th class="iapm-num">Dest</th><th>Phase</th><th>Status</th><th class="iapm-num">HTTP</th><th>Error</th></tr></thead>
<tbody>@forelse($deliveries as $d)<tr>
<td>@include('iapm::partials.time',['at'=>$d->created_at])</td>
<td>@if($d->incident_id)<a href="{{ route('iapm.incidents.show',$d->incident_id) }}">{{ $d->incident_id }}</a>@else<span class="text-muted">—</span>@endif</td>
<td class="iapm-num">{{ $d->destination_id }}</td>
<td>{{ $d->phase }}</td>
<td>@if($d->status==='sent')<span class="label label-success">sent</span>@elseif($d->status==='dry_run')<span class="label label-info">dry-run</span>@else<span class="label label-danger">{{ $d->status }}</span>@endif</td>
<td class="iapm-num">{{ $d->response_status }}</td>
<td class="iapm-truncate" title="{{ $d->error_message }}">{{ $d->error_message }}</td>
</tr>@empty<tr><td colspan="7" class="text-muted">No deliveries match.</td></tr>@endforelse
</tbody></table></div>{{ $deliveries->links() }}</div>@endsection
