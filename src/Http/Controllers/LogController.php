<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\Concerns\ListsRecords;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogController extends Controller
{
    use ListsRecords;

    /** Whitelisted sort columns for each log (P1-6). */
    private const DELIVERY_SORTABLE = [
        'created_at' => 'created_at',
        'incident_id' => 'incident_id',
        'destination_id' => 'destination_id',
        'phase' => 'phase',
        'status' => 'status',
        'response_status' => 'response_status',
    ];

    private const AUDIT_SORTABLE = [
        'created_at' => 'created_at',
        'user_id' => 'user_id',
        'action' => 'action',
        'object_type' => ['object_type', 'object_id'],
    ];

    /**
     * The Overview's "Failed deliveries (24h)" tile counts both failure statuses
     * over a 24-hour window, so the log needs a matching grouped status and a
     * time window for the tile's link to land on the same rows (P0-3).
     */
    public const FAILED_STATUSES = ['failed', 'failed_configuration'];

    public function deliveries(Request $r)
    {
        $sort = $this->sort($r, self::DELIVERY_SORTABLE);
        // Newest first unless the operator picks a column.
        $q = $this->deliveryQuery($r)->when($sort['key'] === null, fn ($q) => $q->latest());

        $this->applySort($q, $sort);
        $perPage = $this->perPage($r, 100);
        $deliveries = $q->paginate($perPage)->withQueryString();
        $incident = $r->filled('incident_id') ? Incident::find($r->integer('incident_id')) : null;

        return view('iapm::delivery-log', [
            'deliveries' => $deliveries,
            // P1-3: the table showed "Dest: 1". Resolve the names for the rows on
            // this page only, rather than eager-loading a relation per row.
            'destinationNames' => Destination::whereIn('id', $deliveries->pluck('destination_id')->filter()->unique())->pluck('name', 'id'),
            'destinations' => Destination::orderBy('name')->get(['id', 'name']),
            'incidentFilterLabel' => $incident ? $this->incidentLabel($incident) : '',
        ] + $this->listControls($r, self::DELIVERY_SORTABLE, $sort, $perPage));
    }

    public function audits(Request $r)
    {
        $sort = $this->sort($r, self::AUDIT_SORTABLE);
        $q = $this->auditQuery($r)->when($sort['key'] === null, fn ($q) => $q->latest('created_at'));
        $this->applySort($q, $sort);
        $perPage = $this->perPage($r, 100);
        $audits = $q->paginate($perPage)->withQueryString();
        $user = $r->filled('user_id') ? User::find($r->integer('user_id')) : null;

        return view('iapm::audit-log', [
            'audits' => $audits,
            // P1-3: the User column rendered a bare id, which defeats the purpose
            // of an audit log. Deleted accounts simply have no entry here and the
            // view falls back to the id.
            'userNames' => User::whereIn('user_id', $audits->pluck('user_id')->filter()->unique())->pluck('username', 'user_id'),
            'objectTypes' => AuditLog::OBJECT_TYPES,
            'userFilterLabel' => $user ? ($user->realname ? "$user->username ($user->realname)" : (string) $user->username) : '',
        ] + $this->listControls($r, self::AUDIT_SORTABLE, $sort, $perPage));
    }

    /**
     * P2-10: the Interface Matrix and Comparison Report could export CSV but the
     * two logs could not, which is exactly where an auditor or an incident review
     * needs the data. Streamed so a long retention window does not buffer in
     * memory, and filtered identically to the on-screen list.
     */
    public function exportDeliveries(Request $r)
    {
        abort_unless($r->user()->can('view iapm audit logs'), 403);
        $destinations = Destination::pluck('name', 'id');

        return $this->streamCsv('iapm-delivery-log', ['time', 'incident_id', 'destination', 'phase', 'status', 'http_status', 'error'],
            $this->deliveryQuery($r)->latest(),
            fn (DeliveryLog $row) => [
                $row->created_at?->format('Y-m-d H:i:s T'),
                $row->incident_id,
                $destinations[$row->destination_id] ?? $row->destination_id,
                $row->phase,
                $row->status,
                $row->response_status,
                $row->error_message,
            ]);
    }

    public function exportAudits(Request $r)
    {
        abort_unless($r->user()->can('view iapm audit logs'), 403);
        $users = User::pluck('username', 'user_id');

        return $this->streamCsv('iapm-audit-log', ['time', 'user', 'action', 'object_type', 'object_id', 'source_ip'],
            $this->auditQuery($r)->latest('created_at'),
            fn (AuditLog $row) => [
                $row->created_at?->format('Y-m-d H:i:s T'),
                $row->user_id ? ($users[$row->user_id] ?? 'user '.$row->user_id) : 'system',
                $row->action,
                $row->object_type,
                $row->object_id,
                $row->source_ip,
            ]);
    }

    /** @return Builder<DeliveryLog> */
    private function deliveryQuery(Request $r)
    {
        $status = (string) $r->query('status', '');

        return DeliveryLog::query()
            ->when($status === 'failed_any', fn ($q) => $q->whereIn('status', self::FAILED_STATUSES))
            ->when($status !== '' && $status !== 'failed_any', fn ($q) => $q->where('status', $status))
            ->when($r->filled('within'), fn ($q) => $q->where('created_at', '>=', now()->subHours($r->integer('within'))))
            ->when($r->filled('from'), fn ($q) => $q->where('created_at', '>=', $r->date('from')))
            ->when($r->filled('to'), fn ($q) => $q->where('created_at', '<=', $r->date('to')->endOfDay()))
            ->when($r->filled('phase'), fn ($q) => $q->where('phase', $r->string('phase')))
            ->when($r->filled('incident_id'), fn ($q) => $q->where('incident_id', $r->integer('incident_id')))
            ->when($r->filled('destination_id'), fn ($q) => $q->where('destination_id', $r->integer('destination_id')));
    }

    /** @return Builder<AuditLog> */
    private function auditQuery(Request $r)
    {
        return AuditLog::query()
            ->when($r->filled('action'), fn ($q) => $q->where('action', 'like', '%'.$r->string('action').'%'))
            ->when($r->filled('object_type'), fn ($q) => $q->where('object_type', $r->string('object_type')))
            ->when($r->filled('user_id'), fn ($q) => $q->where('user_id', $r->integer('user_id')))
            ->when($r->filled('from'), fn ($q) => $q->where('created_at', '>=', $r->date('from')))
            ->when($r->filled('to'), fn ($q) => $q->where('created_at', '<=', $r->date('to')->endOfDay()));
    }

    /**
     * @param  list<string>  $headers
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  callable(mixed): list<mixed>  $row
     */
    private function streamCsv(string $name, array $headers, $query, callable $row): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $query, $row): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            $query->chunk(500, function ($records) use ($out, $row): void {
                foreach ($records as $record) {
                    fputcsv($out, $row($record));
                }
            });
            fclose($out);
        }, $name.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function incidentLabel(Incident $incident): string
    {
        $context = (array) $incident->context_json;

        return sprintf('#%d — %s / %s', $incident->id, $context['hostname'] ?? 'device '.$incident->device_id, $context['ifName'] ?? 'port '.$incident->port_id);
    }
}
