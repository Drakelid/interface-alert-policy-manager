@extends('layouts.librenmsv1') @section('title','IAPM Policy Test') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h2>Policy Test</h2><form class="form-inline" method="get"><input type="number" required name="port_id" value="{{ request('port_id') }}" class="form-control" placeholder="LibreNMS port_id"><button class="btn btn-primary">Evaluate</button></form>
@if($port)<h3>{{ $port->device->hostname }} / {{ $port->ifName }}</h3>
@if($resolution->policy)<div class="alert alert-success">Effective policy: <strong>{{ $resolution->policy->name }}</strong>. @if($resolution->winner)Selected by {{ $resolution->winner->assignment_type->value }} assignment {{ $resolution->winner->id }}.@else Selected by the configured default-policy setting.@endif</div>@else<div class="alert alert-warning">No effective policy.</div>@endif

@if($resolution->policy)
<div class="panel panel-default">
    <div class="panel-heading"><i class="fa fa-bell"></i> Who this would page</div>
    <div class="panel-body" style="padding-bottom:0;">
        @if(count($delivery))
        <table class="table table-condensed"><thead><tr><th>Phase</th><th>Destination</th><th>Resolved receivers</th></tr></thead>
        <tbody>@foreach($delivery as $d)<tr>
            <td>{{ $d['phase'] }}</td>
            <td>{{ $d['destination'] ?? '—' }}</td>
            <td>@if(count($d['receivers']))@foreach($d['receivers'] as $rcv)<span class="label label-info">{{ $rcv }}</span> @endforeach@else<span class="label label-danger" title="No receiver resolves — this action would fail configuration">no receiver</span>@endif</td>
        </tr>@endforeach</tbody></table>
        @else
        <p class="text-warning" style="margin-bottom:12px;"><i class="fa fa-bell-slash"></i> This policy has no enabled notification action — matched interfaces would trigger silently.</p>
        @endif
    </div>
</div>
@endif
<table class="table"><thead><tr><th>Winner</th><th>Assignment</th><th>Type</th><th>Assignment priority</th><th>Policy</th><th>Policy priority</th><th>Updated</th></tr></thead><tbody>@foreach($resolution->candidates as $candidate)<tr><td>{{ $candidate->id===$resolution->winner?->id?'Yes':'' }}</td><td>{{ $candidate->id }}</td><td>{{ $candidate->assignment_type->value }}</td><td>{{ $candidate->priority }}</td><td>{{ $candidate->policy->name }}</td><td>{{ $candidate->policy->priority }}</td><td>{{ $candidate->updated_at }}</td></tr>@endforeach</tbody></table>@endif</div>@endsection
