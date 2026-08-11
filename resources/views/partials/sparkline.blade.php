@php
$iapmSpark = array_values(array_map('floatval', $values ?? []));
$w = (int) ($width ?? 140); $h = (int) ($height ?? 30); $n = count($iapmSpark);
$max = $n ? max($iapmSpark) : 0;
@endphp
@if($n === 0)
<span class="iapm-hint">no data</span>
@else
<svg class="iapm-spark" width="{{ $w }}" height="{{ $h }}" viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="{{ $label ?? 'trend' }}">
@php $gap = $n > 40 ? 1 : 2; $bw = max(1, ($w - ($n - 1) * $gap) / $n); @endphp
@foreach($iapmSpark as $idx => $v)
@php
    $bh = $max > 0 ? max(1, ($v / $max) * ($h - 2)) : 1;
    $x = $idx * ($bw + $gap);
    $last = $idx === $n - 1;
@endphp
<rect x="{{ round($x, 2) }}" y="{{ round($h - $bh, 2) }}" width="{{ round($bw, 2) }}" height="{{ round($bh, 2) }}" rx="1" fill="currentColor" opacity="{{ $last ? '0.9' : '0.4' }}"><title>{{ ($iapmLabels[$idx] ?? ('point '.($idx + 1))).': '.rtrim(rtrim(number_format($v, 2), '0'), '.') }}</title></rect>
@endforeach
</svg>
@endif
