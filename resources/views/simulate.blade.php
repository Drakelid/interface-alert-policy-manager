@extends('layouts.librenmsv1') @section('title','IAPM Simulate Alert') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')
<h2>Simulate alert</h2>
<p class="text-muted">Fire a synthetic alert for one interface through the real ingestion pipeline to validate policy, assignment, and suppression behaviour. Incidents are created/updated as normal; external delivery still respects dry-run mode. Use the <a href="{{ route('iapm.matrix') }}">Interface Matrix</a> to find a port_id.</p>

<form method="post" action="{{ route('iapm.simulate.run') }}" class="form-inline" onsubmit="return confirm('Run a simulated alert now? This creates or updates a real incident.')">@csrf
    <div class="form-group"><label>Port ID</label> <input class="form-control" name="port_id" value="{{ old('port_id') }}" required></div>
    <div class="form-group"><label>State</label>
        <select name="state" class="form-control"><option value="down">Down (raise/continue)</option><option value="up">Up (recover)</option></select>
    </div>
    <button class="btn btn-warning">Run simulation</button>
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
