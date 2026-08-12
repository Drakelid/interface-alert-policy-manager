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
    private const EXCLUDED = ['created_by', 'updated_by'];

    /**
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function plan(array $document, bool $updateExisting): array
    {
        $items = [];
        foreach ((array) ($document['policies'] ?? []) as $policy) {
            $name = (string) ($policy['name'] ?? '');
            $existing = Policy::where('name', $name)->first();
            $decision = $this->decide('policy', $name, $existing !== null, $updateExisting);
            $items[] = $decision;

            // Children of a skipped policy cannot be written; say so explicitly
            // rather than leaving them out of the report entirely.
            $parentSkipped = $decision['action'] === 'skip';

            foreach ((array) ($policy['actions'] ?? []) as $action) {
                $label = sprintf('%s → %s', $action['phase'] ?? '?', $action['destination'] ?? '?');
                if ($parentSkipped) {
                    $items[] = $this->item('action', $label, 'skip', 'the policy it belongs to is being skipped', $name);

                    continue;
                }
                $match = $existing ? $this->matchAction($existing, $action) : null;
                $items[] = $match
                    ? $this->item('action', $label, 'update', 'an action with the same phase, destination and sort order exists', $name)
                    : $this->item('action', $label, 'create', $existing ? 'no matching action on the existing policy' : 'new policy', $name);
            }

            foreach ((array) ($policy['assignments'] ?? []) as $assignment) {
                $label = $this->assignmentLabel($assignment);
                if ($parentSkipped) {
                    $items[] = $this->item('assignment', $label, 'skip', 'the policy it belongs to is being skipped', $name);

                    continue;
                }
                $match = $existing ? $this->matchAssignment($existing, $assignment) : null;
                $items[] = $match
                    ? $this->item('assignment', $label, 'update', 'an assignment with the same type and reference exists', $name)
                    : $this->item('assignment', $label, 'create', $existing ? 'no matching assignment on the existing policy' : 'new policy', $name);
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

        foreach ((array) ($document['policies'] ?? []) as $policy) {
            $name = (string) ($policy['name'] ?? '');
            $existing = Policy::where('name', $name)->first();
            if ($existing !== null && ! $updateExisting) {
                continue;
            }

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
                $destinationId = $destinations[$action['destination'] ?? ''] ?? null;
                if (! $destinationId) {
                    // The validator rejects unknown destinations before we get
                    // here, so this can only mean the document changed underneath.
                    throw new \LogicException('Validated destination disappeared during import.');
                }
                $attributes = $this->fillable($action, new PolicyAction) + ['destination_id' => $destinationId];
                $match = $this->matchAction($target, $action);
                $match ? $match->update($attributes) : $target->actions()->create($attributes);
            }

            foreach ((array) ($policy['assignments'] ?? []) as $assignment) {
                $attributes = $this->fillable($assignment, new Assignment);
                $match = $this->matchAssignment($target, $assignment);
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

    private function matchAction(Policy $policy, array $action): ?PolicyAction
    {
        $destinationId = Destination::where('name', $action['destination'] ?? '')->value('id');

        return $policy->actions()
            ->where('phase', $action['phase'] ?? '')
            ->where('destination_id', $destinationId)
            ->where('sort_order', (int) ($action['sort_order'] ?? 0))
            ->first();
    }

    private function matchAssignment(Policy $policy, array $assignment): ?Assignment
    {
        return $policy->assignments()
            ->where('assignment_type', $assignment['assignment_type'] ?? '')
            ->where('assignment_reference', $assignment['assignment_reference'] ?? null)
            ->where('match_expression', $assignment['match_expression'] ?? null)
            ->first();
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
