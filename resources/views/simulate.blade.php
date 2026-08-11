@extends('layouts.librenmsv1') @section('title','IAPM Simulate Alert') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h1 class="iapm-page-title">Simulate alert</h1>
{{-- P0-6: this used to end "Use the Interface Matrix to find a port_id", but the
     matrix never displayed one. The matrix now shows and copies port_id and links
     here per row, and the picker below searches by name, so the instruction is
     replaced with one that matches what the UI actually offers. --}}
<p class="iapm-hint" style="max-width:70em;">Fire a synthetic alert for one interface through the real ingestion pipeline to validate policy, assignment, and suppression behaviour. Incidents are created and updated as normal; external delivery still respects dry-run mode. Search for the interface below, or start from a row's <i class="fa fa-bolt"></i> shortcut on the <a href="{{ route('iapm.matrix') }}">Interface Matrix</a>. To evaluate policy without creating anything, use <a href="{{ route('iapm.policy-test') }}">Policy Test</a>.</p>

<form method="post" action="{{ route('iapm.simulate.run') }}" data-iapm-busy data-iapm-confirm="Run a simulated alert now? This creates or updates a real incident.">@csrf
    {{-- Prefilled from ?port_id= so the Interface Matrix's per-row shortcut lands
         on a ready-to-run form instead of an empty box (P1-1). --}}
    @include('iapm::partials.port-picker',['id'=>'iapm-sim','value'=>old('port_id', request('port_id')),'valueLabel'=>$portLabel,'required'=>true])
    <div class="form-group" style="max-width:320px;"><label for="iapm-sim-state">State</label>
        <select name="state" id="iapm-sim-state" class="form-control"><option value="down">Down (raise/continue)</option><option value="up">Up (recover)</option></select>
    </div>
    <button class="btn btn-warning"><i class="fa fa-bolt"></i> Run simulation</button>
</form>

@isset($result)
<div class="panel panel-{{ isset($result['error']) ? 'danger' : 'success' }}" style="margin-top:15px;max-width:640px;">
    <div class="panel-heading">Result @isset($port)for {{ $port->device?->hostname }} — {{ $port->ifName }}@endisset</div>
    <div class="panel-body">
        @if(isset($result['error']))
            <span class="text-danger">{{ $result['error'] }}</span>
        @else
            <p><strong>{{ $result['status'] ?? 'processed' }}</strong></p>
            @if(isset($result['counts']))<ul>@foreach($result['counts'] as $k => $v)<li>{{ $k }}: {{ $v }}</li>@endforeach</ul>@endif
            @if(isset($result['error']['message']))<span class="text-danger">{{ $result['error']['message'] }}</span>@endif
        @endif
    </div>
</div>
@endisset
</div>@endsection
