{{--
    Shared "showing X-Y of Z" line for paginated list views.

    data-iapm-total is the machine-readable total and is always emitted, even at
    zero. The KPI-tile parity test on the Overview reads it to assert that a
    tile's number equals the number of rows behind the link it points at, which
    pagination would otherwise hide.

    The sentence itself is suppressed at zero because every list view pairs this
    with a dedicated empty state that says the same thing better.

    Expects: $paginator (LengthAwarePaginator), $noun (singular, e.g. 'incident').
--}}
@php($iapmTotal = $paginator->total())
<div class="iapm-result-bar">
    <p class="iapm-result-count" data-iapm-total="{{ $iapmTotal }}">
        @if($iapmTotal > 0)
            Showing <strong>{{ number_format($paginator->firstItem()) }}</strong>&ndash;<strong>{{ number_format($paginator->lastItem()) }}</strong>
            of <strong>{{ number_format($iapmTotal) }}</strong> {{ \Illuminate\Support\Str::plural($noun, $iapmTotal) }}.
        @endif
    </p>
    {{-- Per-page selector (P1-6). Submits by navigation so it composes with the
         current filter and sort instead of needing its own form. --}}
    @isset($perPageOptions)
    <span class="iapm-per-page">
        <label for="iapm-per-page-{{ \Illuminate\Support\Str::slug($noun) }}">Rows per page</label>
        <select class="form-control input-sm" id="iapm-per-page-{{ \Illuminate\Support\Str::slug($noun) }}" data-iapm-navigate>
            @foreach($perPageOptions as $option)
            <option value="{{ request()->fullUrlWithQuery(['per_page' => $option, 'page' => 1]) }}" @selected($option === ($perPage ?? 0))>{{ $option }}</option>
            @endforeach
        </select>
    </span>
    @endisset
</div>
