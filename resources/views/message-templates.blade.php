@extends('layouts.librenmsv1') @section('title','IAPM Message Templates') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h1 class="iapm-page-title">Message templates</h1>
<p class="iapm-hint">The default message sent for each event type. A policy action with its own template overrides these. Leave a box blank to use the built-in default (shown as the placeholder). Only <code>@{{ placeholder }}</code> substitutions run — no PHP/Blade — and unknown placeholders are rejected on save.</p>
<p class="iapm-hint">Placeholders: <code>hostname</code>, <code>sysName</code>, <code>display_name</code>, <code>device_id</code>, <code>port_id</code>, <code>ifName</code>, <code>ifDescr</code>, <code>ifAlias</code>, <code>ifAdminStatus</code>, <code>ifOperStatus</code>, <code>interface_type</code>, <code>location</code>, <code>severity</code>, <code>state</code>, <code>policy_name</code>, <code>assignment_source</code>, <code>first_seen_at</code>, <code>triggered_at</code>, <code>recovered_at</code>, <code>outage_duration</code>, <code>acknowledgement_user</code>, <code>suppression_reason</code>, <code>device_url</code>, <code>port_url</code>, <code>incident_id</code>. Try wording on the <a href="{{ route('iapm.template-preview') }}" target="_blank">Template Preview</a> page.</p>

<form method="post" action="{{ route('iapm.message-templates.update') }}">@csrf @method('PUT')
<div class="row">
@foreach($rows as $phase => $row)
<div class="col-md-6">
    <div class="form-group">
        <label for="iapm-tpl-{{ $phase }}">{{ $row['label'] }}</label>
        <textarea name="templates[{{ $phase }}]" id="iapm-tpl-{{ $phase }}" rows="7" data-iapm-sms-counter class="form-control" style="font-family:monospace;" placeholder="{{ $row['default'] }}">{{ old('templates.'.$phase, $row['custom']) }}</textarea>
        @error($phase)<span class="help-block text-danger">{{ $message }}</span>@enderror
    </div>
</div>
@endforeach
</div>
<hr>
<h2>Device digest</h2>
<p class="iapm-hint">Sent once when many interfaces on the same device go down together (enable it under Settings → Storm control). It uses a <strong>device-level</strong> placeholder set: <code>@{{ hostname }}</code>, <code>@{{ device_id }}</code>, <code>@{{ interface_count }}</code>, <code>@{{ interfaces }}</code> (comma list, truncated), <code>@{{ severity }}</code>, <code>@{{ first_seen_at }}</code>, <code>@{{ device_url }}</code>.</p>
<div class="form-group">
    <label class="sr-only" for="iapm-tpl-digest">Device digest template</label><textarea name="digest" id="iapm-tpl-digest" rows="6" data-iapm-sms-counter class="form-control" style="font-family:monospace;max-width:640px;" placeholder="{{ $digest['default'] }}">{{ old('digest', $digest['custom']) }}</textarea>
    @error('digest')<span class="help-block text-danger">{{ $message }}</span>@enderror
</div>

<button class="btn btn-primary">Save templates</button>
<span class="iapm-hint" style="margin-left:8px;">Respects the configured SMS length limit; long messages are truncated but keep the incident id.</span>
</form>
</div>@endsection
