<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Location;
use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\Concerns\ListsRecords;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\RebuildPolicyCacheJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\EntityLookup;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyCacheRebuilder;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;

class InterfaceMatrixController
{
    use ListsRecords;

    /**
     * Whitelisted sort columns (P1-6). Hostname sorts through the devices join
     * that query() adds on demand, so the visible column really is what orders.
     */
    private const SORTABLE = [
        'port_id' => 'ports.port_id',
        'hostname' => 'devices.hostname',
        'ifName' => 'ports.ifName',
        'ifAlias' => 'ports.ifAlias',
        'admin' => 'ports.ifAdminStatus',
        'oper' => 'ports.ifOperStatus',
    ];

    public function index(Request $request, InterfaceContextService $contexts, PolicyResolver $resolver)
    {
        $perPage = $this->perPage($request, 50);
        $sort = $this->sort($request, self::SORTABLE);
        $ports = $this->query($request, $sort)->paginate($perPage)->withQueryString();
        $incidentMap = Incident::whereIn('port_id', $ports->getCollection()->pluck('port_id'))->where('state', '!=', 'recovered')->get()->keyBy('port_id');
        $rows = $ports->getCollection()->map(function ($port) use ($contexts, $resolver, $incidentMap) {
            $resolution = $resolver->resolve($contexts->forPort($port));

            return ['port' => $port, 'policy' => $resolution->policy, 'winner' => $resolution->winner, 'candidates' => $resolution->candidates, 'incident' => $incidentMap->get($port->port_id)];
        });
        $ports->setCollection($rows);

        // P1-2: the device-group and location filters were free-text numeric
        // boxes. Both sets are small enough to enumerate, so they become selects;
        // devices are not, so that filter is a type-ahead and only needs the
        // label of whatever is currently selected.
        $device = $request->filled('device_id') ? Device::find($request->integer('device_id')) : null;

        return view('iapm::matrix', [
            'rows' => $ports,
            'policies' => Policy::where('enabled', true)->orderBy('name')->get(),
            'deviceGroups' => DeviceGroup::orderBy('name')->get(['id', 'name']),
            'locations' => Location::orderBy('location')->get(['id', 'location']),
            'deviceFilterLabel' => $device ? app(EntityLookup::class)->deviceLabel($device) : '',
            'cache' => app(PolicyCacheRebuilder::class)->state(),
        ] + $this->listControls($request, self::SORTABLE, $sort, $perPage));
    }

    /**
     * P1-7: the matrix used to display "Run `php artisan iapm:cache-rebuild`",
     * which a web-only administrator cannot act on.
     */
    public function rebuildCache(Request $request, PolicyCacheRebuilder $rebuilder, AuditService $audit)
    {
        abort_unless($request->user()->can('manage iapm assignments'), 403);
        if ($rebuilder->state()['running']) {
            return back()->with('status', 'A cache rebuild is already running.');
        }
        $rebuilder->markQueued();
        RebuildPolicyCacheJob::dispatch();
        $audit->record($request, 'rebuilt_cache', 'interface_matrix', null);

        return back()->with('status', 'Cache rebuild started. Progress appears above; you can leave this page.');
    }

    /** Polled by the matrix while a rebuild runs. */
    public function cacheStatus(PolicyCacheRebuilder $rebuilder)
    {
        return response()->json($rebuilder->state());
    }

    public function bulk(Request $request, AuditService $audit)
    {
        $data = $request->validate(['port_ids' => ['required', 'array', 'max:1000'], 'port_ids.*' => ['integer', 'exists:ports,port_id'], 'operation' => ['required', 'in:assign,remove_assignment,mute,unmute'], 'policy_id' => ['nullable', 'required_if:operation,assign', 'exists:iapm_policies,id'], 'muted_until' => ['nullable', 'required_if:operation,mute', 'date', 'after:now']]);
        $assign = in_array($data['operation'], ['assign', 'remove_assignment'], true);
        abort_unless($request->user()->can($assign ? 'manage iapm assignments' : 'mute iapm incidents'), 403);
        DB::transaction(function () use ($data) {
            if ($data['operation'] === 'assign') {
                foreach ($data['port_ids'] as $id) {
                    Assignment::updateOrCreate(['assignment_type' => 'port', 'assignment_reference' => (string) $id], ['policy_id' => $data['policy_id'], 'match_mode' => 'any', 'priority' => 0, 'enabled' => true]);
                }
            } elseif ($data['operation'] === 'remove_assignment') {
                Assignment::where('assignment_type', 'port')->whereIn('assignment_reference', array_map('strval', $data['port_ids']))->delete();
            } elseif ($data['operation'] === 'mute') {
                Incident::whereIn('port_id', $data['port_ids'])->where('state', '!=', 'recovered')->update(['muted_until' => $data['muted_until']]);
            } else {
                Incident::whereIn('port_id', $data['port_ids'])->update(['muted_until' => null]);
            }DB::table('iapm_interface_policy_cache')->whereIn('port_id', $data['port_ids'])->delete();
        });
        $audit->record($request, 'bulk_'.$data['operation'], 'interface_matrix', null, null, ['port_ids' => $data['port_ids'], 'policy_id' => $data['policy_id'] ?? null, 'muted_until' => $data['muted_until'] ?? null]);

        return back()->with('status', 'Bulk operation completed. Rebuild the cache after large assignment changes.');
    }

    public function export(Request $request, InterfaceContextService $contexts, PolicyResolver $resolver)
    {
        abort_unless($request->user()->can('view iapm'), 403);

        return response()->streamDownload(function () use ($request, $contexts, $resolver) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['device_id', 'hostname', 'port_id', 'ifName', 'ifAlias', 'admin', 'oper', 'policy', 'assignment_source']);
            $this->query($request)->chunkById(500, function ($ports) use ($out, $contexts, $resolver) {
                foreach ($ports as $port) {
                    $result = $resolver->resolve($contexts->forPort($port), writeCache: false);
                    fputcsv($out, [$port->device_id, $port->device->hostname, $port->port_id, $port->ifName, $port->ifAlias, $port->ifAdminStatus?->value, $port->ifOperStatus?->value, $result->policy?->name, $result->winner?->assignment_type->value]);
                }
            }, 'ports.port_id', 'port_id');
            fclose($out);
        }, 'iapm-interface-matrix-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  array{key: string|null, direction: string, columns: list<string>}|null  $sort
     */
    private function query(Request $request, ?array $sort = null)
    {
        // port_id lets the header's "Find interface…" type-ahead land on the exact
        // interface the operator picked instead of a name search that may match
        // the same ifName on many devices (P1-2).
        return Port::query()->select('ports.*')->leftJoin('iapm_interface_policy_cache as ipc', 'ipc.port_id', '=', 'ports.port_id')->with(['device.location', 'device.groups', 'groups'])->when($request->filled('port_id'), fn ($q) => $q->where('ports.port_id', $request->integer('port_id')))->when($request->filled('device_group_id'), fn ($q) => $q->whereHas('device.groups', fn ($g) => $g->where('device_groups.id', $request->integer('device_group_id'))))->when($request->filled('device_id'), fn ($q) => $q->where('ports.device_id', $request->integer('device_id')))->when($request->filled('location_id'), fn ($q) => $q->whereHas('device', fn ($d) => $d->where('location_id', $request->integer('location_id'))))->when($request->filled('policy_id'), fn ($q) => $q->where('ipc.policy_id', $request->integer('policy_id')))->when($request->filled('assignment_source'), fn ($q) => $q->where('ipc.assignment_source', $request->string('assignment_source')))->when($request->boolean('no_policy'), fn ($q) => $q->whereNull('ipc.policy_id'))->when($request->filled('admin'), fn ($q) => $q->where('ports.ifAdminStatus', $request->string('admin')))->when($request->filled('oper'), fn ($q) => $q->where('ports.ifOperStatus', $request->string('oper')))->when($request->filled('incident_state'), fn ($q) => $q->whereExists(fn ($i) => $i->selectRaw('1')->from('iapm_incidents as ii')->whereColumn('ii.port_id', 'ports.port_id')->where('ii.state', $request->string('incident_state'))))->when($request->boolean('active_incident'), fn ($q) => $q->whereExists(fn ($i) => $i->selectRaw('1')->from('iapm_incidents as ii')->whereColumn('ii.port_id', 'ports.port_id')->whereIn('ii.state', ['pending', 'active', 'acknowledged', 'suppressed'])))->when($request->boolean('muted'), fn ($q) => $q->whereExists(fn ($i) => $i->selectRaw('1')->from('iapm_incidents as ii')->whereColumn('ii.port_id', 'ports.port_id')->where('ii.muted_until', '>', now())))->when($request->filled('search'), function ($q) use ($request) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('search')).'%';
            $q->where(fn ($s) => $s->where('ports.ifName', 'like', $term)->orWhere('ports.ifAlias', 'like', $term)->orWhere('ports.ifDescr', 'like', $term));
        })
            // Sorting by hostname needs the devices table; joined only when asked
            // for so the default listing keeps its existing plan.
            ->when(($sort['key'] ?? null) === 'hostname', fn ($q) => $q->join('devices', 'devices.device_id', '=', 'ports.device_id'))
            ->when(! empty($sort['columns'] ?? []), function ($q) use ($sort): void {
                foreach ($sort['columns'] as $column) {
                    $q->orderBy($column, $sort['direction']);
                }
            })
            // port_id last as a stable tie-break: chunkById on the export path and
            // pagination both need a deterministic total order.
            ->orderBy('ports.port_id');
    }
}
