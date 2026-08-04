<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait BulkDeletes
{
    /**
     * Validate and return the selected ids for a bulk delete.
     *
     * @return array<int, int>
     */
    protected function bulkIds(Request $request): array
    {
        return array_values(array_unique($request->validate([
            'ids' => ['required', 'array', 'max:1000'],
            'ids.*' => ['integer'],
        ])['ids']));
    }
}
