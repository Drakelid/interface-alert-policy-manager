@extends('layouts.librenmsv1') @section('title','IAPM Import/Export') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Import / Export configuration</h2>
<p class="text-muted">Export schedules, policies, actions, and assignments as JSON for backup or promotion between installs. Destinations are not exported (they hold environment-specific secrets); actions reference their destination by name and are matched on import. Import is create-only — existing items (matched by name) are skipped.</p>

<a class="btn btn-primary" href="{{ route('iapm.export') }}"><i class="fa fa-download"></i> Export configuration</a>

<hr>
<h3>Import</h3>
@isset($report)
<div class="alert alert-success">
    Imported: {{ $report['schedules'] }} schedule(s), {{ $report['policies'] }} policy(ies), {{ $report['actions'] }} action(s), {{ $report['assignments'] }} assignment(s).
    @if(!empty($report['skipped']))<br><strong>Skipped:</strong><ul style="margin-bottom:0;">@foreach($report['skipped'] as $s)<li>{{ $s }}</li>@endforeach</ul>@endif
</div>
@endisset
<form method="post" action="{{ route('iapm.import') }}" onsubmit="return confirm('Import this configuration? Existing items are skipped, new ones are created.')">@csrf
    <div class="form-group"><label>Paste exported JSON</label><textarea name="document" class="form-control" rows="12" style="font-family:monospace;" required></textarea></div>
    <button class="btn btn-warning"><i class="fa fa-upload"></i> Import</button>
</form>
</div>@endsection
