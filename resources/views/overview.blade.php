@extends('layouts.librenmsv1')
@section('title', 'Interface Alert Policy Manager')
@section('content')
@php
$tile = function ($label, $value, $href, $accent = '', $hot = false) {
    $cls = 'iapm-tile '.$accent.($hot && $value > 0 ? ' hot' : '');
    // data-iapm-tile/-value let KpiTileParityTest pair each tile with its link
    // and assert the number matches the rows on the page it opens.
    return '<a href="'.e($href).'" style="text-decoration:none;" data-iapm-tile="'.e($label).'" data-iapm-value="'.e($value).'"><div class="panel panel-default '.$cls.'"><div class="panel-heading">'.e($label).'</div><div class="panel-body"><strong style="font-size:20px;">'.e($value).'</strong></div></div></a>';
};
@endphp
<div class="container-fluid">
    @include('iapm::partials.nav')
    <div class="iapm-toolbar">
        <h1 class="iapm-page-title" style="margin:0;">Interface Alert Policy Manager</h1>
        <span class="spacer"></span>
        @include('iapm::partials.auto-refresh')
    </div>

    @include('iapm::partials.setup-checklist')

    @php($healthDown = collect($health)->where('ok',false))
    @if($healthDown->isNotEmpty())
    <div class="panel panel-danger">
        <div class="panel-heading"><i class="fa fa-heartbeat"></i> <strong>IAPM health needs attention</strong></div>
        <div class="list-group" style="margin-bottom:0;">
            @foreach($health as $check)<div class="list-group-item"><i class="fa fa-{{ $check['ok']?'check text-success':'times text-danger' }}" style="width:1.2em;"></i> <strong>{{ $check['label'] }}</strong> <span class="iapm-hint">— {{ $check['detail'] }}</span></div>@endforeach
        </div>
    </div>
    @endif

    {{-- P0-3: each tile links to exactly the population it counted. Changing a
         metric here means changing the matching filter on the destination page;
         KpiTileParityTest asserts tile value == total rows behind the link. --}}
    {{-- P4-1: these were two Bootstrap rows of col-sm-2 and col-sm-3, wrapping
         5-then-4 with a lot of empty space beside each number. One auto-fitting
         grid wraps evenly at any width. --}}
    <div class="iapm-tile-grid">
        {!! $tile('Active critical', $metrics['active_critical'], route('iapm.incidents.index',['state'=>'active','severity'=>'critical']), 'crit', true) !!}
        {!! $tile('Active warning', $metrics['active_warning'], route('iapm.incidents.index',['state'=>'active','severity'=>'warning']), 'warn', true) !!}
        {!! $tile('Pending', $counts['pending'] ?? 0, route('iapm.incidents.index',['state'=>'pending']), 'warn') !!}
        {!! $tile('Acknowledged', $counts['acknowledged'] ?? 0, route('iapm.incidents.index',['state'=>'acknowledged']), 'info') !!}
        {!! $tile('Suppressed', $counts['suppressed'] ?? 0, route('iapm.incidents.index',['state'=>'suppressed'])) !!}
        {!! $tile('Recovered (24h)', $metrics['recovered_24h'], route('iapm.incidents.index',['state'=>'recovered','recovered_within'=>24]), 'ok') !!}
        {!! $tile('Failed deliveries (24h)', $metrics['failed_deliveries'], route('iapm.delivery-log',['status'=>'failed_any','within'=>24]), 'crit', true) !!}
        {{-- Counts incidents that alerted with no policy covering them. The label
             used to say "Interfaces without policy" and link to the matrix's
             no_policy filter, which counts every port with no materialized policy
             — a different, far larger population that never matched this number. --}}
        {!! $tile('Alerting, no policy', $metrics['missing_policies'], route('iapm.incidents.index',['state'=>'suppressed','suppression_reason'=>'no_policy']), 'warn', true) !!}
        {!! $tile('Awaiting escalation', $metrics['awaiting_escalation'], route('iapm.incidents.index',['state'=>'active','escalation'=>'pending']), 'warn') !!}
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
        <td class="iapm-actions">@if($incident->state->value!=='acknowledged' && $incident->state->value!=='recovered')<form method="post" action="{{ route('iapm.incidents.acknowledge',$incident) }}">@csrf<button class="btn btn-default btn-xs" title="Acknowledge" aria-label="Acknowledge incident {{ $incident->id }}"><i class="fa fa-check"></i></button></form>@endif</td>
        </tr>@empty<tr><td colspan="6" class="iapm-hint">No incidents recorded yet. Once LibreNMS posts an alert it will appear here.</td></tr>@endforelse</tbody></table></div>
    </div>
    {{ $incidents->links() }}
</div>
@endsection
