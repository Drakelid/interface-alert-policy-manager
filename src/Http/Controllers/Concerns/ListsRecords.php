<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Shared per-page and sorting handling for the list views (P1-6).
 *
 * None of the lists had a per-page control, a total, or column sorting. On a
 * real fleet "Interfaces without policy" alone returns thousands of rows, so
 * these are load-bearing rather than cosmetic.
 *
 * Sort columns are whitelisted per view and mapped to real SQL expressions, so
 * a crafted `sort` parameter can never reach the query builder as raw input.
 */
trait ListsRecords
{
    /** Offered in the per-page selector; anything else falls back to the default. */
    public const PER_PAGE_OPTIONS = [25, 50, 100, 250];

    protected function perPage(Request $request, int $default): int
    {
        $requested = $request->integer('per_page');

        return in_array($requested, self::PER_PAGE_OPTIONS, true) ? $requested : $default;
    }

    /**
     * Resolves the requested sort against a whitelist.
     *
     * @param  array<string, string|list<string>>  $sortable  UI key => column(s) to order by
     * @return array{key: string|null, direction: string, columns: list<string>}
     */
    protected function sort(Request $request, array $sortable, ?string $default = null): array
    {
        $key = (string) $request->query('sort', (string) $default);
        $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (! array_key_exists($key, $sortable)) {
            return ['key' => null, 'direction' => $direction, 'columns' => []];
        }

        return ['key' => $key, 'direction' => $direction, 'columns' => (array) $sortable[$key]];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  array{key: string|null, direction: string, columns: list<string>}  $sort
     */
    protected function applySort($query, array $sort): void
    {
        foreach ($sort['columns'] as $column) {
            $query->orderBy($column, $sort['direction']);
        }
    }

    /**
     * View data every list view's controls need.
     *
     * @param  array<string, string|list<string>>  $sortable
     * @return array<string, mixed>
     */
    protected function listControls(Request $request, array $sortable, array $sort, int $perPage): array
    {
        return [
            'sortable' => array_keys($sortable),
            'sortKey' => $sort['key'],
            'sortDirection' => $sort['direction'],
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ];
    }
}
