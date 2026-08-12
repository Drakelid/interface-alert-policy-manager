@extends('layouts.librenmsv1') @section('title','IAPM Policy Test') @section('content')
<div class="container-fluid">@include('iapm::partials.nav')<h1 class="iapm-page-title">Policy Test</h1>
{{-- P4-8: this page previously had no explanatory copy at all -- just a bare
     numeric input and an Evaluate button -- while Simulate Alert next to it in
     the same menu is well documented. --}}
<p class="iapm-hint" style="max-width:70em;">Work out <strong>which policy would apply to one interface, and who it would page</strong>, without sending anything. Nothing is created or delivered: this only evaluates the current assignments, so it is safe to run at any time. To actually exercise the pipeline and create an incident, use <a href="{{ route('iapm.simulate') }}">Simulate Alert</a> instead.</p>
<form method="get">
    @include('iapm::partials.port-picker',['value'=>request('port_id'),'valueLabel'=>$portLabel,'required'=>true])
    <button class="btn btn-primary"><i class="fa fa-flask"></i> Evaluate</button>
</form>
@if($port)<h2>{{ $port->device->hostname }} / {{ $port->ifName }}</h2>
@if($resolution->policy)<div class="alert alert-success">Effective policy: <strong><a href="{{ route('iapm.policies.edit',$resolution->policy) }}">{{ $resolution->policy->name }}</a></strong>. @if($resolution->winner)Selected by the {{ $resolution->winner->assignment_type->value }} <a href="{{ route('iapm.policies.edit',['policy'=>$resolution->policy,'assignment'=>$resolution->winner->id]) }}#assignments">assignment</a>.@else Selected by the configured default-policy setting.@endif</div>@else<div class="alert alert-warning"><strong>No effective policy.</strong> This interface would not notify anyone. Open a policy and add an assignment covering it, or a default assignment covering everything unmatched.</div>@endif

@if($resolution->policy)
<div class="panel panel-default">
    <div class="panel-heading"><i class="fa fa-bell"></i> Who this would page</div>
    <div class="panel-body" style="padding-bottom:0;">
        @if(count($delivery))
        <table class="table table-condensed"><thead><tr><th>Phase</th><th>Destination</th><th>Resolved receivers</th></tr></thead>
        <tbody>@foreach($delivery as $d)<tr>
            <td>{{ $d['phase'] }}</td>
            <td>{{ $d['destination'] ?? '—' }}</td>
            {{-- Keep the else branch on its own line. Blade matches a directive only when
                 its '@' is not preceded by a word character, so closing a loop and opening
                 the else branch with no separator makes the else render as literal text. --}}
            <td>@if(count($d['receivers']))@foreach($d['receivers'] as $rcv)<span class="label label-info">{{ $rcv }}</span> @endforeach
            @else<span class="label label-danger" title="No receiver resolves — this action would fail configuration">no receiver</span>@endif</td>
        </tr>@endforeach</tbody></table>
        @else
        <p class="text-warning" style="margin-bottom:12px;"><i class="fa fa-bell-slash"></i> This policy has no enabled notification action — matched interfaces would trigger silently.</p>
        @endif
    </div>
</div>
@endif
<h2>Why this policy won</h2>
<p class="iapm-hint">Every assignment that matches this interface, most specific first. Precedence is port, port group, device, device group, location, ifAlias regex, ifName regex, interface type, then default; ties break on assignment priority, then policy priority, then the newest assignment.</p>
<table class="table"><thead><tr><th>Winner</th><th>Assignment</th><th>Type</th><th>Assignment priority</th><th>Policy</th><th>Policy priority</th><th>Updated</th></tr></thead><tbody>@foreach($resolution->candidates as $candidate)<tr><td>@if($candidate->id===$resolution->winner?->id)<span class="label label-success">Yes</span>@endif</td><td><a href="{{ route('iapm.policies.edit',['policy'=>$candidate->policy,'assignment'=>$candidate->id]) }}#assignments">#{{ $candidate->id }}</a></td><td>{{ $candidate->assignment_type->value }}</td><td>{{ $candidate->priority }}</td><td><a href="{{ route('iapm.policies.edit',$candidate->policy) }}">{{ $candidate->policy->name }}</a></td><td>{{ $candidate->policy->priority }}</td><td>@include('iapm::partials.time',['at'=>$candidate->updated_at])</td></tr>@endforeach</tbody></table>@endif</div>@endsection
