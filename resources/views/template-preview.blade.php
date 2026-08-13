@extends('layouts.librenmsv1') @section('title','IAPM Template Preview') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h1 class="iapm-page-title">Safe Template Preview</h1>
<p class="iapm-hint iapm-wrap-code" style="max-width:70em;">Render a message template against one real interface to see exactly what would be sent. Nothing is delivered. Insert values with <code>@{{ placeholder }}</code>, or conditionally include text with <code>@{{#if ifAlias}}...@{{else}}...@{{/if}}</code> and comparisons such as <code>@{{#if severity == "critical"}}...@{{/if}}</code>. No PHP or Blade runs; invalid syntax and unknown placeholders are reported.</p>
<form method="post">@csrf
    @include('iapm::partials.port-picker',['id'=>'iapm-tp','value'=>old('port_id', request('port_id')),'valueLabel'=>$portLabel,'required'=>true])
    <div class="form-group"><label for="iapm-tp-template">Template</label><textarea required name="template" id="iapm-tp-template" rows="14" class="form-control" data-iapm-sms-counter>{{ old('template',$defaultTemplate) }}</textarea></div>
    <button class="btn btn-primary">Preview without sending</button>
</form>
@if($warning)<div class="alert {{ $rendered === null ? 'alert-danger' : 'alert-info' }}">{{ $warning }}</div>@endif
@if($rendered!==null)<h2>Rendered SMS</h2><pre>{{ $rendered }}</pre>@endif</div>@endsection
