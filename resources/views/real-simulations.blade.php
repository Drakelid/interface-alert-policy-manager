@extends('layouts.librenmsv1') @section('title','IAPM Real Simulations') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h1 class="iapm-page-title">Real simulation</h1>

<div class="alert alert-danger" style="max-width:85em;">
    <i class="fa fa-exclamation-triangle"></i>
    <strong>This sends real notifications.</strong> The selected interface is temporarily recorded as operationally down in LibreNMS. IAPM creates a real incident, evaluates its real policy and actions, and dispatches through the configured queue and gateway. Use only an isolated test interface.
</div>

<div class="row">
    <div class="col-md-7">
        <div class="panel panel-danger">
            <div class="panel-heading"><strong><i class="fa fa-flask"></i> Start end-to-end test</strong></div>
            <div class="panel-body">
                <form method="post" action="{{ route('iapm.real-simulations.store') }}" data-iapm-busy>@csrf
                    @include('iapm::partials.port-picker',['id'=>'iapm-real-sim','value'=>old('port_id', request('port_id')),'valueLabel'=>$portLabel,'required'=>true])
                    <p class="iapm-hint">Safety checks require the port to be admin up, oper up, enabled, not ignored, covered by an enabled policy, and free of another open IAPM incident.</p>

                    <div class="form-group iapm-narrow-field">
                        <label for="iapm-real-duration">Keep simulated down for</label>
                        <div class="input-group"><input type="number" min="60" max="86400" step="60" name="duration_seconds" id="iapm-real-duration" class="form-control" value="{{ old('duration_seconds',600) }}" required><span class="input-group-addon">seconds</span></div>
                        <p class="iapm-hint">Choose enough time for the policy's trigger delay, required observations, and action delay. The scheduler restores the exact original state at the deadline.</p>
                    </div>

                    <div class="form-group iapm-narrow-field">
                        <label for="iapm-real-confirmation">Type <code>SEND REAL ALERTS</code></label>
                        <input type="text" class="form-control" id="iapm-real-confirmation" name="confirmation" autocomplete="off" required pattern="SEND REAL ALERTS">
                    </div>
                    <button class="btn btn-danger" data-busy="Starting real simulation…"><i class="fa fa-bolt"></i> Start real simulation</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="panel panel-default">
            <div class="panel-heading"><strong>Delivery readiness</strong></div>
            <div class="panel-body">
                <dl class="dl-horizontal" style="margin-bottom:0;">
                    <dt>Delivery</dt><dd>@if($dryRun)<span class="label label-warning">DRY-RUN</span> No SMS will leave the server.@else<span class="label label-success">LIVE</span> Gateway calls are enabled.@endif</dd>
                    <dt>Dispatch</dt><dd>{{ $dispatchMode === 'queue' ? 'Queued' : 'Synchronous' }}</dd>
                    @if($dispatchMode === 'queue')<dt>Queue worker</dt><dd><span class="label label-{{ $queueHealth['ok'] ? 'success' : 'danger' }}">{{ $queueHealth['ok'] ? 'READY' : 'NOT READY' }}</span> {{ $queueHealth['detail'] }}</dd>@endif
                    <dt>Auto-recovery</dt><dd><span class="label label-{{ $simulationRecoveryReady ? 'success' : 'danger' }}">{{ $simulationRecoveryReady ? 'READY' : 'NOT READY' }}</span> {{ $simulationRecoveryDetail }}</dd>
                </dl>
                @if($dryRun)<p style="margin-top:12px;"><a class="btn btn-warning btn-sm" href="{{ route('iapm.settings.edit') }}#delivery-mode">Turn off dry-run for an external-delivery test</a></p>@endif
            </div>
        </div>
        <div class="alert alert-info">
            <strong>What this proves:</strong> policy matching, suppression, incident state, action timing, receiver resolution, queue dispatch, gateway response, and recovery. It does not shut the physical switch port down or test SNMP polling and the LibreNMS alert transport; use an actual spare switch port for those two layers.
        </div>
    </div>
</div>

<div class="iapm-result-bar"><h2 style="font-size:20px;margin:0;">Recent simulations</h2><span style="margin-left:auto;">@include('iapm::partials.auto-refresh')</span></div>
<div class="iapm-table-wrap">
<table class="table table-striped table-condensed">
    <thead><tr><th>ID</th><th>Interface</th><th>Status</th><th>IAPM incident</th><th>Delivery</th><th>Started</th><th>Automatic recovery</th><th>Action</th></tr></thead>
    <tbody>
    @forelse($simulations as $simulation)
        @php($incident = $simulation->incident)
        @php($latestDelivery = $incident?->deliveries?->where('episode_uuid',$simulation->episode_uuid)->sortByDesc('id')->first())
        <tr>
            <td><code>#{{ $simulation->id }}</code></td>
            <td>{{ $simulation->port?->device?->hostname ?? 'device '.$simulation->device_id }} — {{ $simulation->port?->ifName ?? 'port '.$simulation->port_id }} <span class="iapm-hint">({{ $simulation->port_id }})</span></td>
            <td><span class="label label-{{ $simulation->status === 'running' ? 'danger' : ($simulation->status === 'recovered' ? 'success' : ($simulation->status === 'failed' ? 'warning' : 'default')) }}">{{ strtoupper($simulation->status) }}</span>@if($simulation->last_error)<br><span class="text-danger">{{ $simulation->last_error }}</span>@endif</td>
            <td>@if($incident)<a href="{{ route('iapm.incidents.show',$incident) }}">#{{ $incident->id }}</a> — {{ $incident->state->value }}@else<span class="iapm-hint">Not created</span>@endif</td>
            <td>@if($latestDelivery)<span class="label label-{{ in_array($latestDelivery->status,['sent','dry_run']) ? 'success' : 'danger' }}">{{ $latestDelivery->phase }}: {{ $latestDelivery->status }}</span>@else<span class="iapm-hint">Waiting for an action</span>@endif</td>
            <td>{{ $simulation->started_at?->format('Y-m-d H:i:s') }}</td>
            <td>@if($simulation->status === 'running')<time data-iapm-countdown="{{ $simulation->recover_at?->toIso8601String() }}">{{ $simulation->recover_at?->diffForHumans() }}</time>@else{{ $simulation->recovered_at?->format('Y-m-d H:i:s') ?? '—' }}@endif</td>
            <td>@if(in_array($simulation->status,['starting','running','recovering','failed']))<form method="post" action="{{ route('iapm.real-simulations.recover',$simulation) }}" data-iapm-busy data-iapm-confirm="Restore this port and send recovery now?">@csrf<button class="btn btn-success btn-xs" data-busy="Recovering…"><i class="fa fa-check"></i> Recover now</button></form>@else—@endif</td>
        </tr>
    @empty
        <tr><td colspan="8" class="iapm-hint">No real simulations have been run.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
</div>
<script>
(function () {
    function updateCountdowns() {
        document.querySelectorAll('[data-iapm-countdown]').forEach(function (el) {
            var seconds = Math.max(0, Math.ceil((new Date(el.dataset.iapmCountdown).getTime() - Date.now()) / 1000));
            el.textContent = seconds ? Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's' : 'due now';
        });
    }
    updateCountdowns(); setInterval(updateCountdowns, 1000);
})();
</script>
@endsection
