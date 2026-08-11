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
<h1 class="iapm-page-title">Statistics &amp; SLA</h1>
<form class="form-inline" method="get" style="margin-bottom:12px;">
    <label for="iapm-stats-days">Period</label>
    {{-- P3-6: the inline onchange is replaced by the shared data-iapm-submit
         handler so a CSP without 'unsafe-inline' remains viable. --}}
    <select name="days" id="iapm-stats-days" class="form-control" data-iapm-submit-on-change>
        @foreach([7,30,90,180,365] as $d)<option value="{{ $d }}" @selected($days==$d)>Last {{ $d }} days</option>@endforeach
    </select>
    <noscript><button class="btn btn-default">Apply</button></noscript>
    <span class="iapm-hint" style="margin-left:8px;">Based on completed (recovered) outages.</span>
</form>

<div class="row">
    <div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading">Outages</div><div class="panel-body"><strong>{{ $metrics['outages'] }}</strong></div></div></div>
    <div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading" title="Avg first-seen to triggered">MTTA</div><div class="panel-body"><strong>{{ $fmt($metrics['avg_detect']) }}</strong></div></div></div>
    <div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading" title="Avg first-seen to recovered">MTTR</div><div class="panel-body"><strong>{{ $fmt($metrics['avg_duration']) }}</strong></div></div></div>
    <div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading">Longest</div><div class="panel-body"><strong>{{ $fmt($metrics['max_duration']) }}</strong></div></div></div>
    <div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading">Notifications</div><div class="panel-body"><strong>{{ $metrics['notifications'] }}</strong></div></div></div>
    <div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading">Flapping outages</div><div class="panel-body"><strong>{{ $metrics['flapping'] }}</strong></div></div></div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">Outages per day</div>
    <div class="panel-body text-primary" style="text-align:center;">
        @include('iapm::partials.sparkline',['values'=>array_values($spark),'iapmLabels'=>array_keys($spark),'label'=>'Outages per day','width'=>720,'height'=>48])
        <div class="iapm-hint small">{{ array_key_first($spark) }} → {{ array_key_last($spark) }}</div>
    </div>
</div>

@if($deliverySuccessRate !== null)
<p><strong>Delivery success rate:</strong> {{ $deliverySuccessRate }}%
    <span class="iapm-hint">(logical notifications: @foreach($delivery as $status => $count){{ $status }}: {{ $count }}@if(!$loop->last), @endif @endforeach; transport attempts: {{ $transportAttempts }})</span></p>
@endif

<div class="row">
<div class="col-md-6">
<div class="panel panel-default"><div class="panel-heading"><h2 style="font-size:15px;margin:0;">Noisiest interfaces</h2></div>
<div class="iapm-table-wrap"><table class="table table-condensed"><thead><tr><th>Device</th><th>Port</th><th class="iapm-num">Outages</th><th class="iapm-num">Total down</th></tr></thead><tbody>
@forelse($topInterfaces as $row)<tr><td><a href="{{ route('device',$row->device_id) }}">{{ $row->device_id }}</a></td><td><a href="{{ url('device/'.$row->device_id.'/port/'.$row->port_id) }}">{{ $row->port_id }}</a></td><td class="iapm-num">{{ $row->outages }}</td><td class="iapm-num">{{ $fmt($row->total_down) }}</td></tr>@empty<tr><td colspan="4" class="iapm-hint">No outages in this period.</td></tr>@endforelse
</tbody></table></div></div>
</div>
<div class="col-md-6">
<div class="panel panel-default"><div class="panel-heading"><h2 style="font-size:15px;margin:0;">By policy</h2></div>
<div class="iapm-table-wrap"><table class="table table-condensed"><thead><tr><th>Policy</th><th class="iapm-num">Outages</th><th class="iapm-num">Avg duration</th></tr></thead><tbody>
@forelse($byPolicy as $row)<tr><td>{{ $policyNames[$row->policy_id] ?? '—' }}</td><td class="iapm-num">{{ $row->outages }}</td><td class="iapm-num">{{ $fmt($row->avg_duration) }}</td></tr>@empty<tr><td colspan="3" class="iapm-hint">No outages in this period.</td></tr>@endforelse
</tbody></table></div></div>
</div>
</div>
</div>@endsection
