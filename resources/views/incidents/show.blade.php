@extends('layouts.librenmsv1') @section('title','IAPM Incident '.$incident->id) @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
@php($ctx = (array) $incident->context_json)
<h2>Incident {{ $incident->id }} @include('iapm::partials.state-label',['state'=>$incident->state->value])</h2>

<div class="row"><div class="col-md-6">
<dl class="dl-horizontal">
    <dt>Interface</dt><dd>{{ $ctx['hostname'] ?? $incident->device_id }} — {{ $ctx['ifName'] ?? ('port '.$incident->port_id) }}</dd>
    <dt>Description</dt><dd>{{ $ctx['ifAlias'] ?? '—' }}</dd>
    <dt>Policy</dt><dd>@if($incident->policy)<a href="{{ route('iapm.policies.edit',$incident->policy) }}">{{ $incident->policy->name }}</a>@else<span class="text-warning">none</span>@endif</dd>
    <dt>Severity</dt><dd>{{ $incident->severity->value }}</dd>
    <dt>Suppression</dt><dd>{{ $incident->suppression_reason ?: '—' }}</dd>
    <dt>First seen</dt><dd>{{ $incident->first_seen_at }}</dd>
    <dt>Triggered</dt><dd>{{ $incident->triggered_at ?: '—' }}</dd>
    <dt>Recovered</dt><dd>{{ $incident->recovered_at ?: '—' }}</dd>
    <dt>Muted until</dt><dd>{{ $incident->muted_until ?: '—' }}</dd>
    <dt>Notifications</dt><dd>{{ $incident->notification_count }}</dd>
</dl>
</div><div class="col-md-6">
<div class="panel panel-default"><div class="panel-heading">Open in LibreNMS</div><div class="panel-body">
    <a class="btn btn-default btn-sm" href="{{ route('device',$incident->device_id) }}"><i class="fa fa-server"></i> Device</a>
    <a class="btn btn-default btn-sm" href="{{ url('device/'.$incident->device_id.'/port/'.$incident->port_id) }}"><i class="fa fa-plug"></i> Port</a>
    <a class="btn btn-default btn-sm" href="{{ route('iapm.matrix',['device_id'=>$incident->device_id]) }}"><i class="fa fa-table"></i> Interface Matrix</a>
</div></div>
</div></div>

<div class="panel panel-default"><div class="panel-heading">Actions</div><div class="panel-body form-inline">
@if($incident->state->value!=='acknowledged'&&$incident->state->value!=='recovered')<form method="post" action="{{ route('iapm.incidents.acknowledge',$incident) }}" style="display:inline;">@csrf<button class="btn btn-primary btn-sm">Acknowledge</button></form>@elseif($incident->state->value==='acknowledged')<form method="post" action="{{ route('iapm.incidents.unacknowledge',$incident) }}" style="display:inline;">@csrf<button class="btn btn-default btn-sm">Unacknowledge</button></form>@endif
<form method="post" action="{{ route('iapm.incidents.reconcile',$incident) }}" style="display:inline;" data-iapm-busy>@csrf<button class="btn btn-default btn-sm" data-busy="Reconciling…">Reconcile now</button></form>
<form method="post" action="{{ route('iapm.incidents.mute',$incident) }}" style="display:inline;">@csrf<input type="datetime-local" name="muted_until" required class="form-control input-sm"><button class="btn btn-warning btn-sm">Mute</button></form>
@if($incident->muted_until)<form method="post" action="{{ route('iapm.incidents.unmute',$incident) }}" style="display:inline;">@csrf<button class="btn btn-default btn-sm">Unmute</button></form>@endif
</div></div>

@if($incident->policy)<div class="panel panel-default"><div class="panel-heading">Controlled resend</div><div class="panel-body">
<form class="form-inline" method="post" action="{{ route('iapm.incidents.resend',$incident) }}" data-iapm-busy onsubmit="return confirm('Send this notification action now? Dry-run settings still apply.')">@csrf
<select class="form-control input-sm" name="action_id">@foreach($incident->policy->actions as $action)<option value="{{ $action->id }}">{{ $action->phase->value }} — {{ $action->destination?->name }}</option>@endforeach</select>
<button class="btn btn-warning btn-sm">Resend action</button></form>
</div></div>@endif

<h3>Timeline</h3><div class="table-responsive"><table class="table table-condensed"><thead><tr><th>Time</th><th>Event</th><th>Message</th></tr></thead><tbody>@foreach($incident->events->sortByDesc('created_at') as $e)<tr><td style="white-space:nowrap;">{{ $e->created_at }}</td><td><span class="label label-default">{{ $e->event_type }}</span></td><td>{{ $e->event_message }}</td></tr>@endforeach</tbody></table></div>

<h3>Deliveries</h3><div class="table-responsive"><table class="table table-condensed"><thead><tr><th>Time</th><th>Phase</th><th>Status</th><th>HTTP</th><th>Error</th></tr></thead><tbody>@forelse($incident->deliveries->sortByDesc('created_at') as $delivery)<tr><td style="white-space:nowrap;">{{ $delivery->created_at }}</td><td>{{ $delivery->phase }}</td><td>@if($delivery->status==='sent')<span class="label label-success">sent</span>@elseif($delivery->status==='dry_run')<span class="label label-info">dry-run</span>@else<span class="label label-danger">{{ $delivery->status }}</span>@endif</td><td>{{ $delivery->response_status }}</td><td>{{ $delivery->error_message }}</td></tr>@empty<tr><td colspan="5" class="text-muted">No delivery attempts.</td></tr>@endforelse</tbody></table></div>
</div>@endsection
