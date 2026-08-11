@extends('layouts.librenmsv1') @section('title','IAPM Incidents') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Active Incidents</h2>

<div class="iapm-toolbar">
    <form class="form-inline" method="get">
        <select name="state" class="form-control input-sm"><option value="">Open incidents</option><option value="all" @selected(request('state')==='all')>All states</option>@foreach(['active','pending','acknowledged','suppressed','recovered'] as $v)<option @selected(request('state')===$v)>{{ $v }}</option>@endforeach</select>
        {{-- P0-3: every Overview KPI tile links here with a filter that reproduces
             exactly what it counted, so each one needs a visible, adjustable control. --}}
        <select name="severity" class="form-control input-sm"><option value="">Any severity</option>@foreach(['critical','warning','info'] as $v)<option @selected(request('severity')===$v)>{{ $v }}</option>@endforeach</select>
        <select name="suppression_reason" class="form-control input-sm"><option value="">Any suppression reason</option>@foreach($suppressionReasons as $v)<option value="{{ $v }}" @selected(request('suppression_reason')===$v)>{{ str_replace('_',' ',$v) }}</option>@endforeach</select>
        <select name="recovered_within" class="form-control input-sm"><option value="">Recovered: any time</option>@foreach([1=>'last hour',24=>'last 24 hours',168=>'last 7 days'] as $hours=>$label)<option value="{{ $hours }}" @selected((string) request('recovered_within')===(string) $hours)>Recovered: {{ $label }}</option>@endforeach</select>
        <input class="form-control input-sm" name="device_id" value="{{ request('device_id') }}" placeholder="Device ID">
        <label class="checkbox-inline"><input type="checkbox" name="escalation" value="pending" @checked(request('escalation')==='pending')> Awaiting escalation</label>
        <button class="btn btn-default btn-sm">Filter</button>
    </form>
    <span class="spacer"></span>
    <span id="iapm-autorefresh" data-interval="30"><label class="text-muted" style="font-weight:normal;"><input type="checkbox"> Auto-refresh</label> <span class="text-muted small iapm-updated"></span></span>
</div>

@include('iapm::partials.result-count',['paginator'=>$incidents,'noun'=>'incident'])

@if($incidents->count())
{{-- Bulk form lives outside the table; checkboxes attach via the form= attribute so per-row action forms aren't nested. --}}
<form id="iapm-bulk-incidents" method="post" action="{{ route('iapm.incidents.bulk') }}" class="form-inline" data-iapm-busy onsubmit="return confirm('Apply to the selected incidents?')">@csrf
    <select name="operation" class="form-control input-sm"><option value="acknowledge">Acknowledge</option><option value="mute">Mute</option><option value="unmute">Unmute</option></select>
    <input type="datetime-local" name="muted_until" class="form-control input-sm" title="Required when muting">
    <button class="btn btn-warning btn-sm">Apply to selected</button>
</form>
<div class="iapm-table-wrap" style="margin-top:8px;"><table class="table table-hover table-condensed iapm-sticky">
<thead><tr>
<th style="width:2em;"><input type="checkbox" aria-label="Select all incidents on this page" onclick="document.querySelectorAll('.iapm-bulk').forEach(e=>e.checked=this.checked)"></th>
@include('iapm::partials.sort-header',['column'=>'id','label'=>'ID','numeric'=>true])
<th>Interface</th><th>Policy</th>
@include('iapm::partials.sort-header',['column'=>'state','label'=>'State'])
@include('iapm::partials.sort-header',['column'=>'severity','label'=>'Severity'])
@include('iapm::partials.sort-header',['column'=>'first_seen_at','label'=>'Down'])
@include('iapm::partials.sort-header',['column'=>'last_seen_at','label'=>'Last seen'])
<th>Actions</th>
</tr></thead>
<tbody>@foreach($incidents as $i)@php($c=(array)$i->context_json)<tr>
<td><input class="iapm-bulk" type="checkbox" form="iapm-bulk-incidents" name="incident_ids[]" value="{{ $i->id }}"></td>
<td><a href="{{ route('iapm.incidents.show',$i) }}">{{ $i->id }}</a></td>
<td class="iapm-truncate" title="{{ ($c['hostname'] ?? $i->device_id).' — '.($c['ifAlias'] ?? '') }}"><a href="{{ route('device',$i->device_id) }}">{{ $c['hostname'] ?? $i->device_id }}</a> — {{ $c['ifName'] ?? $i->port_id }}</td>
<td>@if($i->policy)<a href="{{ route('iapm.policies.edit',$i->policy) }}">{{ $i->policy->name }}</a>@else<span class="text-warning">none</span>@endif</td>
<td>@include('iapm::partials.state-label',['state'=>$i->state->value])@if($i->muted_until && $i->muted_until->isFuture()) <i class="fa fa-volume-off text-muted" title="Muted until {{ $i->muted_until }}"></i>@endif</td>
<td>{{ $i->severity->value }}</td>
<td>@include('iapm::partials.time',['at'=>$i->first_seen_at])</td>
<td>@include('iapm::partials.time',['at'=>$i->last_seen_at])</td>
<td class="iapm-actions" style="white-space:nowrap;">
    @if($i->state->value!=='acknowledged' && $i->state->value!=='recovered')<form method="post" action="{{ route('iapm.incidents.acknowledge',$i) }}">@csrf<button class="btn btn-default btn-xs" title="Acknowledge"><i class="fa fa-check"></i></button></form>@endif
    @if(!($i->muted_until && $i->muted_until->isFuture()) && $i->state->value!=='recovered')<form method="post" action="{{ route('iapm.incidents.mute',$i) }}">@csrf<input type="hidden" name="muted_until" value="{{ now()->addHour()->format('Y-m-d\TH:i:s') }}"><button class="btn btn-default btn-xs" title="Mute 1 hour"><i class="fa fa-volume-off"></i></button></form>@elseif($i->muted_until && $i->muted_until->isFuture())<form method="post" action="{{ route('iapm.incidents.unmute',$i) }}">@csrf<button class="btn btn-default btn-xs" title="Unmute"><i class="fa fa-volume-up"></i></button></form>@endif
    <form method="post" action="{{ route('iapm.incidents.reconcile',$i) }}" data-iapm-busy>@csrf<button class="btn btn-default btn-xs" title="Reconcile now" data-busy="…"><i class="fa fa-refresh"></i></button></form>
</td>
</tr>@endforeach</tbody></table></div>{{ $incidents->links() }}
@else
@include('iapm::partials.empty-state',['title'=>'No incidents match','body'=>'No interface incidents recorded yet, or none match the current filter. Incidents appear once LibreNMS posts an alert to IAPM.','route'=>route('iapm.setup-helper'),'action'=>'Open setup helper'])
@endif
</div>@endsection
