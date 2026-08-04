@php($setupChecks = collect($readiness)->where('group','setup'))
@php($systemChecks = collect($readiness)->where('group','system'))
@php($infoChecks = collect($readiness)->where('group','info'))
@php($setupDone = $setupChecks->every(fn($c)=>$c['ok']))
@php($setupTotal = $setupChecks->count())
@php($setupOk = $setupChecks->where('ok',true)->count())
<div class="panel {{ $setupDone ? 'panel-success' : 'panel-warning' }}">
    <div class="panel-heading" role="button" data-toggle="collapse" data-target="#iapm-setup-body" style="cursor:pointer;">
        <i class="fa fa-{{ $setupDone ? 'check-circle' : 'exclamation-triangle' }}"></i>
        <strong>Setup {{ $setupDone ? 'complete' : 'checklist' }}</strong>
        <span class="pull-right">{{ $setupOk }} / {{ $setupTotal }} steps done</span>
    </div>
    <div id="iapm-setup-body" class="collapse {{ $setupDone ? '' : 'in' }}">
        <div class="list-group" style="margin-bottom:0;">
            @foreach($setupChecks as $check)
            <div class="list-group-item">
                <i class="fa fa-{{ $check['ok'] ? 'check text-success' : 'circle-o text-muted' }}" style="width:1.2em;"></i>
                <strong>{{ $check['label'] }}</strong>
                @unless($check['ok'])
                    @if($check['route'])<a class="btn btn-primary btn-xs pull-right" href="{{ route($check['route']) }}">{{ $check['action'] ?? 'Fix' }} <i class="fa fa-arrow-right"></i></a>@endif
                    <div class="text-muted" style="margin-top:4px;">{{ $check['hint'] }}</div>
                @endunless
            </div>
            @endforeach
        </div>
        @foreach($infoChecks as $check)
        <div class="list-group-item list-group-item-info">
            <i class="fa fa-{{ $check['ok'] ? 'check text-success' : 'info-circle' }}" style="width:1.2em;"></i>
            {{ $check['label'] }}
            @unless($check['ok'])
                @if($check['route'])<a class="btn btn-default btn-xs pull-right" href="{{ route($check['route']) }}">{{ $check['action'] ?? 'Open' }}</a>@endif
                <span class="text-muted">— {{ $check['hint'] }}</span>
            @endunless
        </div>
        @endforeach
        @if($systemChecks->where('ok',false)->isNotEmpty())
        <div class="panel-body">
            <strong class="text-danger">System issues:</strong>
            <ul style="margin-bottom:0;">
                @foreach($systemChecks->where('ok',false) as $check)<li>{{ $check['label'] }} — <span class="text-muted">{{ $check['hint'] }}</span></li>@endforeach
            </ul>
        </div>
        @endif
    </div>
</div>
