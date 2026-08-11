@extends('layouts.librenmsv1')
@section('title', $destination->exists ? 'Edit IAPM Destination' : 'Create IAPM Destination')
@section('content')
@php($iapmType = old('type', $destination->type ?? 'sms_gateway'))
<div class="container-fluid">
    @include('iapm::partials.nav')
    <h1 class="iapm-page-title">{{ $destination->exists ? 'Edit' : 'Create' }} Destination</h1>
    <p class="iapm-hint" style="max-width:70em;">Where notifications are delivered. Secrets are encrypted with the LibreNMS application key and never appear in the delivery log.</p>

    {{-- P2-3: Username, Password and Bearer token used to render for both types
         with the rules explained only in prose. They now follow the same
         conditional-display pattern as the assignment form: only fields valid for
         the selected type are shown, and hidden ones are disabled so a stale
         value cannot be submitted. --}}
    <form method="post" action="{{ $destination->exists ? route('iapm.destinations.update',$destination) : route('iapm.destinations.store') }}" id="iapm-destination-form">
        @csrf
        @if($destination->exists)@method('PUT')@endif
        <div class="row"><div class="col-md-7">

        <div class="form-group">
            <label for="iapm-dest-name">Name</label>
            <input class="form-control" id="iapm-dest-name" name="name" required value="{{ old('name',$destination->name) }}">
        </div>

        <div class="form-group">
            <label for="iapm-dest-type">Type</label>
            <select name="type" id="iapm-dest-type" class="form-control" aria-describedby="iapm-dest-type-help">
                <option value="sms_gateway" @selected($iapmType==='sms_gateway')>SMS gateway</option>
                <option value="generic_webhook" @selected($iapmType==='generic_webhook')>Generic webhook</option>
            </select>
            <p class="iapm-hint" id="iapm-dest-type-help">An <strong>SMS gateway</strong> authenticates with HTTP Basic credentials and sends to a receiver. A <strong>generic webhook</strong> may use Basic auth, a bearer token, or neither.</p>
        </div>

        <div class="form-group">
            <label for="iapm-dest-url">Gateway URL</label>
            <input class="form-control" id="iapm-dest-url" name="url" required value="{{ old('url',$configuration['url']??'') }}" placeholder="http://sms-gateway.internal:5000/api/v10/messages/send" aria-describedby="iapm-dest-url-help">
            <p class="iapm-hint" id="iapm-dest-url-help">http/https only. Credentials in the query string are rejected.</p>
        </div>

        <div class="form-group iapm-dest-field" data-types="sms_gateway,generic_webhook">
            <label for="iapm-dest-username">Username</label>
            <input class="form-control" id="iapm-dest-username" name="username" value="{{ old('username',$configuration['username']??'') }}" aria-describedby="iapm-dest-username-help">
            <p class="iapm-hint" id="iapm-dest-username-help" data-sms-help="The gateway's HTTP Basic username." data-webhook-help="Only if the endpoint uses HTTP Basic auth; leave blank to use a bearer token or no authentication.">The gateway's HTTP Basic username.</p>
        </div>

        <div class="form-group iapm-dest-field" data-types="sms_gateway,generic_webhook">
            <label for="iapm-dest-password">Password</label>
            <input class="form-control" id="iapm-dest-password" type="password" name="password" value="" autocomplete="new-password" @if($destination->exists)placeholder="•••••••• (unchanged)"@endif aria-describedby="iapm-dest-password-help">
            <p class="iapm-hint" id="iapm-dest-password-help">@if($destination->exists)Leave blank to keep the stored password.@else Required for an SMS gateway; optional for a webhook.@endif</p>
        </div>

        <div class="form-group iapm-dest-field" data-types="generic_webhook">
            <label for="iapm-dest-bearer">Bearer token</label>
            <input class="form-control" id="iapm-dest-bearer" type="password" name="bearer_token" value="" autocomplete="new-password" @if($destination->exists)placeholder="•••••••• (unchanged)"@endif aria-describedby="iapm-dest-bearer-help">
            {{-- P2-3: the create form said "Leave blank to keep the stored token",
                 which is edit-form copy on a page where nothing is stored yet. --}}
            <p class="iapm-hint" id="iapm-dest-bearer-help">@if($destination->exists)Leave blank to keep the stored token.@else Optional. Sent as <code>Authorization: Bearer …</code>.@endif</p>
        </div>

        <div class="form-group iapm-dest-field" data-types="sms_gateway">
            <label for="iapm-dest-receiver">Default receiver</label>
            <input class="form-control" id="iapm-dest-receiver" name="default_receiver" value="{{ old('default_receiver',$configuration['default_receiver']??'') }}" aria-describedby="iapm-dest-receiver-help">
            <p class="iapm-hint" id="iapm-dest-receiver-help">Used when no more specific receiver is resolved. Setting this satisfies the readiness check.</p>
        </div>

        <div class="form-group">
            <label for="iapm-dest-mode">Encoding</label>
            <select name="mode" id="iapm-dest-mode" class="form-control">
                <option value="json" @selected(old('mode',$configuration['mode']??'json')==='json')>JSON body (recommended)</option>
                <option value="form" @selected(old('mode',$configuration['mode']??'json')==='form')>Form-encoded</option>
            </select>
        </div>

        <div class="row">
            <div class="col-sm-3 form-group"><label for="iapm-dest-connect">Connect timeout (s)</label><input class="form-control" id="iapm-dest-connect" type="number" min="1" max="300" name="connect_timeout" value="{{ old('connect_timeout',$configuration['connect_timeout']??5) }}"></div>
            <div class="col-sm-3 form-group"><label for="iapm-dest-timeout">Request timeout (s)</label><input class="form-control" id="iapm-dest-timeout" type="number" min="1" max="300" name="timeout" value="{{ old('timeout',$configuration['timeout']??15) }}"></div>
            <div class="col-sm-3 form-group"><label for="iapm-dest-retries">Retry count</label><input class="form-control" id="iapm-dest-retries" type="number" min="0" max="10" name="retry_count" value="{{ old('retry_count',$configuration['retry_count']??2) }}"></div>
            <div class="col-sm-3 form-group"><label for="iapm-dest-retrydelay">Retry delay (ms)</label><input class="form-control" id="iapm-dest-retrydelay" type="number" min="0" max="60000" name="retry_delay_ms" value="{{ old('retry_delay_ms',$configuration['retry_delay_ms']??500) }}"></div>
        </div>

        <div class="form-group">
            <label for="iapm-dest-headers">Custom headers <span class="iapm-hint">(JSON object)</span></label>
            {{-- P2-3: this defaulted to "[]", an array, under a label promising an
                 object. An empty object is what the field actually wants. --}}
            <textarea name="headers_json" id="iapm-dest-headers" class="form-control" rows="2" style="font-family:monospace;" aria-describedby="iapm-dest-headers-help">{{ old('headers_json', json_encode((object) ($configuration['headers'] ?? []), JSON_PRETTY_PRINT)) }}</textarea>
            <p class="iapm-hint" id="iapm-dest-headers-help">An object of header names to values, e.g. <code>{"X-Api-Key": "…"}</code>. <code>Authorization</code> and <code>Host</code> are stripped for safety.</p>
        </div>

        @foreach(['enabled'=>'Enabled','verify_tls'=>'Verify TLS certificate','allow_private_networks'=>'Allow private/internal address (trusted gateway only — disables SSRF protection)'] as $key=>$label)
        <input type="hidden" name="{{ $key }}" value="0">
        <div class="checkbox"><label for="iapm-dest-{{ $key }}"><input type="checkbox" id="iapm-dest-{{ $key }}" name="{{ $key }}" value="1" @checked(old($key,$configuration[$key]??($key!=='allow_private_networks')))> {{ $label }}</label></div>
        @endforeach

        <div class="iapm-form-footer">
            <button class="btn btn-primary"><i class="fa fa-save"></i> Save destination</button>
            <a class="btn btn-default" href="{{ route('iapm.destinations.index') }}">Cancel</a>
        </div>
        </div></div>
    </form>

    @if($destination->exists)
    <div class="panel panel-default" id="test" style="margin-top:20px;max-width:600px;">
        <div class="panel-heading">Send a test notification</div>
        <div class="panel-body">
            <p class="iapm-hint">Sends one clearly-labelled test message and records it in the delivery log. This sends even in dry-run mode, so confirm the receiver.</p>
            <form method="post" action="{{ route('iapm.destinations.test',$destination) }}" data-iapm-busy>@csrf
                <label class="sr-only" for="iapm-dest-test-receiver">Test receiver</label>
                <div class="input-group"><input class="form-control" id="iapm-dest-test-receiver" name="receiver" required placeholder="Test receiver (e.g. your number)"><span class="input-group-btn"><button class="btn btn-warning" data-busy="Sending…"><i class="fa fa-paper-plane"></i> Send test</button></span></div>
            </form>
        </div>
    </div>
    <form method="post" action="{{ route('iapm.destinations.destroy',$destination) }}" data-iapm-confirm="Delete the destination &quot;{{ $destination->name }}&quot;? Its stored credentials are erased and cannot be recovered. Policy actions still using it will stop delivering." style="margin-top:10px;">@csrf @method('DELETE')<button class="btn btn-danger"><i class="fa fa-trash"></i> Delete destination</button></form>
    @endif
</div>

<script>
(function () {
    // Same conditional-display pattern as the assignment form (P2-3): show only
    // the fields valid for the chosen type, and disable the hidden ones so a
    // stale value from the other type is never submitted.
    var form = document.getElementById('iapm-destination-form');
    var typeSelect = document.getElementById('iapm-dest-type');
    if (! form || ! typeSelect) { return; }

    function sync() {
        var type = typeSelect.value;
        form.querySelectorAll('.iapm-dest-field').forEach(function (field) {
            var show = field.dataset.types.split(',').indexOf(type) !== -1;
            field.style.display = show ? '' : 'none';
            field.querySelectorAll('input,select,textarea').forEach(function (input) { input.disabled = ! show; });
            // Help that differs by type without duplicating the whole field.
            var help = field.querySelector('[data-sms-help]');
            if (help && show) { help.textContent = type === 'sms_gateway' ? help.dataset.smsHelp : help.dataset.webhookHelp; }
        });
    }

    typeSelect.addEventListener('change', sync);
    sync();
})();
</script>
@endsection
