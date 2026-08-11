{{--
    One time presentation for the whole plugin (P2-10): relative text, with the
    exact timestamp in the title attribute so hovering answers "when exactly?".
    Anywhere a time is shown should include this rather than echoing the
    attribute, which gives either a relative string with no absolute value or an
    absolute one with no sense of recency.

    Expects: $at (?DateTimeInterface).
--}}
@php($iapmTime = ($at ?? null) instanceof \DateTimeInterface ? $at : null)
@if($iapmTime)<time datetime="{{ $iapmTime->format(DATE_ATOM) }}" title="{{ $iapmTime->format('Y-m-d H:i:s T') }}">{{ $iapmTime->diffForHumans() }}</time>@else<span class="iapm-hint">&mdash;</span>@endif
