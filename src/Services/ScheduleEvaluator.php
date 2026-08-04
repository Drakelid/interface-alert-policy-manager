<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Carbon\CarbonImmutable;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;

class ScheduleEvaluator
{
    public function permits(?Schedule $schedule, ?CarbonImmutable $at = null): bool
    {
        if ($schedule === null) return true;
        if (! $schedule->enabled) return false;
        $at = ($at ?? CarbonImmutable::now())->setTimezone($schedule->timezone ?: config('app.timezone'));
        $definition = $schedule->schedule_json ?? [];
        $mode = $definition['mode'] ?? 'always';
        if ($mode === 'always') return true;
        $periods = $definition['days'][strtolower($at->format('D'))] ?? [];
        $inside = collect($periods)->contains(function (array $period) use ($at): bool {
            if (! isset($period['start'], $period['end']) || ! preg_match('/^\d{2}:\d{2}$/', $period['start']) || ! preg_match('/^\d{2}:\d{2}$/', $period['end'])) return false;
            $minute = ((int) $at->format('H') * 60) + (int) $at->format('i');
            [$sh, $sm] = array_map('intval', explode(':', $period['start'])); [$eh, $em] = array_map('intval', explode(':', $period['end']));
            $start = $sh * 60 + $sm; $end = $eh * 60 + $em;
            return $start <= $end ? $minute >= $start && $minute < $end : $minute >= $start || $minute < $end;
        });
        return $mode === 'after_hours' ? ! $inside : $inside;
    }

    public function validate(array $definition): array
    {
        $mode = $definition['mode'] ?? null;
        if (! in_array($mode, ['always', 'business_hours', 'after_hours', 'custom'], true)) throw new \InvalidArgumentException('Unsupported schedule mode.');
        foreach (($definition['days'] ?? []) as $day => $periods) {
            if (! in_array(strtolower((string) $day), ['mon','tue','wed','thu','fri','sat','sun'], true) || ! is_array($periods)) throw new \InvalidArgumentException('Invalid schedule day.');
            foreach ($periods as $period) if (! is_array($period) || ! isset($period['start'], $period['end']) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $period['start']) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $period['end'])) throw new \InvalidArgumentException('Schedule times must use HH:MM.');
        }
        return $definition;
    }
}
