@extends('layouts.librenmsv1') @section('title','IAPM Import/Export') @section('content')
@php($iapmBadge = ['create'=>'success','update'=>'info','skip'=>'default'])
<div class="container-fluid">
    @include('iapm::partials.nav')
    <h1 class="iapm-page-title">Import / Export configuration</h1>
    <p class="iapm-hint" style="max-width:70em;">Move schedules, policies, actions and assignments between installs, or keep a backup. Destinations are deliberately not exported &mdash; they hold environment-specific secrets &mdash; so actions reference their destination <em>by name</em> and are matched against the destinations that already exist here.</p>

    <a class="btn btn-primary" href="{{ route('iapm.export') }}"><i class="fa fa-download"></i> Export configuration</a>

    <hr>
    <h2>Import</h2>

    @isset($report)
    {{-- P1-8: the old summary was four totals. This reports every item and what
         happened to it, so a promotion can actually be verified. --}}
    <div class="panel panel-success">
        <div class="panel-heading">
            <strong>Import complete</strong> &mdash;
            {{ $report['counts']['create'] }} created, {{ $report['counts']['update'] }} updated, {{ $report['counts']['skip'] }} skipped.
        </div>
        @include('iapm::partials.import-plan-table',['items'=>$report['items'],'badge'=>$iapmBadge,'past'=>true])
    </div>
    @endisset

    @isset($plan)
    <div class="panel panel-info">
        <div class="panel-heading">
            <strong>Dry run &mdash; nothing has been written yet.</strong>
            This would create {{ $plan['counts']['create'] }}, update {{ $plan['counts']['update'] }} and skip {{ $plan['counts']['skip'] }} item(s).
        </div>
        @include('iapm::partials.import-plan-table',['items'=>$plan['items'],'badge'=>$iapmBadge,'past'=>false])
        <div class="panel-body" style="border-top:1px solid rgba(128,128,128,.25);">
            <form method="post" action="{{ route('iapm.import') }}" data-iapm-confirm="Apply this import? {{ $plan['counts']['create'] }} item(s) will be created and {{ $plan['counts']['update'] }} overwritten.">@csrf
                <input type="hidden" name="document" value="{{ $document }}">
                <input type="hidden" name="update_existing" value="{{ $updateExisting ? '1' : '0' }}">
                <button class="btn btn-warning" name="action" value="apply"><i class="fa fa-check"></i> Apply this import</button>
                <a class="btn btn-default" href="{{ route('iapm.import.form') }}">Cancel</a>
            </form>
        </div>
    </div>
    @endisset

    <form method="post" action="{{ route('iapm.import') }}" enctype="multipart/form-data">@csrf
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="iapm-import-file">Upload an exported file</label>
                    <input type="file" id="iapm-import-file" name="file" accept=".json,application/json">
                    <p class="iapm-hint">A <code>.json</code> file produced by Export, up to 5&nbsp;MB. Takes precedence over anything pasted below.</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label for="iapm-import-paste">&hellip; or paste the JSON</label>
                    <textarea name="document" id="iapm-import-paste" class="form-control" rows="10" style="font-family:monospace;">{{ old('document') }}</textarea>
                </div>
            </div>
        </div>

        <div class="checkbox">
            <label for="iapm-update-existing">
                <input type="checkbox" id="iapm-update-existing" name="update_existing" value="1" @checked(old('update_existing', $updateExisting))>
                <strong>Update items that already exist here</strong>
            </label>
            <p class="iapm-hint">
                Off (the default): an item whose name already exists is left untouched. On: its fields are overwritten from the document, and matching actions and assignments are updated in place.
                Either way <strong>nothing is deleted</strong> &mdash; actions and assignments that exist here but are absent from the document are kept, because an import should not silently remove alerting the document simply does not mention.
            </p>
        </div>

        <button class="btn btn-primary" name="action" value="preview"><i class="fa fa-search"></i> Preview changes</button>
        <span class="iapm-hint">Nothing is written until you review the plan and confirm.</span>
    </form>
</div>@endsection
