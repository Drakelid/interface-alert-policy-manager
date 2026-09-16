{{--
    One time presentation for the whole plugin (P2-10): the exact timestamp, with
    the relative phrasing in the title attribute so hovering answers "how long
    ago?". Logs are read to answer "when exactly?" — correlating a delivery
    against a poller run or a LibreNMS alert needs a real timestamp, and "21
    hours ago" cannot be lined up against anything. Anywhere a time is shown
    should include this rather than echoing the attribute, which gives an
    absolute value with no sense of recency.

    Expects: $at (?DateTimeInterface).
--}}
@php($iapmTime = ($at ?? null) instanceof \DateTimeInterface ? $at : null)
@if($iapmTime)<time datetime="{{ $iapmTime->format(DATE_ATOM) }}" title="{{ $iapmTime->diffForHumans() }}" class="iapm-time">{{ \LibreNMS\Plugins\InterfaceAlertPolicyManager\Support\TimeText::exact($iapmTime) }}</time>@else<span class="iapm-hint">&mdash;</span>@endif
