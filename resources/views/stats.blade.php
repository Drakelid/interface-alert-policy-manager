@extends('layouts.librenmsv1') @section('title','IAPM Stats') @section('content')
@php
$fmt = function ($seconds) {
    if ($seconds === null) { return '—'; }
    $s = (int) round($seconds);
    if ($s < 60) { return $s.'s'; }
    if ($s < 3600) { return round($s / 60, 1).'m'; }
    return round($s / 3600, 1).'h';
};
@endphp
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Statistics &amp; SLA</h2>
<form class="form-inline" method="get" style="margin-bottom:12px;">
    <label>Period</label>
    <select name="days" class="form-control" onchange="this.form.submit()">
        @foreach([7,30,90,180,365] as $d)<option value="{{ $d }}" @selected($days==$d)>Last {{ $d }} days</option>@endforeach
    </select>
    <span class="text-muted" style="margin-left:8px;">Based on completed (recovered) outages.</span>
</form>

<div class="row">
    <div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading">Outages</div><div class="panel-body"><strong>{{ $metrics['outages'] }}</strong></div></div></div>
    <div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading" title="Avg first-seen to triggered">MTTA</div><div class="panel-body"><strong>{{ $fmt($metrics['avg_detect']) }}</strong></div></div></div>
    <div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading" title="Avg first-seen to recovered">MTTR</div><div class="panel-body"><strong>{{ $fmt($metrics['avg_duration']) }}</strong></div></div></div>
    <div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading">Longest</div><div class="panel-body"><strong>{{ $fmt($metrics['max_duration']) }}</strong></div></div></div>
    <div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading">Notifications</div><div class="panel-body"><strong>{{ $metrics['notifications'] }}</strong></div></div></div>
    <div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading">Flapping outages</div><div class="panel-body"><strong>{{ $metrics['flapping'] }}</strong></div></div></div>
</div>

@if($deliverySuccessRate !== null)
<p><strong>Delivery success rate:</strong> {{ $deliverySuccessRate }}%
    <span class="text-muted">(@foreach($delivery as $status => $count){{ $status }}: {{ $count }}@if(!$loop->last), @endif @endforeach)</span></p>
@endif

<div class="row">
<div class="col-md-6">
<div class="panel panel-default"><div class="panel-heading">Noisiest interfaces</div>
<table class="table table-condensed"><thead><tr><th>Device</th><th>Port</th><th>Outages</th><th>Total down</th></tr></thead><tbody>
@forelse($topInterfaces as $row)<tr><td><a href="{{ route('device',$row->device_id) }}">{{ $row->device_id }}</a></td><td><a href="{{ url('device/'.$row->device_id.'/port/'.$row->port_id) }}">{{ $row->port_id }}</a></td><td>{{ $row->outages }}</td><td>{{ $fmt($row->total_down) }}</td></tr>@empty<tr><td colspan="4" class="text-muted">No outages in this period.</td></tr>@endforelse
</tbody></table></div>
</div>
<div class="col-md-6">
<div class="panel panel-default"><div class="panel-heading">By policy</div>
<table class="table table-condensed"><thead><tr><th>Policy</th><th>Outages</th><th>Avg duration</th></tr></thead><tbody>
@forelse($byPolicy as $row)<tr><td>{{ $policyNames[$row->policy_id] ?? '—' }}</td><td>{{ $row->outages }}</td><td>{{ $fmt($row->avg_duration) }}</td></tr>@empty<tr><td colspan="3" class="text-muted">No outages in this period.</td></tr>@endforelse
</tbody></table></div>
</div>
</div>
</div>@endsection
