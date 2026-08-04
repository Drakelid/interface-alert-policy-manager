@extends('layouts.librenmsv1')
@section('title', 'Interface Alert Policy Manager')
@section('content')
<div class="container-fluid">
    @include('iapm::partials.nav')
    <h2>Interface Alert Policy Manager</h2>
    <div class="row">
        <div class="col-sm-2"><div class="panel panel-danger"><div class="panel-heading">Active critical</div><div class="panel-body"><strong>{{ $metrics['active_critical'] }}</strong></div></div></div>
        <div class="col-sm-2"><div class="panel panel-warning"><div class="panel-heading">Active warning</div><div class="panel-body"><strong>{{ $metrics['active_warning'] }}</strong></div></div></div>
        @foreach(['pending','acknowledged','suppressed'] as $state)<div class="col-sm-2"><div class="panel panel-default"><div class="panel-heading">{{ ucfirst($state) }}</div><div class="panel-body"><strong>{{ $counts[$state] ?? 0 }}</strong></div></div></div>@endforeach
    </div>
    <div class="row">@foreach(['recovered_24h'=>'Recovered (24h)','failed_deliveries'=>'Failed deliveries (24h)','missing_policies'=>'Interfaces without policy','awaiting_escalation'=>'Awaiting escalation'] as $key=>$label)<div class="col-sm-3"><div class="panel panel-default"><div class="panel-heading">{{ $label }}</div><div class="panel-body"><strong>{{ $metrics[$key] }}</strong></div></div></div>@endforeach</div>
    @if($metrics['dry_run'])<div class="alert alert-warning"><strong>Dry-run is enabled.</strong> External notification delivery is suppressed.</div>@else<div class="alert alert-success"><strong>Live delivery is enabled.</strong></div>@endif
    <div class="panel panel-default"><div class="panel-heading">Recent incidents</div><div class="table-responsive"><table class="table table-hover"><thead><tr><th>ID</th><th>Device</th><th>Port</th><th>State</th><th>Severity</th><th>Last seen</th></tr></thead><tbody>@forelse($incidents as $incident)<tr><td>{{ $incident->id }}</td><td>{{ $incident->device_id }}</td><td>{{ $incident->port_id }}</td><td>{{ $incident->state->value }}</td><td>{{ $incident->severity->value }}</td><td>{{ $incident->last_seen_at }}</td></tr>@empty<tr><td colspan="6">No incidents.</td></tr>@endforelse</tbody></table></div></div>
    {{ $incidents->links() }}
</div>
@endsection
