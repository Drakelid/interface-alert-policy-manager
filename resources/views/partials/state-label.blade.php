@php($iapmStateMap = [
    'active' => ['label-danger', 'fa-exclamation-circle'],
    'pending' => ['label-warning', 'fa-clock-o'],
    'acknowledged' => ['label-info', 'fa-check'],
    'suppressed' => ['label-default', 'fa-volume-off'],
    'recovered' => ['label-success', 'fa-check-circle'],
])
@php($iapmState = $iapmStateMap[$state] ?? ['label-default', 'fa-question'])
<span class="label {{ $iapmState[0] }} iapm-state"><i class="fa {{ $iapmState[1] }}"></i> {{ $state }}</span>
