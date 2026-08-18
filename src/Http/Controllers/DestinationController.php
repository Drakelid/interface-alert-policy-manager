<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\Concerns\BulkDeletes;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Requests\DestinationRequest;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\UrlGuard;

class DestinationController extends Controller
{
    use BulkDeletes;

    public function index()
    {
        return view('iapm::destinations.index', ['destinations' => Destination::withCount('actions')->paginate(50)]);
    }

    public function bulkDestroy(Request $r, AuditService $audit)
    {
        abort_unless($r->user()->can('manage iapm destinations'), 403);
        $ids = $this->bulkIds($r);
        $deleted = 0;
        $skipped = [];
        DB::transaction(function () use ($ids, &$deleted, &$skipped) {
            foreach (Destination::whereIn('id', $ids)->get() as $destination) {
                if ($this->deletionBlocker($destination) !== null) {
                    $skipped[] = $destination->name;

                    continue;
                }$this->purge($destination);
                $deleted++;
            }
        });
        $audit->record($r, 'bulk_deleted', 'destination', null, null, ['deleted' => $deleted, 'skipped' => $skipped]);
        $msg = "Deleted {$deleted} destination(s).";
        if ($skipped) {
            $msg .= ' Skipped (in use, or still holding notification history): '.implode(', ', $skipped).'.';
        }

        return redirect()->route('iapm.destinations.index')->with($skipped ? 'error' : 'status', $msg);
    }

    public function create(Request $request, SettingStore $settings)
    {
        abort_unless($request->user()->can('manage iapm destinations'), 403);

        return view('iapm::destinations.form', [
            'destination' => new Destination,
            'configuration' => [
                'timeout' => (int) $settings->get('notification_timeout', config('iapm.http.timeout', 15)),
                'retry_count' => (int) $settings->get('notification_retry_count', config('iapm.http.retries', 2)),
            ],
        ]);
    }

    public function store(DestinationRequest $r, AuditService $audit, UrlGuard $guard)
    {
        $data = $r->validated();
        $guard->assertAllowed($data['url'], $data['allow_private_networks']);
        $config = $this->config($data);
        $d = Destination::create(['name' => $data['name'], 'type' => $data['type'], 'enabled' => $data['enabled'], 'configuration_encrypted' => $config]);
        $audit->record($r, 'created', 'destination', $d, null, ['name' => $d->name, 'type' => $d->type, 'enabled' => $d->enabled]);

        return redirect()->route('iapm.destinations.edit', $d)->with('status', 'Destination created.');
    }

    public function edit(Request $request, Destination $destination)
    {
        abort_unless($request->user()->can('manage iapm destinations'), 403);

        $c = (array) $destination->configuration_encrypted;
        unset($c['password'],$c['bearer_token']);

        return view('iapm::destinations.form', ['destination' => $destination, 'configuration' => $c]);
    }

    public function update(DestinationRequest $r, Destination $destination, AuditService $audit, UrlGuard $guard)
    {
        $data = $r->validated();
        $guard->assertAllowed($data['url'], $data['allow_private_networks']);
        $before = ['name' => $destination->name, 'type' => $destination->type, 'enabled' => $destination->enabled];
        $config = array_replace((array) $destination->configuration_encrypted, $this->config($data));
        if (blank($data['password'] ?? null)) {
            $config['password'] = $destination->configuration_encrypted['password'] ?? null;
        }if (blank($data['bearer_token'] ?? null)) {
            $config['bearer_token'] = $destination->configuration_encrypted['bearer_token'] ?? null;
        }$destination->update(['name' => $data['name'], 'type' => $data['type'], 'enabled' => $data['enabled'], 'configuration_encrypted' => $config]);
        $audit->record($r, 'updated', 'destination', $destination, $before, ['name' => $destination->name, 'type' => $destination->type, 'enabled' => $destination->enabled]);

        return back()->with('status', 'Destination updated.');
    }

    public function destroy(Request $r, Destination $destination, AuditService $audit)
    {
        abort_unless($r->user()->can('manage iapm destinations'), 403);
        if ($blocker = $this->deletionBlocker($destination)) {
            return back()->withErrors($blocker);
        }$before = ['name' => $destination->name, 'type' => $destination->type];
        $this->purge($destination);
        $audit->record($r, 'deleted', 'destination', $destination->id, $before, null);

        return redirect()->route('iapm.destinations.index')->with('status', 'Destination deleted.');
    }

    /**
     * Why this destination cannot be deleted yet, or null when it can be.
     *
     * Delivery logs and outbox rows are both restrictOnDelete, but destroy() only ever
     * checked policy actions -- so a still-referenced destination raised a bare
     * QueryException ("Whoops, looks like something went wrong") instead of an
     * explanation. Real history blocks deletion and is left for iapm:cleanup to remove
     * with its incident once retention lapses.
     */
    private function deletionBlocker(Destination $destination): ?string
    {
        if ($destination->actions()->exists()) {
            return 'Destination is used by policy actions. Remove or repoint those actions first.';
        }
        if ($destination->outboxEntries()->exists()) {
            return 'Destination still has notification work in the outbox. It can be deleted once that work finishes and retention cleanup removes it.';
        }
        if ($destination->deliveryLogs()->whereNotNull('incident_id')->exists()) {
            return 'Destination has delivery history for incidents. It can be deleted once retention cleanup (iapm:cleanup) removes those incidents.';
        }

        return null;
    }

    /**
     * Delete the destination together with its incident-less delivery rows.
     *
     * Those come from the Test button, which logs a delivery with a null incident_id.
     * iapm:cleanup only reaches delivery logs by cascading from a recovered incident,
     * so nothing else ever prunes them -- leaving them in place would make every
     * destination that was tested once permanently undeletable.
     */
    private function purge(Destination $destination): void
    {
        DB::transaction(function () use ($destination) {
            $destination->deliveryLogs()->whereNull('incident_id')->delete();
            $destination->delete();
        });
    }

    public function test(Request $r, Destination $destination, NotificationDispatcher $dispatcher, AuditService $audit)
    {
        abort_unless($r->user()->can('test iapm destinations'), 403);
        $data = $r->validate(['receiver' => ['required', 'string', 'max:128']]);
        $result = $dispatcher->test($destination, $data['receiver'], 'IAPM test message from '.(config('app.url') ?: gethostname()).'. Destination configuration is working.');
        $audit->record($r, 'tested', 'destination', $destination, null, ['successful' => $result->successful, 'status' => $result->status]);

        return back()->with($result->successful ? 'status' : 'error', $result->successful ? 'Test succeeded.' : 'Test failed: '.$result->error);
    }

    private function config(array $d): array
    {
        return ['url' => $d['url'], 'username' => $d['username'] ?? null, 'password' => $d['password'] ?? null, 'bearer_token' => $d['bearer_token'] ?? null, 'default_receiver' => $d['default_receiver'] ?? null, 'mode' => $d['mode'], 'connect_timeout' => $d['connect_timeout'], 'timeout' => $d['timeout'], 'retry_count' => $d['retry_count'], 'retry_delay_ms' => $d['retry_delay_ms'], 'verify_tls' => $d['verify_tls'], 'allow_private_networks' => $d['allow_private_networks'], // '{}' rather than '[]' to match the field's own label, "JSON object" (P2-3).
            'headers' => json_decode($d['headers_json'] ?? '{}', true, 32, JSON_THROW_ON_ERROR)];
    }
}
