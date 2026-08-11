@extends('layouts.librenmsv1') @section('title','IAPM Delivery Log') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h1 class="iapm-page-title">Delivery Log</h1>
{{-- P1-2: "Incident ID" and "Destination ID" were free-text numeric boxes.
     Destinations are low-cardinality, so that one is a select; incidents are
     not, so it is a type-ahead searching by id or hostname. --}}
<form class="panel panel-default" style="margin-bottom:10px;">
    <div class="panel-body">
        <div class="iapm-field-grid">
            @include('iapm::partials.typeahead',['name'=>'incident_id','id'=>'iapm-f-incident','label'=>'Incident','endpoint'=>route('iapm.lookup.incidents'),'placeholder'=>'Number or hostname…','value'=>request('incident_id'),'valueLabel'=>$incidentFilterLabel])
            <div class="form-group">
                <label for="iapm-f-destination">Destination</label>
                <select class="form-control" id="iapm-f-destination" name="destination_id"><option value="">Any destination</option>@foreach($destinations as $destination)<option value="{{ $destination->id }}" @selected((string) request('destination_id')===(string) $destination->id)>{{ $destination->name }}</option>@endforeach</select>
            </div>
            <div class="form-group">
                <label for="iapm-f-status">Status</label>
                <select class="form-control" id="iapm-f-status" name="status"><option value="">Any status</option><option value="failed_any" @selected(request('status')==='failed_any')>failed (any reason)</option>@foreach(['sent','failed','failed_configuration','dry_run'] as $v)<option @selected(request('status')===$v)>{{ $v }}</option>@endforeach</select>
            </div>
            <div class="form-group">
                <label for="iapm-f-within">Time range</label>
                <select class="form-control" id="iapm-f-within" name="within"><option value="">Any time</option>@foreach([1=>'Last hour',24=>'Last 24 hours',168=>'Last 7 days'] as $hours=>$label)<option value="{{ $hours }}" @selected((string) request('within')===(string) $hours)>{{ $label }}</option>@endforeach</select>
            </div>
            <div class="form-group">
                <label for="iapm-f-phase">Phase</label>
                <select class="form-control" id="iapm-f-phase" name="phase"><option value="">Any phase</option>@foreach(['trigger','escalation','reminder','recovery','acknowledged','flapping','digest','test'] as $v)<option @selected(request('phase')===$v)>{{ $v }}</option>@endforeach</select>
            </div>
            <div class="form-group">
                <label class="iapm-invisible-label" for="iapm-f-apply">Filter</label>
                <span class="iapm-filter-actions" style="margin-left:0;">
                    <button class="btn btn-primary" id="iapm-f-apply"><i class="fa fa-filter"></i> Filter</button>
                    <a class="btn btn-default" href="{{ route('iapm.delivery-log') }}">Reset</a>
                </span>
            </div>
        </div>
    </div>
</form>
@include('iapm::partials.result-count',['paginator'=>$deliveries,'noun'=>'delivery'])
<div class="iapm-table-wrap"><table class="table table-condensed table-hover">
<thead><tr>
@include('iapm::partials.sort-header',['column'=>'created_at','label'=>'Time'])
@include('iapm::partials.sort-header',['column'=>'incident_id','label'=>'Incident'])
@include('iapm::partials.sort-header',['column'=>'destination_id','label'=>'Destination'])
@include('iapm::partials.sort-header',['column'=>'phase','label'=>'Phase'])
@include('iapm::partials.sort-header',['column'=>'status','label'=>'Status'])
@include('iapm::partials.sort-header',['column'=>'response_status','label'=>'HTTP','numeric'=>true])
<th>Error</th>
</tr></thead>
<tbody>@forelse($deliveries as $d)<tr>
<td>@include('iapm::partials.time',['at'=>$d->created_at])</td>
<td>@if($d->incident_id)<a href="{{ route('iapm.incidents.show',$d->incident_id) }}">#{{ $d->incident_id }}</a>@else<span class="iapm-hint">&mdash;</span>@endif</td>
{{-- P1-3: this rendered the bare destination_id. destination_id is non-null and
     restrictOnDelete, so the name always resolves -- no deleted-row branch. --}}
<td><a href="{{ route('iapm.destinations.edit',$d->destination_id) }}">{{ $destinationNames[$d->destination_id] ?? ('destination '.$d->destination_id) }}</a></td>
<td>{{ $d->phase }}</td>
<td>@if($d->status==='sent')<span class="label label-success">sent</span>@elseif($d->status==='dry_run')<span class="label label-info">dry-run</span>@else<span class="label label-danger">{{ $d->status }}</span>@endif</td>
<td class="iapm-num">{{ $d->response_status }}</td>
<td class="iapm-truncate" title="{{ $d->error_message }}">{{ $d->error_message }}</td>
</tr>@empty<tr><td colspan="7" class="text-muted">No deliveries match.</td></tr>@endforelse
</tbody></table></div>{{ $deliveries->links() }}</div>@endsection
