@extends('layouts.librenmsv1') @section('title','IAPM Audit Log') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h1 class="iapm-page-title">Audit Log</h1>
<p class="iapm-hint" style="max-width:70em;">Every configuration change made through this plugin: who changed what, when, and from where. Incident actions (acknowledge, mute, resend) are recorded here too. This is a record of <em>changes</em> &mdash; for what was actually delivered, see the <a href="{{ route('iapm.delivery-log') }}">Delivery Log</a>.</p>
{{-- P1-2: "User ID" was a free-text numeric box and "Object type" free text.
     Users get a type-ahead searching username/real name; object types are a
     fixed vocabulary, so they are a select. --}}
<form class="panel panel-default" style="margin-bottom:10px;">
    <div class="panel-body">
        <div class="iapm-field-grid">
            <div class="form-group">
                <label for="iapm-f-action">Action</label>
                <input class="form-control" id="iapm-f-action" name="action" value="{{ request('action') }}" placeholder="e.g. deleted">
            </div>
            <div class="form-group">
                <label for="iapm-f-objecttype">Object type</label>
                <select class="form-control" id="iapm-f-objecttype" name="object_type"><option value="">Any object type</option>@foreach($objectTypes as $type)<option value="{{ $type }}" @selected(request('object_type')===$type)>{{ str_replace('_',' ',$type) }}</option>@endforeach</select>
            </div>
            @include('iapm::partials.typeahead',['name'=>'user_id','id'=>'iapm-f-user','label'=>'User','endpoint'=>route('iapm.lookup.users'),'placeholder'=>'Username…','value'=>request('user_id'),'valueLabel'=>$userFilterLabel])
            {{-- P2-10: date range and CSV export, which the matrix already had
                 and the logs -- where an audit actually needs them -- did not. --}}
            <div class="form-group">
                <label for="iapm-f-from">From</label>
                <input type="date" class="form-control" id="iapm-f-from" name="from" value="{{ request('from') }}">
            </div>
            <div class="form-group">
                <label for="iapm-f-to">To</label>
                <input type="date" class="form-control" id="iapm-f-to" name="to" value="{{ request('to') }}">
            </div>
            <div class="form-group">
                <label class="iapm-invisible-label" for="iapm-f-apply">Filter</label>
                <span class="iapm-filter-actions" style="margin-left:0;">
                    <button class="btn btn-primary" id="iapm-f-apply"><i class="fa fa-filter"></i> Filter</button>
                    <a class="btn btn-default" href="{{ route('iapm.audit-log') }}">Reset</a>
                    <a class="btn btn-default" href="{{ route('iapm.audit-log.export',request()->query()) }}"><i class="fa fa-download"></i> Export CSV</a>
                </span>
            </div>
        </div>
    </div>
</form>
@include('iapm::partials.result-count',['paginator'=>$audits,'noun'=>'audit entry'])
<div class="iapm-table-wrap"><table class="table table-condensed table-hover">
<thead><tr>
@include('iapm::partials.sort-header',['column'=>'created_at','label'=>'Time'])
@include('iapm::partials.sort-header',['column'=>'user_id','label'=>'User'])
@include('iapm::partials.sort-header',['column'=>'action','label'=>'Action'])
@include('iapm::partials.sort-header',['column'=>'object_type','label'=>'Object'])
<th>Source IP</th>
</tr></thead>
<tbody>@forelse($audits as $a)<tr>
<td>@include('iapm::partials.time',['at'=>$a->created_at])</td>
{{-- P1-3: this column rendered the bare user_id, which defeats the point of an
     audit log. Falls back to the id when the account has since been deleted. --}}
<td>@if($a->user_id){{ $userNames[$a->user_id] ?? 'user '.$a->user_id.' (deleted)' }}@else<span class="iapm-hint">system</span>@endif</td>
<td><span class="label label-default">{{ $a->action }}</span></td>
<td>@include('iapm::partials.audit-object',['audit'=>$a])</td>
<td>{{ $a->source_ip }}</td>
</tr>@empty<tr><td colspan="5" class="iapm-hint">No audit entries match.</td></tr>@endforelse
</tbody></table></div>{{ $audits->links() }}
@if($audits->total() === 0)
@include('iapm::partials.empty-state',['title'=>'No audit entries yet','body'=>'Every configuration change made through this plugin is recorded here — who changed what, and when. Entries appear as soon as someone edits a policy, destination, assignment or setting.','route'=>route('iapm.policies.index'),'action'=>'Open policies','secondaryRoute'=>route('iapm.audit-log'),'secondaryAction'=>'Clear filters'])
@endif
</div>@endsection
