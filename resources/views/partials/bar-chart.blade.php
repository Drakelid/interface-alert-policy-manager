{{--
    Labelled bar chart for the Statistics page (P4-7).

    The old sparkline drew 1px bars with no axes and no labels, so a sparse
    period rendered as barely-visible dashes that could not be read at all. This
    keeps the same inline-SVG, no-dependency approach but adds a value axis, date
    labels, gridlines and a minimum readable bar height — and says plainly when
    there is nothing in the period rather than drawing an empty frame.

    Expects: $values (label => number), $label (accessible chart name).
    Optional: $height (plot area), $unit (y-axis noun).
--}}
@php
    $iapmSeries = $values ?? [];
    $iapmMax = $iapmSeries ? max($iapmSeries) : 0;
    $iapmTotal = array_sum($iapmSeries);
    // Round the axis up to something readable rather than the raw maximum.
    $iapmTop = max(1, (int) ceil($iapmMax / 5) * 5);
    $iapmPlotHeight = (int) ($height ?? 140);
    $iapmLeftGutter = 34;
    $iapmBottomGutter = 22;
    $iapmCount = count($iapmSeries);
    $iapmWidth = 720;
    $iapmPlotWidth = $iapmWidth - $iapmLeftGutter;
    $iapmSlot = $iapmCount > 0 ? $iapmPlotWidth / $iapmCount : $iapmPlotWidth;
    $iapmBarWidth = max(1, $iapmSlot * 0.7);
    // Label every nth day so a 365-day window stays legible.
    $iapmLabelEvery = max(1, (int) ceil($iapmCount / 12));
@endphp

@if($iapmCount === 0 || $iapmTotal == 0)
    <div class="iapm-chart-empty">
        <p><i class="fa fa-line-chart"></i> <strong>No outages recorded in this period.</strong></p>
        <p class="iapm-hint" style="margin:0;">Nothing to plot &mdash; either nothing went down, or IAPM has not been receiving alerts yet. Try a longer period, or check the <a href="{{ route('iapm.setup-helper') }}">setup helper</a> if you expected data.</p>
    </div>
@else
<svg class="iapm-chart" viewBox="0 0 {{ $iapmWidth }} {{ $iapmPlotHeight + $iapmBottomGutter }}" width="100%" height="{{ $iapmPlotHeight + $iapmBottomGutter }}"
     role="img" aria-label="{{ $label ?? 'Chart' }}: {{ $iapmTotal }} total across {{ $iapmCount }} days, peak {{ $iapmMax }}">
    {{-- Value axis: four gridlines with labels. --}}
    @foreach([0, 0.25, 0.5, 0.75, 1] as $iapmFraction)
    @php
        $iapmValue = (int) round($iapmTop * $iapmFraction);
        $iapmY = $iapmPlotHeight - ($iapmFraction * ($iapmPlotHeight - 6));
    @endphp
    <line x1="{{ $iapmLeftGutter }}" y1="{{ round($iapmY, 1) }}" x2="{{ $iapmWidth }}" y2="{{ round($iapmY, 1) }}"
          stroke="currentColor" stroke-opacity="{{ $iapmFraction === 0 ? '0.55' : '0.15' }}" stroke-width="1"></line>
    <text x="{{ $iapmLeftGutter - 6 }}" y="{{ round($iapmY + 3.5, 1) }}" text-anchor="end" font-size="10" fill="currentColor" fill-opacity="0.75">{{ $iapmValue }}</text>
    @endforeach

    @foreach(array_values($iapmSeries) as $iapmIndex => $iapmValue)
    @php
        // A non-zero day always gets at least 2px so it cannot vanish.
        $iapmBarHeight = $iapmValue > 0 ? max(2, ($iapmValue / $iapmTop) * ($iapmPlotHeight - 6)) : 0;
        $iapmX = $iapmLeftGutter + ($iapmIndex * $iapmSlot) + (($iapmSlot - $iapmBarWidth) / 2);
        $iapmDate = array_keys($iapmSeries)[$iapmIndex];
    @endphp
    @if($iapmBarHeight > 0)
    <rect x="{{ round($iapmX, 2) }}" y="{{ round($iapmPlotHeight - $iapmBarHeight, 2) }}" width="{{ round($iapmBarWidth, 2) }}" height="{{ round($iapmBarHeight, 2) }}"
          rx="1" fill="currentColor" fill-opacity="0.75"><title>{{ $iapmDate }}: {{ $iapmValue }} {{ \Illuminate\Support\Str::plural($unit ?? 'outage', $iapmValue) }}</title></rect>
    @endif
    @if($iapmIndex % $iapmLabelEvery === 0)
    <text x="{{ round($iapmX + $iapmBarWidth / 2, 2) }}" y="{{ $iapmPlotHeight + 14 }}" text-anchor="middle" font-size="9" fill="currentColor" fill-opacity="0.75">{{ \Illuminate\Support\Str::substr((string) $iapmDate, 5) }}</text>
    @endif
    @endforeach
</svg>
<p class="iapm-hint" style="margin:4px 0 0;">
    {{ $iapmTotal }} {{ \Illuminate\Support\Str::plural($unit ?? 'outage', $iapmTotal) }} over {{ $iapmCount }} days &middot; peak {{ $iapmMax }} on one day &middot; {{ array_key_first($iapmSeries) }} to {{ array_key_last($iapmSeries) }}
</p>
@endif
