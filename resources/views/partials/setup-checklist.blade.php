@php($setupChecks = collect($readiness)->where('group','setup'))
@php($systemChecks = collect($readiness)->where('group','system'))
@php($infoChecks = collect($readiness)->where('group','info'))
@php($setupDone = $setupChecks->every(fn($c)=>$c['ok']))
@php($setupTotal = $setupChecks->count())
@php($setupOk = $setupChecks->where('ok',true)->count())
{{--
    P0-4: the banner read "6 / 6 steps done" above what looked like seven
    checklist rows. The denominator was already correct -- the seventh row is an
    informational check that is deliberately not scored -- but it sat in the same
    list, so the arithmetic looked broken. The scored steps and the
    informational ones are now visibly separate sections, and the counter says
    which of the two it is counting. Both numbers derive from $setupChecks, so
    adding a check cannot make them disagree.
--}}
<div class="panel {{ $setupDone ? 'panel-success' : 'panel-warning' }}">
    <div class="panel-heading" role="button" data-toggle="collapse" data-target="#iapm-setup-body" style="cursor:pointer;">
        <i class="fa fa-{{ $setupDone ? 'check-circle' : 'exclamation-triangle' }}"></i>
        <strong>Setup {{ $setupDone ? 'complete' : 'checklist' }}</strong>
        <span class="pull-right" data-iapm-setup-score="{{ $setupOk }}/{{ $setupTotal }}">{{ $setupOk }} / {{ $setupTotal }} required steps done</span>
    </div>
    <div id="iapm-setup-body" class="collapse {{ $setupDone ? '' : 'in' }}">
        <div class="list-group" style="margin-bottom:0;" data-iapm-scored-steps>
            {{-- P4-2: the rows were plain text. Every row that has a route is now
                 a link to the page that resolves it — including completed ones,
                 so the checklist doubles as navigation once setup is done. --}}
            @foreach($setupChecks as $check)
            <div class="list-group-item" data-iapm-scored-step>
                <i class="fa fa-{{ $check['ok'] ? 'check text-success' : 'circle-o' }}" style="width:1.2em;"></i>
                @if($check['route'])
                    <a href="{{ route($check['route']) }}"><strong>{{ $check['label'] }}</strong></a>
                @else
                    <strong>{{ $check['label'] }}</strong>
                @endif
                @unless($check['ok'])
                    @if($check['route'])<a class="btn btn-primary btn-xs pull-right" href="{{ route($check['route']) }}">{{ $check['action'] ?? 'Fix' }} <i class="fa fa-arrow-right"></i></a>@endif
                    <div class="iapm-hint" style="margin-top:4px;">{{ $check['hint'] }}</div>
                @endunless
            </div>
            @endforeach
        </div>

        @if($infoChecks->isNotEmpty())
        <div class="panel-body" style="padding-bottom:6px;border-top:1px solid rgba(128,128,128,.35);">
            <p class="iapm-hint" style="margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em;font-size:11px;">
                For information &mdash; not counted in the {{ $setupTotal }} required steps
            </p>
            <div class="list-group" style="margin-bottom:0;" data-iapm-info-steps>
                @foreach($infoChecks as $check)
                <div class="list-group-item" data-iapm-info-step>
                    <i class="fa fa-{{ $check['ok'] ? 'check text-success' : 'info-circle text-info' }}" style="width:1.2em;"></i>
                    @if($check['route'])<a href="{{ route($check['route']) }}">{{ $check['label'] }}</a>@else{{ $check['label'] }}@endif
                    @unless($check['ok'])
                        @if($check['route'])<a class="btn btn-default btn-xs pull-right" href="{{ route($check['route']) }}">{{ $check['action'] ?? 'Open' }}</a>@endif
                        <span class="iapm-hint">&mdash; {{ $check['hint'] }}</span>
                    @endunless
                </div>
                @endforeach
            </div>
        </div>
        @endif

        @if($systemChecks->where('ok',false)->isNotEmpty())
        <div class="panel-body">
            <strong class="text-danger">System issues:</strong>
            <ul style="margin-bottom:0;">
                @foreach($systemChecks->where('ok',false) as $check)<li>{{ $check['label'] }} &mdash; <span class="iapm-hint">{{ $check['hint'] }}</span></li>@endforeach
            </ul>
        </div>
        @endif
    </div>
</div>
