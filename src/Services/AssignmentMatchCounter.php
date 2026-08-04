<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use App\Models\Port;
use Illuminate\Database\Eloquent\Builder;

/**
 * Estimates how many current interfaces a candidate assignment would match,
 * for the assignment "match preview". Deterministic assignment types use
 * indexed queries; regex types use a bounded scan so a preview never loads the
 * whole port table on a large installation.
 */
class AssignmentMatchCounter
{
    public const REGEX_SCAN_LIMIT = 5000;

    /**
     * @param  array{assignment_type:string, assignment_reference?:string|int|null, match_expression?:string|null, match_mode?:string|null, device_group_ids?:array<int|string>}  $assignment
     * @return array{count:int, capped:bool, error:string|null}
     */
    public function count(array $assignment): array
    {
        $type = $assignment['assignment_type'] ?? '';
        $reference = $assignment['assignment_reference'] ?? null;

        try {
            return match ($type) {
                'default' => $this->result($this->activePorts()->count()),
                'port' => $this->result($reference !== null ? $this->activePorts()->where('ports.port_id', (int) $reference)->count() : 0),
                'device' => $this->result($this->activePorts()->where('ports.device_id', (int) $reference)->count()),
                'interface_type' => $this->result($this->activePorts()->where('ports.ifType', (string) $reference)->count()),
                'location' => $this->result($this->activePorts()->whereHas('device', fn (Builder $q) => $q->where('location_id', (int) $reference))->count()),
                'port_group' => $this->result($this->activePorts()->whereHas('groups', fn (Builder $q) => $q->where('port_groups.id', (int) $reference))->count()),
                'device_group' => $this->result($this->deviceGroupQuery($assignment)->count()),
                'ifalias_regex', 'ifname_regex' => $this->countRegex($type, (string) ($assignment['match_expression'] ?? '')),
                default => ['count' => 0, 'capped' => false, 'error' => 'Unsupported assignment type.'],
            };
        } catch (\Throwable $e) {
            return ['count' => 0, 'capped' => false, 'error' => 'Preview could not be evaluated.'];
        }
    }

    private function activePorts(): Builder
    {
        // Mirror the alert rule: only ports that can actually raise an incident.
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

    private function countRegex(string $type, string $pattern): array
    {
        if ($pattern === '' || strlen($pattern) > 1000 || ! $this->validRegex($pattern)) {
            return ['count' => 0, 'capped' => false, 'error' => 'The regular expression is invalid.'];
        }

        $column = $type === 'ifalias_regex' ? 'ifAlias' : 'ifName';
        $count = 0;
        $scanned = 0;

        $this->activePorts()->select(['port_id', $column])->orderBy('port_id')->chunkById(1000, function ($ports) use ($pattern, $column, &$count, &$scanned): bool {
            foreach ($ports as $port) {
                $scanned++;
                if ($this->matches($pattern, (string) $port->{$column})) {
                    $count++;
                }
            }

            return $scanned < self::REGEX_SCAN_LIMIT;
        }, 'port_id');

        return ['count' => $count, 'capped' => $scanned >= self::REGEX_SCAN_LIMIT, 'error' => null];
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

    private function result(int $count): array
    {
        return ['count' => $count, 'capped' => false, 'error' => null];
    }
}
