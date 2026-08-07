@extends('layouts.librenmsv1') @section('title','IAPM Settings') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Settings</h2>

<div class="panel {{ $values['has_token'] ? 'panel-default' : 'panel-warning' }}" id="ingestion-token">
    <div class="panel-heading"><i class="fa fa-key"></i> Ingestion token @unless($values['has_token'])<span class="label label-warning pull-right">Step 0 — start here</span>@endunless</div>
    <div class="panel-body">
        <p class="help-block">The bearer token LibreNMS uses to authenticate to <code>/plugin/interface-alert-policy-manager/api/v1/alerts</code>. Rotating keeps the previous token valid for 15 minutes so you can update the transport without missing alerts.</p>
        <p>Status: @if($values['has_token'])<span class="label label-success">Configured</span>@else<span class="label label-warning">Not generated</span>@endif</p>
        <form method="post" action="{{ route('iapm.settings.rotate-token') }}" onsubmit="return confirm('Rotate ingestion token? The previous token stays valid for 15 minutes.')">@csrf
            <button class="btn btn-warning">{{ $values['has_token']?'Rotate':'Generate' }} ingestion token</button>
        </form>
    </div>
</div>

<form method="post" action="{{ route('iapm.settings.update') }}" id="iapm-settings-form" data-dry-run-was="{{ $values['dry_run']?'1':'0' }}">@csrf @method('PUT')

<div class="panel panel-default">
    <div class="panel-heading">Delivery mode</div>
    <div class="panel-body">
        <input type="hidden" name="dry_run" value="0">
        <div class="checkbox"><label><input type="checkbox" name="dry_run" id="iapm-dry-run" value="1" @checked(old('dry_run',$values['dry_run']))> <strong>Dry-run mode</strong></label></div>
        <p class="help-block">While enabled, IAPM records what it <em>would</em> send but never contacts the gateway. Keep this on during the shadow period, then disable it to go live.</p>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">Policy defaults &amp; receivers</div>
    <div class="panel-body">
        <div class="form-group"><label>Default policy</label>
            <select name="default_policy_id" class="form-control"><option value="">None</option>@foreach($policies as $p)<option value="{{ $p->id }}" @selected($values['default_policy_id']==$p->id)>{{ $p->name }}</option>@endforeach</select>
            <p class="help-block">Used when no assignment matches an interface. A default <em>assignment</em> is usually preferable.</p>
        </div>
        <div class="form-group"><label>Global SMS receiver</label>
            <input class="form-control" name="sms_default_receiver" value="{{ old('sms_default_receiver',$values['sms_default_receiver']) }}">
            <p class="help-block">Last-resort receiver when a policy, assignment, or destination does not specify one.</p>
        </div>
        <div class="form-group"><label>URL base</label>
            <input class="form-control" name="url_base" value="{{ old('url_base',$values['url_base']) }}" placeholder="https://librenms.example.com">
            <p class="help-block">Used to build device/port links inside notification messages.</p>
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">Delivery &amp; retention</div>
    <div class="panel-body">
        <div class="form-group"><label>Notification timeout (seconds)</label><input class="form-control" name="notification_timeout" value="{{ old('notification_timeout',$values['notification_timeout']) }}"><p class="help-block">How long to wait for the gateway before recording a failure.</p></div>
        <div class="form-group"><label>Notification retries</label><input class="form-control" name="notification_retry_count" value="{{ old('notification_retry_count',$values['notification_retry_count']) }}"><p class="help-block">Extra attempts per delivery when the gateway errors (0–10).</p></div>
        <div class="form-group"><label>Retention (days)</label><input class="form-control" name="retention_days" value="{{ old('retention_days',$values['retention_days']) }}"><p class="help-block">Recovered incidents, events, delivery and audit logs older than this are cleaned up daily. Active incidents are never deleted.</p></div>
        <input type="hidden" name="record_unpoliced" value="0">
        <div class="checkbox"><label><input type="checkbox" name="record_unpoliced" value="1" @checked(old('record_unpoliced',$values['record_unpoliced']))> <strong>Record alerts for interfaces with no policy</strong></label>
            <p class="help-block">On (default): un-matched interfaces are stored as suppressed <code>no_policy</code> incidents for visibility. <strong>Turn off on very large fleets</strong> that intentionally scope IAPM to specific interfaces — alerts for everything else are then ignored instead of creating hundreds of thousands of rows.</p>
        </div>
        <div class="form-group"><label>Deleted-port behaviour</label>
            <select class="form-control" name="deleted_port_behavior"><option value="recover" @selected($values['deleted_port_behavior']==='recover')>Recover incident when the port is deleted</option><option value="retain" @selected($values['deleted_port_behavior']==='retain')>Retain the open incident</option></select>
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">Delivery dispatch</div>
    <div class="panel-body">
        <div class="form-group"><label>Dispatch mode</label>
            <select class="form-control" name="dispatch_mode">
                <option value="queue" @selected($values['dispatch_mode']==='queue')>Queued (default) — parallel delivery via queue workers</option>
                <option value="sync" @selected($values['dispatch_mode']==='sync')>Synchronous — send inside the every-minute job</option>
            </select>
            <p class="help-block"><strong>Queued</strong> delivers notifications in parallel, so large simultaneous events drain quickly. Everything needed is set up automatically: the queue tables are created on install, and the LibreNMS scheduler keeps <code>{{ (int) config('iapm.queue.workers', 3) }}</code> worker(s) running (tune with <code>IAPM_QUEUE_WORKERS</code>; point at Redis with <code>IAPM_QUEUE_CONNECTION</code>). The Overview health panel flags it if the queue ever stops draining. <strong>Synchronous</strong> sends inside the every-minute job (bounded by a wall-clock budget) — simpler, no workers, but serial.</p>
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">Storm control — device digest</div>
    <div class="panel-body">
        <div class="form-group"><label>Aggregate threshold</label>
            <input class="form-control" name="aggregate_threshold" value="{{ old('aggregate_threshold',$values['aggregate_threshold']) }}">
            <p class="help-block">When this many interfaces on the <strong>same device</strong> go down together, send one grouped "device down" SMS instead of one per interface. Set <code>0</code> to disable (always notify per interface). Recommended: 3.</p>
        </div>
        <div class="form-group"><label>Aggregation window (seconds)</label>
            <input class="form-control" name="aggregate_window_seconds" value="{{ old('aggregate_window_seconds',$values['aggregate_window_seconds']) }}">
            <p class="help-block">Interfaces that trigger within this window of each other are grouped. Default 120.</p>
        </div>
        <p class="help-block">Customise the digest wording under <a href="{{ route('iapm.message-templates') }}">Message Templates</a>.</p>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">Root-cause suppression</div>
    <div class="panel-body">
        <div class="form-group"><label>Uplink port group</label>
            <select class="form-control" name="uplink_port_group_id"><option value="">None</option>@foreach($portGroups as $g)<option value="{{ $g->id }}" @selected($values['uplink_port_group_id']==$g->id)>{{ $g->name }}</option>@endforeach</select>
            <p class="help-block">Ports in this LibreNMS port group are treated as uplinks. When an uplink on a device is down, policies with "suppress when uplink down" will suppress the downstream customer interfaces on that device, avoiding an alert storm.</p>
        </div>
    </div>
</div>

<button class="btn btn-primary">Save settings</button>
</form>
</div>

<script>
document.getElementById('iapm-settings-form').addEventListener('submit', function (e) {
    var form = e.currentTarget;
    var enablingLive = form.dataset.dryRunWas === '1' && ! document.getElementById('iapm-dry-run').checked;
    if (enablingLive && ! window.confirm('You are disabling dry-run mode. IAPM will begin sending real notifications to the SMS gateway. Continue?')) {
        e.preventDefault();
    }
});
</script>
@endsection
