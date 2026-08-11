@extends('layouts.librenmsv1') @section('title','IAPM Settings') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h1 class="iapm-page-title">Settings</h1>

{{-- P2-8: this was one long single-column page with a single Save at the very
     bottom, and the "0. Generate ingestion token" menu item dropped you at the
     top of it. Sections now have anchors, a jump list, and the Save bar sticks
     to the bottom of the viewport so it is reachable from any section. --}}
<nav class="iapm-section-nav" aria-label="Settings sections">
    <span class="iapm-hint">Jump to:</span>
    <a href="#ingestion-token">Ingestion token</a>
    <a href="#delivery-mode">Delivery mode</a>
    <a href="#policy-defaults">Policy defaults</a>
    <a href="#delivery-retention">Delivery &amp; retention</a>
    <a href="#delivery-dispatch">Dispatch</a>
    <a href="#storm-control">Storm control</a>
    <a href="#root-cause">Root-cause suppression</a>
</nav>

<div class="panel {{ $values['has_token'] ? 'panel-default' : 'panel-warning' }}" id="ingestion-token">
    <div class="panel-heading"><i class="fa fa-key"></i> Ingestion token @unless($values['has_token'])<span class="label label-warning pull-right">Step 0 — start here</span>@endunless</div>
    <div class="panel-body">
        <p class="iapm-hint">The bearer token LibreNMS uses to authenticate to <code>/plugin/interface-alert-policy-manager/api/v1/alerts</code>. Rotating keeps the previous token valid for 15 minutes so you can update the transport without missing alerts.</p>
        <p>Status: @if($values['has_token'])<span class="label label-success">Configured</span>@else<span class="label label-warning">Not generated</span>@endif</p>

        @if($values['has_token'])
        {{-- P0-5: the token used to be write-only -- the Setup Helper asked for it
             but the only way to obtain one was to rotate, breaking a live install.
             The value is fetched from settings/ingestion-token on demand rather
             than rendered here, because this page only needs `view iapm` while the
             token is a `manage iapm settings` secret. --}}
        <div class="form-group">
            <label for="iapm-token-value">Current token</label>
            <div class="input-group" style="max-width:640px;">
                <input type="text" readonly class="form-control" id="iapm-token-value" style="font-family:monospace;"
                       value="••••••••••••••••" data-iapm-token-template="__TOKEN__" aria-describedby="iapm-token-help">
                <span class="input-group-btn">
                    <button type="button" class="btn btn-default" data-iapm-reveal-token="{{ route('iapm.settings.reveal-token') }}" data-iapm-token-mask="••••••••••••••••"><i class="fa fa-eye"></i> Reveal</button>
                    <button type="button" class="btn btn-default" data-copy="#iapm-token-value"><i class="fa fa-copy"></i> Copy</button>
                </span>
            </div>
            <p class="iapm-hint" id="iapm-token-help">Reveal before copying &mdash; copying while masked copies the dots. Every reveal is recorded in the audit log. Managing settings is required; viewers cannot read it.</p>
        </div>
        @endif

        <form method="post" action="{{ route('iapm.settings.rotate-token') }}" onsubmit="return confirm('Rotate ingestion token? The previous token stays valid for 15 minutes.')">@csrf
            <button class="btn btn-warning">{{ $values['has_token']?'Rotate':'Generate' }} ingestion token</button>
            @if($values['has_token'])<span class="iapm-hint" style="margin-left:8px;">Only needed if the token may have leaked &mdash; use Reveal above to read the current one.</span>@endif
        </form>
    </div>
</div>

<form method="post" action="{{ route('iapm.settings.update') }}" id="iapm-settings-form" data-dry-run-was="{{ $values['dry_run']?'1':'0' }}">@csrf @method('PUT')

<div class="panel panel-default" id="delivery-mode">
    <div class="panel-heading">Delivery mode</div>
    <div class="panel-body">
        <input type="hidden" name="dry_run" value="0">
        <div class="checkbox"><label for="iapm-dry-run"><input type="checkbox" name="dry_run" id="iapm-dry-run" value="1" @checked(old('dry_run',$values['dry_run']))> <strong>Dry-run mode</strong></label></div>
        <p class="iapm-hint">While enabled, IAPM records what it <em>would</em> send but never contacts the gateway. Keep this on during the shadow period, then disable it to go live.</p>
        {{-- P2-9: unticking this is the moment the plugin starts paging real
             people, and it was a plain checkbox on a long page. The warning
             appears the moment the box is cleared, before Save is even pressed,
             and the confirmation names the consequence rather than asking a
             generic "are you sure". --}}
        <div class="alert alert-danger" id="iapm-going-live" style="display:none;margin-bottom:0;">
            <i class="fa fa-bolt"></i> <strong>This will start sending real notifications.</strong>
            On save, IAPM begins delivering to your gateway for every incident that matches a policy &mdash; including any that are already open. Re-tick the box to stay in dry-run.
        </div>
        @if(! $values['dry_run'])
        <p class="iapm-hint"><span class="label label-success"><i class="fa fa-bolt"></i> LIVE</span> Notifications are currently being delivered.</p>
        @endif
    </div>
</div>

<div class="panel panel-default" id="policy-defaults">
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

<div class="panel panel-default" id="delivery-retention">
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

<div class="panel panel-default" id="delivery-dispatch">
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

<div class="panel panel-default" id="storm-control">
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

<div class="panel panel-default" id="root-cause">
    <div class="panel-heading">Root-cause suppression</div>
    <div class="panel-body">
        <div class="form-group"><label>Uplink port group</label>
            <select class="form-control" name="uplink_port_group_id"><option value="">None</option>@foreach($portGroups as $g)<option value="{{ $g->id }}" @selected($values['uplink_port_group_id']==$g->id)>{{ $g->name }}</option>@endforeach</select>
            <p class="help-block">Ports in this LibreNMS port group are treated as uplinks. When an uplink on a device is down, policies with "suppress when uplink down" will suppress the downstream customer interfaces on that device, avoiding an alert storm.</p>
        </div>
    </div>
</div>

{{-- P2-8: one Save at the very bottom of a long page meant scrolling back for
     every change. It now sticks to the bottom of the viewport. --}}
<div class="iapm-sticky-save">
    <button class="btn btn-primary"><i class="fa fa-save"></i> Save settings</button>
    <span class="iapm-hint">Saves every section on this page.</span>
</div>
</form>
</div>

<script>
(function () {
    var form = document.getElementById('iapm-settings-form');
    var dryRun = document.getElementById('iapm-dry-run');
    var warning = document.getElementById('iapm-going-live');
    if (! form || ! dryRun) { return; }

    function goingLive() { return form.dataset.dryRunWas === '1' && ! dryRun.checked; }

    // Warn while the operator is still on the page, not only at submit (P2-9).
    dryRun.addEventListener('change', function () {
        warning.style.display = goingLive() ? '' : 'none';
    });

    form.addEventListener('submit', function (e) {
        if (! goingLive()) { return; }
        var typed = window.prompt('Turning off dry-run makes IAPM send real notifications to your gateway for every matching incident, including ones already open.\n\nType GO LIVE to confirm.');
        if ((typed || '').trim().toUpperCase() !== 'GO LIVE') {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);
})();
</script>
@endsection
