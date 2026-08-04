@extends('layouts.librenmsv1') @section('title','IAPM Destination') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>{{ $destination->exists?'Edit':'Create' }} Destination</h2>
<p class="text-muted">Where notifications are delivered. Secrets are encrypted with the application key. For the SMS gateway, use JSON encoding and HTTP Basic auth.</p>

<form method="post" action="{{ $destination->exists?route('iapm.destinations.update',$destination):route('iapm.destinations.store') }}">@csrf @if($destination->exists)@method('PUT')@endif
<div class="row"><div class="col-md-7">

<div class="form-group"><label>Name</label><input class="form-control" name="name" required value="{{ old('name',$destination->name) }}"></div>

<div class="form-group"><label>Type</label>
    <select name="type" class="form-control"><option value="sms_gateway" @selected(old('type',$destination->type ?? 'sms_gateway')==='sms_gateway')>SMS gateway</option><option value="generic_webhook" @selected(old('type',$destination->type)==='generic_webhook')>Generic webhook</option></select>
    <p class="help-block">SMS gateway requires a password on create; a webhook may use Basic auth, a bearer token, or none.</p>
</div>

<div class="form-group"><label>Gateway URL</label><input class="form-control" name="url" required value="{{ old('url',$configuration['url']??'') }}" placeholder="http://sms-gateway.internal:5000/api/v10/messages/send"><p class="help-block">http/https only. Credentials in the query string are rejected.</p></div>

<div class="form-group"><label>Username</label><input class="form-control" name="username" value="{{ old('username',$configuration['username']??'') }}"></div>
<div class="form-group"><label>Password</label><input class="form-control" type="password" name="password" value="" @if($destination->exists)placeholder="•••••••• (unchanged)"@endif><p class="help-block">@if($destination->exists)Leave blank to keep the stored password.@else Required for an SMS gateway.@endif</p></div>
<div class="form-group"><label>Bearer token</label><input class="form-control" type="password" name="bearer_token" value="" @if($destination->exists)placeholder="•••••••• (unchanged)"@endif><p class="help-block">Optional, webhook only. Leave blank to keep the stored token.</p></div>

<div class="form-group"><label>Default receiver</label><input class="form-control" name="default_receiver" value="{{ old('default_receiver',$configuration['default_receiver']??'') }}"><p class="help-block">Used when no more specific receiver is resolved. Setting this satisfies the readiness check.</p></div>

<div class="form-group"><label>Encoding</label><select name="mode" class="form-control"><option value="json" @selected(old('mode',$configuration['mode']??'json')==='json')>JSON body (recommended)</option><option value="form" @selected(old('mode',$configuration['mode']??'json')==='form')>Form-encoded</option></select></div>

<div class="row">
    <div class="col-sm-3 form-group"><label>Connect timeout (s)</label><input class="form-control" name="connect_timeout" value="{{ old('connect_timeout',$configuration['connect_timeout']??5) }}"></div>
    <div class="col-sm-3 form-group"><label>Request timeout (s)</label><input class="form-control" name="timeout" value="{{ old('timeout',$configuration['timeout']??15) }}"></div>
    <div class="col-sm-3 form-group"><label>Retry count</label><input class="form-control" name="retry_count" value="{{ old('retry_count',$configuration['retry_count']??2) }}"></div>
    <div class="col-sm-3 form-group"><label>Retry delay (ms)</label><input class="form-control" name="retry_delay_ms" value="{{ old('retry_delay_ms',$configuration['retry_delay_ms']??500) }}"></div>
</div>

<div class="form-group"><label>Custom headers (JSON object)</label><textarea name="headers_json" class="form-control" rows="2">{{ old('headers_json',json_encode($configuration['headers']??[],JSON_PRETTY_PRINT)) }}</textarea><p class="help-block"><code>Authorization</code> and <code>Host</code> are stripped for safety.</p></div>

@foreach(['enabled'=>'Enabled','verify_tls'=>'Verify TLS certificate','allow_private_networks'=>'Allow private/internal address (trusted gateway only — disables SSRF protection)'] as $key=>$label)
<input type="hidden" name="{{ $key }}" value="0"><div class="checkbox"><label><input type="checkbox" name="{{ $key }}" value="1" @checked(old($key,$configuration[$key]??($key!=='allow_private_networks')))> {{ $label }}</label></div>
@endforeach

<button class="btn btn-primary">Save</button>
</div></div>
</form>

@if($destination->exists)
<div class="panel panel-default" id="test" style="margin-top:20px;max-width:600px;">
    <div class="panel-heading">Send a test notification</div>
    <div class="panel-body">
        <p class="help-block">Sends one clearly-labelled test message and records it in the delivery log. This sends even in dry-run mode, so confirm the receiver.</p>
        <form method="post" action="{{ route('iapm.destinations.test',$destination) }}">@csrf
            <div class="input-group"><input class="form-control" name="receiver" required placeholder="Test receiver (e.g. your number)"><span class="input-group-btn"><button class="btn btn-warning"><i class="fa fa-paper-plane"></i> Send test</button></span></div>
        </form>
    </div>
</div>
<form method="post" action="{{ route('iapm.destinations.destroy',$destination) }}" onsubmit="return confirm('Delete this destination? This cannot be undone.')" style="margin-top:10px;">@csrf @method('DELETE')<button class="btn btn-danger">Delete destination</button></form>
@endif
</div>@endsection
