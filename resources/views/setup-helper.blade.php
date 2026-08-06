@extends('layouts.librenmsv1') @section('title','IAPM LibreNMS Setup') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h2>LibreNMS Alert Setup</h2>
<p class="text-muted">Configure LibreNMS to post interface alerts to IAPM. Do the three steps below in the LibreNMS alerting UI, then confirm it's working at the bottom.</p>

@if(! $hasToken)
<div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> No ingestion token yet — <a href="{{ route('iapm.settings.edit') }}#ingestion-token">generate one first</a>; the transport below needs it.</div>
@endif

<div class="panel panel-default">
    <div class="panel-heading"><strong>Step 1 — Alert rule</strong> <button type="button" class="btn btn-default btn-xs pull-right" data-copy="#iapm-rule"><i class="fa fa-copy"></i> Copy</button></div>
    <div class="panel-body">
        <textarea id="iapm-rule" class="form-control" rows="4" readonly>{{ $rule }}</textarea>
        <p class="help-block">Build this in the rule editor so LibreNMS validates it against your database. It uses the documented <code>macros.device_up</code> macro and only fires for an admin-up / oper-down interface that isn't ignored, disabled, or deleted.</p>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><strong>Step 2 — Alert template</strong> <button type="button" class="btn btn-default btn-xs pull-right" data-copy="#iapm-template"><i class="fa fa-copy"></i> Copy</button></div>
    <div class="panel-body">
        <textarea id="iapm-template" class="form-control" rows="20" readonly>{{ $template }}</textarea>
        <p class="help-block">Paste as the rule's alert template. It builds an array and applies Blade's <code>@@json</code> encoder (no manual quoting); recovery state 0 emits an empty fault array.</p>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><strong>Step 3 — API transport</strong> <button type="button" class="btn btn-default btn-xs pull-right" data-copy="#iapm-transport"><i class="fa fa-copy"></i> Copy body</button></div>
    <div class="panel-body">
        <p>Create an <strong>API</strong> transport (POST, "send as form" OFF), route the rule to it, and set:</p>
        <pre>POST {{ $endpoint }}
Authorization: Bearer &lt;your IAPM ingestion token&gt;
Content-Type: application/json
Accept: application/json</pre>
        <p>Body:</p>
        <textarea id="iapm-transport" class="form-control" rows="1" readonly>@{{ $msg }}</textarea>
        <div class="alert alert-warning" style="margin-top:10px;"><i class="fa fa-shield"></i> Do <strong>not</strong> put SMS credentials in this transport — IAPM owns downstream delivery. This transport only forwards the alert JSON.</div>
    </div>
</div>

<div class="panel {{ $lastAlertAt ? 'panel-success' : 'panel-default' }}">
    <div class="panel-heading"><strong>Confirm it's working</strong></div>
    <div class="panel-body">
        @if($lastAlertAt)
            <p><i class="fa fa-check-circle text-success"></i> Last alert received <strong>@include('iapm::partials.time',['at'=>\Carbon\Carbon::parse($lastAlertAt)])</strong> — LibreNMS is posting to IAPM.</p>
        @else
            <p><i class="fa fa-clock-o text-muted"></i> <strong>No alert received yet.</strong> After saving the rule and transport, trigger a real interface down (or use <a href="{{ route('iapm.simulate') }}">Simulate Alert</a> to exercise the pipeline). This box turns green once LibreNMS posts here.</p>
        @endif
        <p class="help-block">Also watch <code>storage/logs/iapm.log</code> for <code>Alert ingestion completed</code> lines.</p>
    </div>
</div>
</div>

<script>
document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var el = document.querySelector(btn.dataset.copy);
        if (!el) return;
        el.select && el.select();
        navigator.clipboard.writeText(el.value !== undefined ? el.value : el.textContent).then(function () {
            var original = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-check"></i> Copied';
            setTimeout(function () { btn.innerHTML = original; }, 1500);
        });
    });
});
</script>
@endsection
