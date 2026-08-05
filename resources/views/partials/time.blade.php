@php($iapmTime = ($at ?? null) instanceof \DateTimeInterface ? $at : null)
@if($iapmTime)<span title="{{ $iapmTime->format('Y-m-d H:i:s T') }}">{{ $iapmTime->diffForHumans() }}</span>@else<span class="text-muted">—</span>@endif
