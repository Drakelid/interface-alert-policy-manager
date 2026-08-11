{{--
    A sortable table heading (P1-6).

    Clicking toggles asc/desc for that column and preserves every other query
    parameter, so sorting does not silently drop the current filter.

    Expects: $column (whitelist key), $label.
    Optional: $numeric (right-aligns), $sortKey / $sortDirection (injected by the
              view's list controls).
--}}
@php($iapmActive = ($sortKey ?? null) === $column)
@php($iapmNext = $iapmActive && ($sortDirection ?? 'asc') === 'asc' ? 'desc' : 'asc')
<th @class(['iapm-num' => $numeric ?? false]) @if($iapmActive) aria-sort="{{ $sortDirection === 'asc' ? 'ascending' : 'descending' }}" @endif>
    <a href="{{ request()->fullUrlWithQuery(['sort' => $column, 'direction' => $iapmNext, 'page' => 1]) }}"
       title="Sort by {{ $label }} ({{ $iapmNext === 'asc' ? 'ascending' : 'descending' }})">
        {{ $label }}
        @if($iapmActive)
            <i class="fa fa-caret-{{ $sortDirection === 'asc' ? 'up' : 'down' }}" aria-hidden="true"></i>
        @else
            <i class="fa fa-sort iapm-sort-idle" aria-hidden="true"></i>
        @endif
    </a>
</th>
