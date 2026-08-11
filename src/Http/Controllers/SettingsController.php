<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers;

use App\Models\PortGroup;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AuditService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;

class SettingsController extends Controller
{
    public function edit(SettingStore $s)
    {
        return view('iapm::admin-settings', ['values' => ['dry_run' => $s->get('dry_run', true), 'default_policy_id' => $s->get('default_policy_id'), 'sms_default_receiver' => $s->get('sms_default_receiver'), 'retention_days' => $s->get('retention_days', 365), 'notification_timeout' => $s->get('notification_timeout', 15), 'notification_retry_count' => $s->get('notification_retry_count', 2), 'deleted_port_behavior' => $s->get('deleted_port_behavior', 'recover'), 'url_base' => $s->get('url_base', config('app.url')), 'uplink_port_group_id' => $s->get('uplink_port_group_id'), 'aggregate_threshold' => $s->get('aggregate_threshold', 0), 'aggregate_window_seconds' => $s->get('aggregate_window_seconds', 120), 'dispatch_mode' => $s->get('dispatch_mode', 'queue'), 'record_unpoliced' => $s->get('record_unpoliced', true), 'has_token' => filled($s->get('ingestion_token'))], 'policies' => Policy::orderBy('name')->get(), 'portGroups' => PortGroup::orderBy('name')->get(['id', 'name'])]);
    }

    public function update(Request $r, SettingStore $s, AuditService $audit)
    {
        abort_unless($r->user()->can('manage iapm settings'), 403);
        $d = $r->validate(['dry_run' => ['nullable', 'boolean'], 'default_policy_id' => ['nullable', 'exists:iapm_policies,id'], 'sms_default_receiver' => ['nullable', 'string', 'max:128'], 'retention_days' => ['required', 'integer', 'between:1,3650'], 'notification_timeout' => ['required', 'integer', 'between:1,300'], 'notification_retry_count' => ['required', 'integer', 'between:0,10'], 'deleted_port_behavior' => ['required', 'in:recover,retain'], 'url_base' => ['nullable', 'url:http,https', 'max:2048'], 'uplink_port_group_id' => ['nullable', 'integer', 'exists:port_groups,id'], 'aggregate_threshold' => ['required', 'integer', 'between:0,1000'], 'aggregate_window_seconds' => ['required', 'integer', 'between:30,3600'], 'dispatch_mode' => ['required', 'in:sync,queue'], 'record_unpoliced' => ['nullable', 'boolean']]);
        $wasDryRun = (bool) $s->get('dry_run', true);
        $d['dry_run'] = $r->boolean('dry_run');
        $d['record_unpoliced'] = $r->boolean('record_unpoliced');
        foreach ($d as $k => $v) {
            $s->put($k, $v);
        }$audit->record($r, 'updated', 'settings', null, null, $d);

        // P2-9: going live is the single most consequential change on this page,
        // so say plainly that it happened rather than "Settings updated."
        if ($wasDryRun && ! $d['dry_run']) {
            return back()->with('status', 'Dry-run is OFF — IAPM is now delivering real notifications to your destinations. Watch the Delivery Log for the first send.');
        }
        if (! $wasDryRun && $d['dry_run']) {
            return back()->with('status', 'Dry-run is ON — IAPM will record what it would send but contact no destination.');
        }

        return back()->with('status', 'Settings updated.');
    }

    /**
     * P0-5: the Setup Helper told operators to send
     * `Authorization: Bearer <your IAPM ingestion token>`, but the token was
     * never shown anywhere. The only way to obtain a usable value was to rotate
     * it, which breaks a working install.
     *
     * Served from its own endpoint rather than rendered into the settings and
     * setup-helper pages: both are reachable with only `view iapm`, while the
     * token itself is a `manage iapm settings` secret. Every reveal is audited.
     */
    public function revealToken(Request $r, SettingStore $s, AuditService $audit)
    {
        abort_unless($r->user()->can('manage iapm settings'), 403);
        $token = $s->get('ingestion_token');
        abort_unless(filled($token), 404, 'No ingestion token has been generated yet.');
        $audit->record($r, 'revealed_token', 'settings', null, null, ['ingestion_token' => '[REDACTED]']);

        return response()->json(['token' => $token])
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex');
    }

    public function rotateToken(Request $r, SettingStore $s, AuditService $audit)
    {
        abort_unless($r->user()->can('manage iapm settings'), 403);
        $old = $s->get('ingestion_token');
        if ($old) {
            $s->put('previous_ingestion_token', $old);
            $s->put('previous_ingestion_token_expires_at', now()->addMinutes(15)->toIso8601String());
        }$token = Str::random(64);
        $s->put('ingestion_token', $token);
        $audit->record($r, 'rotated_token', 'settings', null, null, ['ingestion_token' => '[REDACTED]', 'previous_token_expires_at' => now()->addMinutes(15)->toIso8601String()]);

        return back()->with('new_ingestion_token', $token)->with('status', 'Token rotated. The previous token remains valid for 15 minutes. Copy the new token now.');
    }
}
