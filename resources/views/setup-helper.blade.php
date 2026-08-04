@extends('layouts.librenmsv1') @section('title','IAPM LibreNMS Setup') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h2>LibreNMS Alert Setup</h2>
<h3>Recommended rule</h3><textarea class="form-control" rows="4" readonly>{{ $rule }}</textarea><p>This uses current LibreNMS entities and the documented <code>macros.device_up</code> macro. Build it in the rule editor so LibreNMS validates it for your database.</p>
<h3>JSON alert template</h3><textarea class="form-control" rows="35" readonly>{{ $template }}</textarea><p>The template constructs an array and applies Blade's <code>@@json</code> encoder. It never manually quotes values. Recovery state 0 emits an empty fault array.</p>
<h3>API transport</h3><pre>POST {{ url('/plugin/interface-alert-policy-manager/api/v1/alerts') }}
Authorization: Bearer &lt;generated IAPM token&gt;
Content-Type: application/json
Accept: application/json
Body: @{{ $msg }}</pre>
<div class="alert alert-warning">Do not configure SMS credentials in this LibreNMS transport. IAPM owns downstream delivery.</div></div>@endsection
