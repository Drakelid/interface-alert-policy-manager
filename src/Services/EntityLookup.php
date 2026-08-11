<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use App\Models\Device;
use App\Models\Port;
use App\Models\User;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;

/**
 * Name-for-id lookups behind the type-ahead pickers (P1-2).
 *
 * The administration screens used to demand internal primary keys in free-text
 * boxes for things the application already knows the name of. Sets small enough
 * to enumerate (destinations, device groups, locations) are plain selects in the
 * views; the ones that are not — devices, ports, users, incidents — are searched
 * through here so no page ever renders a hundred thousand options.
 *
 * Every method returns `[['id' => int, 'label' => string], ...]`, which is the
 * shape the shared type-ahead script consumes.
 */
class EntityLookup
{
    /** Type-aheads are advisory; a wide result list helps nobody. */
    public const LIMIT = 20;

    /** @return list<array{id:int,label:string}> */
    public function devices(string $term): array
    {
        return Device::query()
            ->where(fn ($q) => $q->where('hostname', 'like', $this->like($term))->orWhere('sysName', 'like', $this->like($term)))
            ->orderBy('hostname')
            ->limit(self::LIMIT)
            ->get(['device_id', 'hostname', 'sysName'])
            ->map(fn ($d) => ['id' => (int) $d->device_id, 'label' => $this->deviceLabel($d)])
            ->all();
    }

    /** @return list<array{id:int,label:string}> */
    public function ports(string $term, ?int $deviceId = null): array
    {
        return Port::query()
            ->with('device:device_id,hostname')
            ->when($deviceId, fn ($q) => $q->where('ports.device_id', $deviceId))
            ->where(function ($q) use ($term): void {
                $q->where('ifName', 'like', $this->like($term))
                    ->orWhere('ifAlias', 'like', $this->like($term))
                    ->orWhere('ifDescr', 'like', $this->like($term))
                    ->orWhereHas('device', fn ($d) => $d->where('hostname', 'like', $this->like($term)));
            })
            ->orderBy('ports.device_id')
            ->orderBy('ifName')
            ->limit(self::LIMIT)
            ->get(['port_id', 'device_id', 'ifName', 'ifAlias'])
            ->map(fn ($p) => ['id' => (int) $p->port_id, 'label' => $this->portLabel($p)])
            ->all();
    }

    /**
     * The port_id stays visible in the label: operators still need it for the
     * tools that take a raw id, and it disambiguates like-named interfaces on
     * different devices.
     */
    public function portLabel(Port $port): string
    {
        // instanceof rather than ?->: Larastan types the relation as non-null, but
        // a port whose device row has gone should degrade to the id, not fatal.
        $hostname = $port->device instanceof Device ? $port->device->hostname : 'device '.$port->device_id;

        return trim(sprintf('%s — %s%s [%d]', $hostname, $port->ifName, $port->ifAlias ? ' — '.$port->ifAlias : '', $port->port_id));
    }

    /** @return list<array{id:int,label:string}> */
    public function users(string $term): array
    {
        return User::query()
            ->where(fn ($q) => $q->where('username', 'like', $this->like($term))->orWhere('realname', 'like', $this->like($term)))
            ->orderBy('username')
            ->limit(self::LIMIT)
            ->get(['user_id', 'username', 'realname'])
            ->map(fn ($u) => ['id' => (int) $u->user_id, 'label' => $u->realname ? "$u->username ($u->realname)" : (string) $u->username])
            ->all();
    }

    /** @return list<array{id:int,label:string}> */
    public function incidents(string $term): array
    {
        return Incident::query()
            ->when(ctype_digit($term), fn ($q) => $q->where('id', 'like', $term.'%'))
            ->when(! ctype_digit($term), fn ($q) => $q->whereIn('device_id', Device::where('hostname', 'like', $this->like($term))->select('device_id')))
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'device_id', 'port_id', 'state', 'context_json'])
            ->map(function (Incident $incident) {
                $context = (array) $incident->context_json;

                return [
                    'id' => (int) $incident->id,
                    'label' => sprintf('#%d — %s / %s (%s)', $incident->id, $context['hostname'] ?? 'device '.$incident->device_id, $context['ifName'] ?? 'port '.$incident->port_id, $incident->state->value),
                ];
            })
            ->all();
    }

    public function deviceLabel(Device $device): string
    {
        return $device->hostname.($device->sysName && $device->sysName !== $device->hostname ? ' ('.$device->sysName.')' : '');
    }

    /** Escapes the LIKE wildcards so a term containing % or _ cannot widen the search. */
    private function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
    }
}
