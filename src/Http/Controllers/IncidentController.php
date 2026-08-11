<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\Concerns\ListsRecords;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\IncidentLifecycleService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SuppressionService;

class IncidentController extends Controller
{
    use ListsRecords;

    /**
     * Whitelisted sort columns (P1-6). Keys are the UI's, values the SQL, so a
     * crafted `sort` parameter can never reach the query builder.
     */
    private const SORTABLE = [
        'id' => 'id',
        'state' => 'state',
        'severity' => 'severity',
        'first_seen_at' => 'first_seen_at',
        'last_seen_at' => 'last_seen_at',
    ];

    public function index(Request $r)
    {
        $q = Incident::with('policy');
        $state = (string) $r->query('state', '');
        // Default landing ('' / no param) shows the OPEN working set (recovered hidden);
        // 'all' shows every state; a specific value filters to it.
        if ($state === 'all') {
        } elseif ($state !== '') {
            $q->where('state', $state);
        } else {
            $q->where('state', '!=', IncidentState::Recovered->value);
        }
        if ($r->filled('device_id')) {
            $q->where('device_id', $r->integer('device_id'));
        }
        // P0-3: the Overview KPI tiles must land on exactly the population they
        // counted, so each tile's metric has a matching filter here.
        if ($r->filled('severity')) {
            $q->where('severity', $r->string('severity'));
        }
        if ($r->filled('suppression_reason')) {
            $q->where('suppression_reason', $r->string('suppression_reason'));
        }
        if ($r->query('escalation') === 'pending') {
            $q->whereHas('policy.actions', fn ($a) => $a->where('phase', 'escalation')->where('enabled', true));
        }
        if ($r->filled('recovered_within')) {
            $q->where('recovered_at', '>=', now()->subHours($r->integer('recovered_within')));
        }
        $sort = $this->sort($r, self::SORTABLE);
        if ($sort['key'] === null) {
            // Default triage order: active before pending/ack/suppressed/recovered,
            // critical before warning, then oldest first. An explicit sort replaces
            // it entirely rather than layering on top, so the chosen column wins.
            $q->orderByRaw("CASE state WHEN 'active' THEN 0 WHEN 'pending' THEN 1 WHEN 'acknowledged' THEN 2 WHEN 'suppressed' THEN 3 ELSE 4 END")
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
                ->orderBy('first_seen_at');
        } else {
            $this->applySort($q, $sort);
        }
        $perPage = $this->perPage($r, 50);

        return view('iapm::incidents.index', [
            'incidents' => $q->paginate($perPage)->withQueryString(),
            'suppressionReasons' => SuppressionService::REASONS,
        ] + $this->listControls($r, self::SORTABLE, $sort, $perPage));
    }

    public function show(Incident $incident)
    {
        return view('iapm::incidents.show', ['incident' => $incident->load(['policy.actions.destination', 'events', 'deliveries'])]);
    }

    public function acknowledge(Request $r, Incident $incident, AuditService $audit, IncidentLifecycleService $lifecycle)
    {
        abort_unless($r->user()->can('acknowledge iapm incidents'), 403);
        abort_if($incident->state === IncidentState::Recovered, 409, 'A recovered incident cannot be acknowledged.');
        $before = $incident->toArray();
        $lifecycle->acknowledge($incident, (int) $r->user()->getAuthIdentifier());
        $audit->record($r, 'acknowledged', 'incident', $incident, $before, $incident->fresh()->toArray());

        return back()->with('status', 'Incident acknowledged.');
    }

    public function unacknowledge(Request $r, Incident $incident, AuditService $audit, IncidentLifecycleService $lifecycle)
    {
        abort_unless($r->user()->can('acknowledge iapm incidents'), 403);
        abort_unless($incident->state === IncidentState::Acknowledged, 409, 'Only an acknowledged incident can be unacknowledged.');
        $before = $incident->toArray();
        try {
            $lifecycle->unacknowledge($incident, (int) $r->user()->getAuthIdentifier());
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }$audit->record($r, 'unacknowledged', 'incident', $incident, $before, $incident->fresh()->toArray());

        return back()->with('status', 'Acknowledgement removed.');
    }

    public function mute(Request $r, Incident $incident, AuditService $audit)
    {
        abort_unless($r->user()->can('mute iapm incidents'), 403);
        $d = $r->validate(['muted_until' => ['required', 'date', 'after:now']]);
        $incident->update(['muted_until' => $d['muted_until']]);
        $incident->events()->create(['event_type' => 'muted', 'event_message' => 'Notifications muted until '.$d['muted_until'], 'actor_user_id' => $r->user()->getAuthIdentifier()]);
        $audit->record($r, 'muted', 'incident', $incident, null, ['muted_until' => $d['muted_until']]);

        return back();
    }

    public function unmute(Request $r, Incident $incident, AuditService $audit)
    {
        abort_unless($r->user()->can('mute iapm incidents'), 403);
        $incident->update(['muted_until' => null]);
        $incident->events()->create(['event_type' => 'unmuted', 'event_message' => 'Notifications unmuted.', 'actor_user_id' => $r->user()->getAuthIdentifier()]);
        $audit->record($r, 'unmuted', 'incident', $incident);

        return back();
    }

    public function reconcile(Request $r, Incident $incident)
    {
        abort_unless($r->user()->can('acknowledge iapm incidents'), 403);
        Artisan::call('iapm:reconcile', ['--incident' => $incident->id]);

        return back()->with('status', trim(Artisan::output()));
    }

    public function resend(Request $r, Incident $incident, AuditService $audit)
    {
        abort_unless($r->user()->can('acknowledge iapm incidents'), 403);
        $d = $r->validate(['action_id' => ['required', 'integer', 'exists:iapm_policy_actions,id']]);
        abort_unless($incident->policy?->actions()->whereKey($d['action_id'])->exists(), 422);
        Artisan::call('iapm:process-actions', ['--incident' => $incident->id, '--action' => $d['action_id'], '--force' => true]);
        $audit->record($r, 'resent', 'incident', $incident, null, ['action_id' => $d['action_id']]);

        return back()->with('status', trim(Artisan::output()));
    }
}
