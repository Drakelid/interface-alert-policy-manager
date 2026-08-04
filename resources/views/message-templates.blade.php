@extends('layouts.librenmsv1') @section('title','IAPM Message Templates') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Message templates</h2>
<p class="text-muted">The default message sent for each event type. A policy action with its own template overrides these. Leave a box blank to use the built-in default (shown as the placeholder). Only <code>@{{ placeholder }}</code> substitutions run — no PHP/Blade — and unknown placeholders are rejected on save.</p>
<p class="text-muted">Placeholders: <code>hostname</code>, <code>sysName</code>, <code>display_name</code>, <code>device_id</code>, <code>port_id</code>, <code>ifName</code>, <code>ifDescr</code>, <code>ifAlias</code>, <code>ifAdminStatus</code>, <code>ifOperStatus</code>, <code>interface_type</code>, <code>location</code>, <code>severity</code>, <code>state</code>, <code>policy_name</code>, <code>assignment_source</code>, <code>first_seen_at</code>, <code>triggered_at</code>, <code>recovered_at</code>, <code>outage_duration</code>, <code>acknowledgement_user</code>, <code>suppression_reason</code>, <code>device_url</code>, <code>port_url</code>, <code>incident_id</code>. Try wording on the <a href="{{ route('iapm.template-preview') }}" target="_blank">Template Preview</a> page.</p>

<form method="post" action="{{ route('iapm.message-templates.update') }}">@csrf @method('PUT')
<div class="row">
@foreach($rows as $phase => $row)
<div class="col-md-6">
    <div class="form-group">
        <label>{{ $row['label'] }}</label>
        <textarea name="templates[{{ $phase }}]" rows="7" class="form-control" style="font-family:monospace;" placeholder="{{ $row['default'] }}">{{ old('templates.'.$phase, $row['custom']) }}</textarea>
        @error($phase)<span class="help-block text-danger">{{ $message }}</span>@enderror
    </div>
</div>
@endforeach
</div>
<button class="btn btn-primary">Save templates</button>
<span class="text-muted" style="margin-left:8px;">Respects the configured SMS length limit; long messages are truncated but keep the incident id.</span>
</form>
</div>@endsection
