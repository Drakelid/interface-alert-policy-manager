<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\EntityLookup;

/**
 * Backs the type-ahead pickers that replaced the numeric-id text boxes (P1-2).
 *
 * These endpoints expose only names the caller can already read elsewhere in
 * the plugin — hostnames and interfaces appear on the Interface Matrix, incidents
 * on the incidents list — so the route group's `view iapm` is the right bar. The
 * user lookup is the exception: it backs the Audit Log filter and is gated on the
 * same ability that guards the audit log itself.
 */
class LookupController extends Controller
{
    private const PORT_PAGE_SIZE = 50;

    public function __construct(private readonly EntityLookup $lookup) {}

    public function devices(Request $request): JsonResponse
    {
        return $this->respond($request, fn (string $term) => $this->lookup->devices($term));
    }

    public function ports(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if ($term === '') {
            return response()->json([]);
        }

        $deviceId = $request->filled('device_id') ? $request->integer('device_id') : null;
        $offset = max(0, $request->integer('offset'));
        $items = $this->lookup->ports($term, $deviceId, self::PORT_PAGE_SIZE + 1, $offset);
        $hasMore = count($items) > self::PORT_PAGE_SIZE;
        if ($hasMore) {
            array_pop($items);
        }

        return response()->json([
            'items' => $items,
            'has_more' => $hasMore,
            'next_offset' => $offset + count($items),
        ]);
    }

    public function incidents(Request $request): JsonResponse
    {
        return $this->respond($request, fn (string $term) => $this->lookup->incidents($term));
    }

    public function users(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('view iapm audit logs'), 403);

        return $this->respond($request, fn (string $term) => $this->lookup->users($term));
    }

    /**
     * An empty term returns nothing rather than the first N rows: a type-ahead
     * that answers before the user has typed just invites an unbounded scan.
     *
     * @param  callable(string): list<array{id:int,label:string}>  $search
     */
    private function respond(Request $request, callable $search): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        return response()->json($term === '' ? [] : $search($term));
    }
}
