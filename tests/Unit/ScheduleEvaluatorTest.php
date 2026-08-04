<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ScheduleEvaluator;
use PHPUnit\Framework\TestCase;

class ScheduleEvaluatorTest extends TestCase
{
    public function test_a_policy_without_a_schedule_always_notifies(): void
    {
        self::assertTrue((new ScheduleEvaluator)->permits(null));
    }

    public function test_a_disabled_schedule_never_permits(): void
    {
        self::assertFalse((new ScheduleEvaluator)->permits($this->schedule(['mode' => 'always'], enabled: false)));
    }

    public function test_the_always_mode_permits_at_any_time(): void
    {
        self::assertTrue((new ScheduleEvaluator)->permits($this->schedule(['mode' => 'always']), CarbonImmutable::parse('2026-07-11 03:14:00', 'UTC')));
    }

    public function test_business_hours_permit_only_inside_the_window(): void
    {
        $schedule = $this->schedule(['mode' => 'business_hours', 'days' => ['fri' => [['start' => '08:00', 'end' => '16:00']]]]);
        $evaluator = new ScheduleEvaluator;

        // 2026-07-10 is a Friday.
        self::assertTrue($evaluator->permits($schedule, CarbonImmutable::parse('2026-07-10 08:00:00', 'UTC')));
        self::assertTrue($evaluator->permits($schedule, CarbonImmutable::parse('2026-07-10 15:59:00', 'UTC')));
        self::assertFalse($evaluator->permits($schedule, CarbonImmutable::parse('2026-07-10 16:00:00', 'UTC')), 'The end of the window is exclusive.');
        self::assertFalse($evaluator->permits($schedule, CarbonImmutable::parse('2026-07-10 07:59:00', 'UTC')));
        self::assertFalse($evaluator->permits($schedule, CarbonImmutable::parse('2026-07-11 12:00:00', 'UTC')), 'Saturday has no window.');
    }

    public function test_after_hours_inverts_the_window(): void
    {
        $schedule = $this->schedule(['mode' => 'after_hours', 'days' => ['fri' => [['start' => '08:00', 'end' => '16:00']]]]);
        $evaluator = new ScheduleEvaluator;

        self::assertFalse($evaluator->permits($schedule, CarbonImmutable::parse('2026-07-10 09:00:00', 'UTC')));
        self::assertTrue($evaluator->permits($schedule, CarbonImmutable::parse('2026-07-10 22:00:00', 'UTC')));
    }

    public function test_a_window_crossing_midnight_is_handled(): void
    {
        $schedule = $this->schedule(['mode' => 'custom', 'days' => ['fri' => [['start' => '22:00', 'end' => '02:00']]]]);
        $evaluator = new ScheduleEvaluator;

        self::assertTrue($evaluator->permits($schedule, CarbonImmutable::parse('2026-07-10 23:30:00', 'UTC')));
        self::assertTrue($evaluator->permits($schedule, CarbonImmutable::parse('2026-07-10 01:00:00', 'UTC')));
        self::assertFalse($evaluator->permits($schedule, CarbonImmutable::parse('2026-07-10 12:00:00', 'UTC')));
    }

    public function test_the_schedule_timezone_is_applied(): void
    {
        $schedule = $this->schedule(['mode' => 'custom', 'days' => ['fri' => [['start' => '08:00', 'end' => '16:00']]]], timezone: 'Europe/Oslo');

        // 07:00 UTC on Friday is 09:00 in Oslo, inside the window.
        self::assertTrue((new ScheduleEvaluator)->permits($schedule, CarbonImmutable::parse('2026-07-10 07:00:00', 'UTC')));
        self::assertFalse((new ScheduleEvaluator)->permits($schedule, CarbonImmutable::parse('2026-07-10 05:00:00', 'UTC')));
    }

    public function test_a_malformed_period_never_matches(): void
    {
        $schedule = $this->schedule(['mode' => 'custom', 'days' => ['fri' => [['start' => 'lunchtime', 'end' => '16:00']]]]);

        self::assertFalse((new ScheduleEvaluator)->permits($schedule, CarbonImmutable::parse('2026-07-10 12:00:00', 'UTC')));
    }

    public function test_validation_rejects_an_unknown_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ScheduleEvaluator)->validate(['mode' => 'whenever']);
    }

    public function test_validation_rejects_an_unknown_day(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ScheduleEvaluator)->validate(['mode' => 'custom', 'days' => ['funday' => []]]);
    }

    public function test_validation_rejects_a_malformed_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ScheduleEvaluator)->validate(['mode' => 'custom', 'days' => ['mon' => [['start' => '8:00', 'end' => '16:00']]]]);
    }

    public function test_validation_accepts_a_well_formed_definition(): void
    {
        $definition = ['mode' => 'business_hours', 'days' => ['mon' => [['start' => '08:00', 'end' => '16:00']]]];

        self::assertSame($definition, (new ScheduleEvaluator)->validate($definition));
    }

    private function schedule(array $definition, bool $enabled = true, string $timezone = 'UTC'): Schedule
    {
        $schedule = new Schedule;
        $schedule->setRawAttributes(['name' => 'Test', 'timezone' => $timezone, 'enabled' => $enabled, 'schedule_json' => json_encode($definition)]);

        return $schedule;
    }
}
