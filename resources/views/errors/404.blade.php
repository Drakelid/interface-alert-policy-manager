@extends('layouts.librenmsv1')
@section('title','IAPM Page Not Found')
@section('content')
<div class="container-fluid">
    @include('iapm::partials.nav')
    <h1 class="iapm-page-title">Page not found</h1>
    <div class="panel panel-warning">
        <div class="panel-heading"><i class="fa fa-question-circle"></i> <strong>No IAPM page lives at this address</strong></div>
        <div class="panel-body">
            @if($missingResource)
                <p>The {{ $missingResource }} referenced by <code>{{ $requestedPath }}</code> no longer exists. It may have been deleted since the link was created.</p>
            @else
                <p>The plugin has no page at <code>{{ $requestedPath }}</code>.</p>
            @endif
            <p class="iapm-hint">Use the navigation above, or jump straight to a common destination:</p>
            <p>
                <a class="btn btn-primary" href="{{ route('iapm.overview') }}"><i class="fa fa-dashboard"></i> Overview</a>
                <a class="btn btn-default" href="{{ route('iapm.incidents.index') }}">Incidents</a>
                <a class="btn btn-default" href="{{ route('iapm.matrix') }}">Interface Matrix</a>
                <a class="btn btn-default" href="{{ route('iapm.stats') }}">Statistics &amp; SLA</a>
                <a class="btn btn-default" href="{{ route('iapm.settings.edit') }}">Settings</a>
            </p>
        </div>
    </div>
</div>
@endsection
