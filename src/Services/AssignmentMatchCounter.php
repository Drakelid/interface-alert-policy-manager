<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use App\Models\Port;
use Illuminate\Database\Eloquent\Builder;

/**
 * Estimates how many current interfaces (and how many distinct devices) a
 * candidate assignment would match, for the assignment "match preview".
 * Deterministic assignment types use indexed queries; regex types use a bounded
 * scan so a preview never loads the whole port table on a large installation.
 *
 * Returns: ['count' => interfaces, 'devices' => distinct devices, 'capped' => bool, 'error' => ?string]
 */
class AssignmentMatchCounter
{
    public const REGEX_SCAN_LIMIT = 5000;

    /**
     * @param  array{assignment_type:string, assignment_reference?:string|int|null, match_expression?:string|null, match_mode?:string|null, device_group_ids?:array<int|string>}  $assignment
     * @return array{count:int, devices:int, capped:bool, error:string|null}
     */
    public function count(array $assignment): array
    {
        $type = $assignment['assignment_type'];

        try {
            if ($type === 'ifalias_regex' || $type === 'ifname_regex') {
                return $this->countRegex($type, (string) ($assignment['match_expression'] ?? ''));
            }

            $query = $this->queryFor($type, $assignment);
            if ($query === null) {
                return $this->error('Unsupported assignment type.');
            }

            return $this->countQuery($query);
        } catch (\Throwable $e) {
            return $this->error('Preview could not be evaluated.');
        }
    }

    private function queryFor(string $type, array $assignment): ?Builder
    {
        $ref = $assignment['assignment_reference'] ?? null;

        return match ($type) {
            'default' => $this->activePorts(),
            'port' => $ref !== null ? $this->activePorts()->where('ports.port_id', (int) $ref) : $this->activePorts()->whereRaw('1 = 0'),
            'device' => $this->activePorts()->where('ports.device_id', (int) $ref),
            'interface_type' => $this->activePorts()->where('ports.ifType', (string) $ref),
            'location' => $this->activePorts()->whereHas('device', fn (Builder $q) => $q->where('location_id', (int) $ref)),
            'port_group' => $this->activePorts()->whereHas('groups', fn (Builder $q) => $q->where('port_groups.id', (int) $ref)),
            'device_group' => $this->deviceGroupQuery($assignment),
            default => null,
        };
    }

    private function activePorts(): Builder
    {
        // Mirror the alert rule's baseline: only ports that still exist.
        return Port::query()->where('ports.deleted', 0);
    }

    private function deviceGroupQuery(array $assignment): Builder
    {
        $groups = array_values(array_filter(array_map('intval', (array) ($assignment['device_group_ids'] ?? []))));
        $mode = $assignment['match_mode'] ?? 'any';

        if ($groups === []) {
            return $this->activePorts()->whereRaw('1 = 0');
        }

        return $this->activePorts()->whereHas('device', function (Builder $query) use ($groups, $mode): void {
            if ($mode === 'exclude') {
                $query->whereDoesntHave('groups', fn (Builder $g) => $g->whereIn('device_groups.id', $groups));
            } elseif ($mode === 'all') {
                foreach ($groups as $group) {
                    $query->whereHas('groups', fn (Builder $g) => $g->where('device_groups.id', $group));
                }
            } else {
                $query->whereHas('groups', fn (Builder $g) => $g->whereIn('device_groups.id', $groups));
            }
        });
    }

    private function countQuery(Builder $query): array
    {
        return [
            'count' => (clone $query)->count(),
            'devices' => (clone $query)->distinct()->count('ports.device_id'),
            'capped' => false,
            'error' => null,
        ];
    }

    private function countRegex(string $type, string $pattern): array
    {
        if ($pattern === '' || strlen($pattern) > 1000 || ! $this->validRegex($pattern)) {
            return $this->error('The regular expression is invalid.');
        }

        $column = $type === 'ifalias_regex' ? 'ifAlias' : 'ifName';
        $count = 0;
        $scanned = 0;
        $devices = [];

        $this->activePorts()->select(['port_id', 'device_id', $column])->orderBy('port_id')->chunkById(1000, function ($ports) use ($pattern, $column, &$count, &$scanned, &$devices): bool {
            foreach ($ports as $port) {
                $scanned++;
                if ($this->matches($pattern, (string) $port->{$column})) {
                    $count++;
                    $devices[$port->device_id] = true;
                }
            }

            return $scanned < self::REGEX_SCAN_LIMIT;
        }, 'port_id');

        return ['count' => $count, 'devices' => count($devices), 'capped' => $scanned >= self::REGEX_SCAN_LIMIT, 'error' => null];
    }

    private function validRegex(string $pattern): bool
    {
        set_error_handler(static fn () => true);
        try {
            return @preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    private function matches(string $pattern, string $subject): bool
    {
        set_error_handler(static fn () => true);
        try {
            return preg_match($pattern, $subject) === 1;
        } finally {
            restore_error_handler();
        }
    }

    private function error(string $message): array
    {
        return ['count' => 0, 'devices' => 0, 'capped' => false, 'error' => $message];
    }
}
