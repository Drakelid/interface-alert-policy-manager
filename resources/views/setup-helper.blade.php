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
        {{-- data-literal-blade marks copy-paste content that is Blade source by design:
             this is the template the operator pastes into LibreNMS, not markup we compile.
             RouteSmokeTest strips these blocks before asserting no directive leaked. --}}
        <textarea id="iapm-template" class="form-control" rows="20" readonly data-literal-blade="1">{{ $template }}</textarea>
        <p class="help-block">Paste as the rule's alert template. It builds an array and applies Blade's <code>@@json</code> encoder (no manual quoting); recovery state 0 emits an empty fault array.</p>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><strong>Step 3 — API transport</strong> <button type="button" class="btn btn-default btn-xs pull-right" data-copy="#iapm-transport"><i class="fa fa-copy"></i> Copy body</button></div>
    <div class="panel-body">
        <p>Create an <strong>API</strong> transport (POST, "send as form" OFF), route the rule to it, and set:</p>
        @php($iapmHeaderBlock = "POST $endpoint\nAuthorization: Bearer __TOKEN__\nContent-Type: application/json\nAccept: application/json")
        {{-- P0-5: this block used to read "<your IAPM ingestion token>" with no way
             to obtain the value. Reveal fetches it from the settings endpoint so
             the block becomes paste-ready without ever putting the secret in the
             page source of a view that only requires `view iapm`. --}}
        <div class="iapm-toolbar" style="margin-bottom:6px;">
            @if($hasToken)
                <button type="button" class="btn btn-default btn-xs" data-iapm-reveal-token="{{ route('iapm.settings.reveal-token') }}" data-iapm-token-mask="&lt;your IAPM ingestion token&gt;"><i class="fa fa-eye"></i> Reveal</button>
                <button type="button" class="btn btn-default btn-xs" data-copy="#iapm-transport-headers"><i class="fa fa-copy"></i> Copy headers</button>
                <span class="iapm-hint">Reveal inserts the live token into the block below. Requires permission to manage IAPM settings.</span>
            @else
                <span class="iapm-hint">No token yet &mdash; <a href="{{ route('iapm.settings.edit') }}#ingestion-token">generate one</a> and this block becomes paste-ready.</span>
            @endif
        </div>
        <textarea id="iapm-transport-headers" class="form-control" rows="4" readonly style="font-family:monospace;"
                  data-iapm-token-template="{{ $iapmHeaderBlock }}">{{ str_replace('__TOKEN__', '<your IAPM ingestion token>', $iapmHeaderBlock) }}</textarea>
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

{{-- The data-copy handler now lives in partials/assets so the Settings page
     shares it; this page no longer ships its own copy. --}}
@endsection
