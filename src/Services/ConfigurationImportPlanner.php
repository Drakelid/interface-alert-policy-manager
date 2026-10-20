<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;

/**
 * Works out what an import would do, so the operator can see it before anything
 * is written (P1-8).
 *
 * Import used to be create-only and silent: an item whose name already existed
 * was skipped with no explanation, which makes "promotion between installs" —
 * the page's stated purpose — impossible, because the second promotion of a
 * changed policy does nothing.
 *
 * plan() is pure with respect to the database (it only reads), and apply() runs
 * the plan it produces, so the preview cannot disagree with the outcome.
 *
 * Update semantics are deliberately non-destructive: an existing policy has its
 * own fields updated, its matching actions and assignments updated, and any it
 * does not carry are created. Actions and assignments that exist locally but are
 * absent from the document are left alone rather than deleted — an import should
 * not silently remove alerting that the document simply does not mention.
 */
class ConfigurationImportPlanner
{
    private const EXCLUDED = ['created_by', 'updated_by', 'policy_id', 'destination_id'];

    /**
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function plan(array $document, bool $updateExisting): array
    {
        $items = [];
        $existingPolicies = Policy::with(['actions', 'assignments.deviceGroups'])
            ->whereIn('name', array_column($document['policies'] ?? [], 'name'))->get()->keyBy('name');
        $destinations = Destination::pluck('id', 'name');
        foreach ((array) ($document['policies'] ?? []) as $policy) {
            $name = (string) ($policy['name'] ?? '');
            $existing = $existingPolicies->get($name);
            $decision = $this->decide('policy', $name, $existing !== null, $updateExisting);
            $decision['target_id'] = $existing?->id;
            $items[] = $decision;
            // Consume each row in the pre-import snapshot at most once. Rows
            // created earlier in this document must never become update targets.
            $actions = [];
            $assignments = [];
            foreach ($existing?->actions->sortBy('id') ?? [] as $action) {
                $actions[$this->actionKey($action->toArray(), $action->destination_id)][] = $action->id;
            }
            foreach ($existing?->assignments->sortBy('id') ?? [] as $assignment) {
                $row = $assignment->toArray();
                $row['device_group_ids'] = $assignment->deviceGroups->pluck('device_group_id')->all();
                $assignments[$this->assignmentKey($row)][] = $assignment->id;
            }

            // Children of a skipped policy cannot be written; say so explicitly
            // rather than leaving them out of the report entirely.
            $parentSkipped = $decision['action'] === 'skip';

            foreach ((array) ($policy['actions'] ?? []) as $action) {
                $label = sprintf('%s → %s', $action['phase'] ?? '?', $action['destination'] ?? '?');
                if ($parentSkipped) {
                    $items[] = $this->item('action', $label, 'skip', 'the policy it belongs to is being skipped', $name);

                    continue;
                }
                $key = $this->actionKey($action, $destinations[$action['destination'] ?? ''] ?? null);
                $targetId = empty($actions[$key]) ? null : array_shift($actions[$key]);
                $item = $targetId
                    ? $this->item('action', $label, 'update', 'an action with the same phase, destination and sort order exists', $name)
                    : $this->item('action', $label, 'create', $existing ? 'no matching action on the existing policy' : 'new policy', $name);
                $item['target_id'] = $targetId;
                $items[] = $item;
            }

            foreach ((array) ($policy['assignments'] ?? []) as $assignment) {
                $label = $this->assignmentLabel($assignment);
                if ($parentSkipped) {
                    $items[] = $this->item('assignment', $label, 'skip', 'the policy it belongs to is being skipped', $name);

                    continue;
                }
                $key = $this->assignmentKey($assignment);
                $targetId = empty($assignments[$key]) ? null : array_shift($assignments[$key]);
                $item = $targetId
                    ? $this->item('assignment', $label, 'update', 'an assignment with the same scope and match mode exists', $name)
                    : $this->item('assignment', $label, 'create', $existing ? 'no matching assignment on the existing policy' : 'new policy', $name);
                $item['target_id'] = $targetId;
                $items[] = $item;
            }
        }

        return ['items' => $items, 'counts' => $this->counts($items)];
    }

    /**
     * Executes the plan. Caller is responsible for the transaction.
     *
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function apply(array $document, bool $updateExisting, ?int $userId): array
    {
        $plan = $this->plan($document, $updateExisting);
        $destinations = Destination::pluck('id', 'name');
        $offset = 0;

        foreach ((array) ($document['policies'] ?? []) as $policy) {
            $decision = $plan['items'][$offset++];
            if ($decision['action'] === 'skip') {
                $offset += count($policy['actions'] ?? []) + count($policy['assignments'] ?? []);

                continue;
            }
            $existing = $decision['target_id'] ? Policy::findOrFail($decision['target_id']) : null;

            $attributes = $this->fillable($policy, new Policy) + [
                'updated_by' => $userId,
            ];

            if ($existing === null) {
                $target = Policy::create($attributes + ['created_by' => $userId]);
            } else {
                $existing->update($attributes);
                $target = $existing;
            }

            foreach ((array) ($policy['actions'] ?? []) as $action) {
                $decision = $plan['items'][$offset++];
                $destinationId = $destinations[$action['destination'] ?? ''] ?? null;
                if (! $destinationId) {
                    // The validator rejects unknown destinations before we get
                    // here, so this can only mean the document changed underneath.
                    throw new \LogicException('Validated destination disappeared during import.');
                }
                $attributes = $this->fillable($action, new PolicyAction) + ['destination_id' => $destinationId, 'policy_id' => $target->id];
                $match = $decision['target_id'] ? $target->actions()->findOrFail($decision['target_id']) : null;
                $match ? $match->update($attributes) : $target->actions()->create($attributes);
            }

            foreach ((array) ($policy['assignments'] ?? []) as $assignment) {
                $decision = $plan['items'][$offset++];
                $attributes = $this->fillable($assignment, new Assignment) + ['policy_id' => $target->id];
                $match = $decision['target_id'] ? $target->assignments()->findOrFail($decision['target_id']) : null;
                if ($match) {
                    $match->update($attributes);
                    $record = $match;
                    $record->deviceGroups()->delete();
                } else {
                    $record = $target->assignments()->create($attributes);
                }
                foreach ((array) ($assignment['device_group_ids'] ?? []) as $groupId) {
                    $record->deviceGroups()->create([
                        'device_group_id' => (int) $groupId,
                        'inclusion_mode' => ($assignment['match_mode'] ?? 'any') === 'exclude' ? 'exclude' : 'include',
                    ]);
                }
            }
        }

        return $plan;
    }

    /** Count the enabled regex topology after the exact non-destructive plan. */
    public function projectedRegexCounts(array $document, bool $updateExisting): array
    {
        $types = ['ifalias_regex', 'ifname_regex'];
        $counts = array_fill_keys($types, 0);
        foreach ($types as $type) {
            $counts[$type] = Assignment::where('assignment_type', $type)->where('enabled', true)
                ->whereHas('policy', fn ($query) => $query->where('enabled', true))->count();
        }
        $plan = $this->plan($document, $updateExisting);
        $existingPolicies = Policy::with('assignments')->whereIn('name', array_column($document['policies'] ?? [], 'name'))->get()->keyBy('name');
        $offset = 0;
        foreach ($document['policies'] ?? [] as $policy) {
            $decision = $plan['items'][$offset++];
            $offset += count($policy['actions'] ?? []);
            if ($decision['action'] === 'skip') {
                $offset += count($policy['assignments'] ?? []);

                continue;
            }
            $existing = $existingPolicies->get($policy['name']);
            $rows = [];
            foreach ($existing->assignments ?? [] as $assignment) {
                $type = $assignment->assignment_type->value;
                $rows[$assignment->id] = ['assignment_type' => $type, 'enabled' => $assignment->enabled];
                if ($existing->enabled && $assignment->enabled && isset($counts[$type])) {
                    $counts[$type]--;
                }
            }
            foreach ($policy['assignments'] ?? [] as $index => $assignment) {
                $decision = $plan['items'][$offset++];
                $rows[$decision['target_id'] ?? 'new-'.$index] = $assignment;
            }
            if ($policy['enabled']) {
                foreach ($rows as $row) {
                    if ($row['enabled'] && isset($counts[$row['assignment_type']])) {
                        $counts[$row['assignment_type']]++;
                    }
                }
            }
        }

        return $counts;
    }

    private function actionKey(array $action, mixed $destinationId): string
    {
        return json_encode([(string) $action['phase'], (int) $destinationId, (int) ($action['sort_order'] ?? 0)], JSON_THROW_ON_ERROR);
    }

    private function assignmentKey(array $assignment): string
    {
        $groups = $assignment['assignment_type'] === 'device_group'
            ? collect($assignment['device_group_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all()
            : [];

        return json_encode([
            $assignment['assignment_type'],
            isset($assignment['assignment_reference']) ? (string) $assignment['assignment_reference'] : null,
            $assignment['match_expression'] ?? null,
            $assignment['match_mode'] ?? 'any',
            $groups,
        ], JSON_THROW_ON_ERROR);
    }

    private function assignmentLabel(array $assignment): string
    {
        $type = (string) ($assignment['assignment_type'] ?? '?');
        $reference = $assignment['assignment_reference'] ?? $assignment['match_expression'] ?? null;

        return $reference ? "$type: $reference" : $type;
    }

    /** @return array<string, mixed> */
    private function decide(string $type, string $name, bool $exists, bool $updateExisting): array
    {
        if (! $exists) {
            return $this->item($type, $name, 'create', 'does not exist here yet');
        }

        return $updateExisting
            ? $this->item($type, $name, 'update', 'already exists and updating is enabled')
            : $this->item($type, $name, 'skip', 'already exists — enable "update existing items" to overwrite it');
    }

    /** @return array<string, mixed> */
    private function item(string $type, string $name, string $action, string $reason, ?string $parent = null): array
    {
        return ['type' => $type, 'name' => $name, 'action' => $action, 'reason' => $reason, 'parent' => $parent];
    }

    /** @return array<string, int> */
    private function counts(array $items): array
    {
        $counts = ['create' => 0, 'update' => 0, 'skip' => 0];
        foreach ($items as $item) {
            $counts[$item['action']]++;
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    private function fillable(array $row, $model): array
    {
        return collect($row)->only($model->getFillable())->except(self::EXCLUDED)->all();
    }
}
