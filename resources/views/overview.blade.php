@extends('layouts.librenmsv1')
@section('title', 'Interface Alert Policy Manager')
@section('content')
@php
$tile = function ($label, $value, $href, $accent = '', $hot = false) {
    $cls = 'iapm-tile '.$accent.($hot && $value > 0 ? ' hot' : '');
    return '<a href="'.e($href).'" style="text-decoration:none;"><div class="panel panel-default '.$cls.'"><div class="panel-heading">'.e($label).'</div><div class="panel-body"><strong style="font-size:20px;">'.e($value).'</strong></div></div></a>';
};
@endphp
<div class="container-fluid">
    @include('iapm::partials.nav')
    <div class="iapm-toolbar">
        <h2 style="margin:0;">Interface Alert Policy Manager</h2>
        <span class="spacer"></span>
        <span id="iapm-autorefresh" data-interval="30"><label class="text-muted" style="font-weight:normal;"><input type="checkbox"> Auto-refresh</label> <span class="text-muted small iapm-updated"></span></span>
    </div>

    @include('iapm::partials.setup-checklist')

    @php($healthDown = collect($health)->where('ok',false))
    @if($healthDown->isNotEmpty())
    <div class="panel panel-danger">
        <div class="panel-heading"><i class="fa fa-heartbeat"></i> <strong>IAPM health needs attention</strong></div>
        <div class="list-group" style="margin-bottom:0;">
            @foreach($health as $check)<div class="list-group-item"><i class="fa fa-{{ $check['ok']?'check text-success':'times text-danger' }}" style="width:1.2em;"></i> <strong>{{ $check['label'] }}</strong> <span class="text-muted">— {{ $check['detail'] }}</span></div>@endforeach
        </div>
    </div>
    @endif

    <div class="row">
        <div class="col-sm-2">{!! $tile('Active critical', $metrics['active_critical'], route('iapm.incidents.index',['state'=>'active']), 'crit', true) !!}</div>
        <div class="col-sm-2">{!! $tile('Active warning', $metrics['active_warning'], route('iapm.incidents.index',['state'=>'active']), 'warn', true) !!}</div>
        <div class="col-sm-2">{!! $tile('Pending', $counts['pending'] ?? 0, route('iapm.incidents.index',['state'=>'pending']), 'warn') !!}</div>
        <div class="col-sm-2">{!! $tile('Acknowledged', $counts['acknowledged'] ?? 0, route('iapm.incidents.index',['state'=>'acknowledged']), 'info') !!}</div>
        <div class="col-sm-2">{!! $tile('Suppressed', $counts['suppressed'] ?? 0, route('iapm.incidents.index',['state'=>'suppressed'])) !!}</div>
    </div>
    <div class="row">
        <div class="col-sm-3">{!! $tile('Recovered (24h)', $metrics['recovered_24h'], route('iapm.incidents.index',['state'=>'recovered']), 'ok') !!}</div>
        <div class="col-sm-3">{!! $tile('Failed deliveries (24h)', $metrics['failed_deliveries'], route('iapm.delivery-log',['status'=>'failed']), 'crit', true) !!}</div>
        <div class="col-sm-3">{!! $tile('Interfaces without policy', $metrics['missing_policies'], route('iapm.matrix',['no_policy'=>1]), 'warn', true) !!}</div>
        <div class="col-sm-3">{!! $tile('Awaiting escalation', $metrics['awaiting_escalation'], route('iapm.incidents.index',['state'=>'active']), 'warn') !!}</div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">Recent incidents <a class="pull-right" href="{{ route('iapm.incidents.index') }}">All incidents →</a></div>
        <div class="iapm-table-wrap"><table class="table table-hover table-condensed">
        <thead><tr><th>ID</th><th>Interface</th><th>State</th><th>Severity</th><th>Last seen</th><th></th></tr></thead>
        <tbody>@forelse($incidents as $incident)@php($c=(array)$incident->context_json)<tr>
        <td><a href="{{ route('iapm.incidents.show',$incident) }}">{{ $incident->id }}</a></td>
        <td class="iapm-truncate"><a href="{{ route('device',$incident->device_id) }}">{{ $c['hostname'] ?? $incident->device_id }}</a> — {{ $c['ifName'] ?? $incident->port_id }}</td>
        <td>@include('iapm::partials.state-label',['state'=>$incident->state->value])</td>
        <td>{{ $incident->severity->value }}</td>
        <td>@include('iapm::partials.time',['at'=>$incident->last_seen_at])</td>
        <td class="iapm-actions">@if($incident->state->value!=='acknowledged' && $incident->state->value!=='recovered')<form method="post" action="{{ route('iapm.incidents.acknowledge',$incident) }}">@csrf<button class="btn btn-default btn-xs" title="Acknowledge"><i class="fa fa-check"></i></button></form>@endif</td>
        </tr>@empty<tr><td colspan="6" class="text-muted">No incidents recorded yet. Once LibreNMS posts an alert it will appear here.</td></tr>@endforelse</tbody></table></div>
    </div>
    {{ $incidents->links() }}
</div>
@endsection
