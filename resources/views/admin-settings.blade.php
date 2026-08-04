@extends('layouts.librenmsv1') @section('title','IAPM Settings') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h2>Settings</h2>
@if(session('new_ingestion_token'))<div class="alert alert-warning"><strong>Copy this token now:</strong><pre>{{ session('new_ingestion_token') }}</pre></div>@endif
<form method="post" action="{{ route('iapm.settings.update') }}" id="iapm-settings-form" data-dry-run-was="{{ $values['dry_run']?'1':'0' }}">@csrf @method('PUT')<input type="hidden" name="dry_run" value="0"><div class="checkbox"><label><input type="checkbox" name="dry_run" id="iapm-dry-run" value="1" @checked(old('dry_run',$values['dry_run']))> Dry-run mode <span class="help-block" style="display:inline">While enabled, IAPM records what it would send but never contacts the gateway.</span></label></div>
<div class="form-group"><label>Default policy</label><select name="default_policy_id" class="form-control"><option value="">None</option>@foreach($policies as $p)<option value="{{ $p->id }}" @selected($values['default_policy_id']==$p->id)>{{ $p->name }}</option>@endforeach</select></div>
@foreach(['sms_default_receiver'=>'Global SMS receiver','retention_days'=>'Retention days','notification_timeout'=>'Notification timeout','notification_retry_count'=>'Notification retries','url_base'=>'URL base'] as $key=>$label)<div class="form-group"><label>{{ $label }}</label><input class="form-control" name="{{ $key }}" value="{{ old($key,$values[$key]) }}"></div>@endforeach
<div class="form-group"><label>Deleted port behavior</label><select class="form-control" name="deleted_port_behavior"><option value="recover" @selected($values['deleted_port_behavior']==='recover')>Recover incident</option><option value="retain" @selected($values['deleted_port_behavior']==='retain')>Retain open incident</option></select></div>
<button class="btn btn-primary">Save</button></form><hr><form method="post" action="{{ route('iapm.settings.rotate-token') }}" onsubmit="return confirm('Rotate ingestion token?')">@csrf<button class="btn btn-warning">{{ $values['has_token']?'Rotate':'Generate' }} ingestion token</button></form></div>
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
