<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\Port;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;

/** Explicit browser scopes; never registered as global model scopes or used by workers. */
class InventoryAccess
{
    public function __construct(private readonly Request $request) {}

    public function unrestricted(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->hasRole('admin') || (
            Gate::forUser($user)->allows('viewAll', Device::class)
            && Gate::forUser($user)->allows('viewAll', Port::class)
        ));
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function ports(Builder $query): Builder
    {
        if ($this->allPorts()) {
            return $query;
        }
        if (! $this->canRead('port')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q
            ->whereIn($q->qualifyColumn('device_id'), $this->deviceGrants())
            ->orWhereIn($q->qualifyColumn('port_id'), $this->portGrants()));
    }

    /**
     * Parent device labels remain available to users granted individual ports.
     *
     * @param  Builder<Device>  $query
     * @return Builder<Device>
     */
    public function devices(Builder $query): Builder
    {
        $user = $this->user();
        if ($user !== null && ($user->hasRole('admin') || Gate::forUser($user)->allows('viewAll', Device::class) || $this->allPorts())) {
            return $query;
        }
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q
            ->when($this->canRead('device'), fn (Builder $devices) => $devices->whereIn($devices->qualifyColumn('device_id'), $this->deviceGrants()), fn (Builder $devices) => $devices->whereRaw('1 = 0'))
            ->orWhereIn($q->qualifyColumn('device_id'), $this->ports(Port::query())->select('ports.device_id')));
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function records(Builder $query): Builder
    {
        if ($this->allPorts()) {
            return $query;
        }
        if (! $this->canRead('port')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q
            ->whereIn($q->qualifyColumn('device_id'), $this->deviceGrants())
            ->orWhereIn($q->qualifyColumn('port_id'), $this->ports(Port::query())->select('ports.port_id')));
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function throughIncident(Builder $query): Builder
    {
        return $this->unrestricted() ? $query : $query->whereIn($query->qualifyColumn('incident_id'), $this->records(Incident::query())->select('iapm_incidents.id'));
    }

    public function assertPort(int $id): void
    {
        abort_unless($this->ports(Port::query())->whereKey($id)->exists(), 404);
    }

    public function assertDevice(int $id): void
    {
        abort_unless($this->devices(Device::query())->whereKey($id)->exists(), 404);
    }

    public function assertIncident(int $id): void
    {
        abort_unless($this->records(Incident::query())->whereKey($id)->exists(), 404);
    }

    /** @param list<int> $ids */
    public function assertPorts(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        abort_unless($this->ports(Port::query())->whereKey($ids)->count() === count($ids), 404);
    }

    /** @param list<int> $ids */
    public function assertIncidents(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        abort_unless($this->records(Incident::query())->whereKey($ids)->count() === count($ids), 404);
    }

    private function allPorts(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->hasRole('admin') || Gate::forUser($user)->allows('viewAll', Port::class));
    }

    private function canRead(string $entity): bool
    {
        $user = $this->user();

        return $user !== null && ($user->hasRole('user') || Gate::forUser($user)->allows($entity.'.view'));
    }

    private function user(): ?User
    {
        $user = $this->request->user();

        return $user instanceof User ? $user : null;
    }

    private function portGrants(): QueryBuilder
    {
        return DB::table('ports_perms')->select('port_id')->where('user_id', $this->user()?->getAuthIdentifier());
    }

    /** Match LibreNMS PermissionsCache, including the dynamic-group opt-in. */
    private function deviceGrants(): QueryBuilder
    {
        $userId = $this->user()?->getAuthIdentifier();
        $groups = DB::table('devices_group_perms')->select('device_group_device.device_id')
            ->join('device_group_device', 'device_group_device.device_group_id', '=', 'devices_group_perms.device_group_id')
            ->where('devices_group_perms.user_id', $userId)
            ->when(! LibrenmsConfig::get('permission.device_group.allow_dynamic'), fn (QueryBuilder $q) => $q
                ->join('device_groups', 'device_groups.id', '=', 'devices_group_perms.device_group_id')
                ->where('device_groups.type', 'static'));

        return DB::table('devices_perms')->select('device_id')->where('user_id', $userId)->union($groups);
    }
}
