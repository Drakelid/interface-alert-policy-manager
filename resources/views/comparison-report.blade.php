@extends('layouts.librenmsv1') @section('title','IAPM Dry-run Comparison') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h1 class="iapm-page-title">Dry-run Comparison</h1>
<form class="form-inline" style="margin-bottom:10px;"><label for="iapm-cmp-days">Window (days)</label> <input type="number" min="1" max="90" name="days" id="iapm-cmp-days" value="{{ $days }}" class="form-control"> <button class="btn btn-default">Apply</button> <a class="btn btn-default" href="{{ route('iapm.comparison-report.export',['days'=>$days]) }}"><i class="fa fa-download"></i> CSV</a></form>
{{-- P4-1: col-sm-3 left the ninth card orphaned on a row of its own. The tile
     grid wraps evenly at every width instead. --}}
<div class="iapm-tile-grid">@foreach($metrics as $name=>$value)<div class="panel panel-default iapm-tile"><div class="panel-heading">{{ str_replace('_',' ',ucfirst($name)) }}</div><div class="panel-body"><strong>{{ $value }}</strong></div></div>@endforeach</div>

<div class="panel panel-default"><div class="panel-heading"><h2 style="font-size:15px;margin:0;">By policy</h2></div>
<div class="table-responsive"><table class="table table-condensed"><thead><tr><th>Policy</th><th>Outage episodes</th><th>Would send (dry-run)</th><th>Sent (live)</th><th>Suppressed</th></tr></thead><tbody>
@forelse($byPolicy as $row)<tr><td>{{ $row['policy'] }}</td><td>{{ $row['incidents'] }}</td><td>{{ $row['would_send'] }}</td><td>{{ $row['sent'] }}</td><td>{{ $row['suppressed'] }}</td></tr>@empty<tr><td colspan="5" class="iapm-hint">No activity in this window.</td></tr>@endforelse
</tbody></table></div></div>

<p class="iapm-hint">IAPM decisions only. Existing direct LibreNMS transport deliveries are not observable by IAPM and should be compared against LibreNMS's own transport logs during cutover.</p>
</div>@endsection
