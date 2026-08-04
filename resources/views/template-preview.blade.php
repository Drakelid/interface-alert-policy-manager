@extends('layouts.librenmsv1') @section('title','IAPM Template Preview') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h2>Safe Template Preview</h2>
<form method="post">@csrf<div class="form-group"><label>LibreNMS port_id</label><input type="number" required name="port_id" class="form-control" value="{{ old('port_id') }}"></div><div class="form-group"><label>Template</label><textarea required name="template" rows="14" class="form-control">{{ old('template',$defaultTemplate) }}</textarea></div><button class="btn btn-primary">Preview without sending</button></form>
@if($warning)<div class="alert alert-warning">{{ $warning }}</div>@endif
@if($rendered!==null)<h3>Rendered SMS</h3><pre>{{ $rendered }}</pre>@endif</div>@endsection
