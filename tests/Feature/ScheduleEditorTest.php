<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Carbon\CarbonImmutable;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ScheduleEvaluator;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P1-5: creating a schedule required hand-writing
 * {"mode": "...", "days": {...}} into a textarea. That is the most likely reason
 * the optional schedule step gets skipped, losing out-of-hours suppression.
 */
class ScheduleEditorTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    /** The headline requirement: end-to-end creation with no JSON anywhere. */
    public function test_a_schedule_can_be_created_without_writing_json(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/schedules', [
            'name' => 'Business hours',
            'timezone' => 'Europe/Oslo',
            'mode' => 'business_hours',
            'enabled' => '1',
            'days' => [
                'mon' => [['start' => '08:00', 'end' => '16:00']],
                'tue' => [['start' => '08:00', 'end' => '16:00']],
            ],
        ])->assertRedirect();

        $schedule = Schedule::where('name', 'Business hours')->firstOrFail();
        self::assertSame('Europe/Oslo', $schedule->timezone);
        self::assertSame('business_hours', $schedule->schedule_json['mode']);
        self::assertSame([['start' => '08:00', 'end' => '16:00']], $schedule->schedule_json['days']['mon']);
        self::assertArrayNotHasKey('wed', $schedule->schedule_json['days']);
    }

    public function test_multiple_ranges_per_day_are_kept(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/schedules', [
            'name' => 'Split shift', 'timezone' => 'UTC', 'mode' => 'custom', 'enabled' => '1',
            'days' => ['wed' => [['start' => '06:00', 'end' => '10:00'], ['start' => '18:00', 'end' => '22:00']]],
        ])->assertRedirect();

        self::assertCount(2, Schedule::where('name', 'Split shift')->firstOrFail()->schedule_json['days']['wed']);
    }

    /** A row the operator added and left blank must not block the save. */
    public function test_empty_rows_are_discarded(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/schedules', [
            'name' => 'Sparse', 'timezone' => 'UTC', 'mode' => 'business_hours', 'enabled' => '1',
            'days' => ['mon' => [['start' => '08:00', 'end' => '16:00'], ['start' => '', 'end' => '']]],
        ])->assertRedirect();

        self::assertCount(1, Schedule::where('name', 'Sparse')->firstOrFail()->schedule_json['days']['mon']);
    }

    /** A half-filled row is a mistake, not an empty row. */
    public function test_a_range_missing_one_end_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/schedules', [
            'name' => 'Broken', 'timezone' => 'UTC', 'mode' => 'business_hours', 'enabled' => '1',
            'days' => ['mon' => [['start' => '08:00', 'end' => '']]],
        ])->assertSessionHasErrors('days');

        self::assertNull(Schedule::where('name', 'Broken')->first());
    }

    public function test_a_malformed_time_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/schedules', [
            'name' => 'Bad time', 'timezone' => 'UTC', 'mode' => 'business_hours', 'enabled' => '1',
            'days' => ['mon' => [['start' => '8am', 'end' => '16:00']]],
        ])->assertSessionHasErrors();

        self::assertNull(Schedule::where('name', 'Bad time')->first());
    }

    public function test_an_unknown_weekday_key_is_ignored(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/schedules', [
            'name' => 'Odd day', 'timezone' => 'UTC', 'mode' => 'business_hours', 'enabled' => '1',
            'days' => ['funday' => [['start' => '08:00', 'end' => '16:00']], 'mon' => [['start' => '09:00', 'end' => '17:00']]],
        ])->assertRedirect();

        $days = Schedule::where('name', 'Odd day')->firstOrFail()->schedule_json['days'];
        self::assertArrayNotHasKey('funday', $days);
        self::assertArrayHasKey('mon', $days);
    }

    /** The JSON escape hatch still works, but only when explicitly chosen. */
    public function test_the_json_view_wins_only_when_advanced_mode_is_ticked(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/schedules', [
            'name' => 'From JSON', 'timezone' => 'UTC', 'enabled' => '1',
            'advanced_json' => '1',
            'schedule_json' => json_encode(['mode' => 'after_hours', 'days' => ['fri' => [['start' => '09:00', 'end' => '17:00']]]]),
            // Deliberately contradicts the JSON; must be ignored.
            'mode' => 'always',
            'days' => ['mon' => [['start' => '00:00', 'end' => '01:00']]],
        ])->assertRedirect();

        $definition = Schedule::where('name', 'From JSON')->firstOrFail()->schedule_json;
        self::assertSame('after_hours', $definition['mode']);
        self::assertArrayHasKey('fri', $definition['days']);
        self::assertArrayNotHasKey('mon', $definition['days']);
    }

    /**
     * The pre-P1-5 contract was "post schedule_json alone". A request with no
     * `mode` still takes the JSON path, so nothing that worked before breaks.
     */
    public function test_a_request_without_a_mode_still_accepts_raw_json(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/schedules', [
            'name' => 'Legacy', 'timezone' => 'UTC', 'enabled' => '1',
            'schedule_json' => json_encode(['mode' => 'always', 'days' => []]),
        ])->assertRedirect();

        self::assertSame('always', Schedule::where('name', 'Legacy')->firstOrFail()->schedule_json['mode']);
    }

    public function test_the_editor_ignores_the_json_box_by_default(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/schedules', [
            'name' => 'Editor wins', 'timezone' => 'UTC', 'mode' => 'business_hours', 'enabled' => '1',
            'days' => ['mon' => [['start' => '08:00', 'end' => '16:00']]],
            // Stale mirror of an older state; the editor is authoritative.
            'schedule_json' => json_encode(['mode' => 'always', 'days' => []]),
        ])->assertRedirect();

        self::assertSame('business_hours', Schedule::where('name', 'Editor wins')->firstOrFail()->schedule_json['mode']);
    }

    public function test_the_timezone_is_a_select_of_real_zones(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/schedules/create')->assertOk()->getContent();

        self::assertStringContainsString('<select class="form-control" id="iapm-sched-tz" name="timezone"', $body);
        self::assertStringContainsString('Europe/Oslo', $body);
    }

    public function test_the_form_renders_a_row_for_every_weekday(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/schedules/create')->assertOk()->getContent();

        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            self::assertStringContainsString('data-iapm-day="'.$day.'"', $body);
        }
    }

    /** P1-5 asks for after_hours to be explained relative to business_hours. */
    public function test_the_form_explains_after_hours_against_business_hours(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/schedules/create')->assertOk()->getContent();

        self::assertStringContainsString('exact inverse of business hours', $body);
    }

    /** The in-window indicator must reflect the evaluator, not just render. */
    public function test_the_edit_page_shows_whether_the_schedule_is_currently_open(): void
    {
        $schedule = Schedule::create([
            'name' => 'Weekday mornings', 'timezone' => 'UTC', 'enabled' => true,
            'schedule_json' => ['mode' => 'business_hours', 'days' => ['mon' => [['start' => '08:00', 'end' => '12:00']]]],
        ]);

        // Monday 09:00 UTC — inside the window.
        $this->travelTo(CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC'));
        self::assertTrue(app(ScheduleEvaluator::class)->permits($schedule));
        $open = (string) $this->actingAs($this->admin())->get(self::BASE."/schedules/{$schedule->id}/edit")->assertOk()->getContent();
        self::assertStringContainsString('data-iapm-window="open"', $open);

        // Monday 20:00 UTC — outside it.
        $this->travelTo(CarbonImmutable::parse('2026-08-10 20:00:00', 'UTC'));
        $closed = (string) $this->actingAs($this->admin())->get(self::BASE."/schedules/{$schedule->id}/edit")->assertOk()->getContent();
        self::assertStringContainsString('data-iapm-window="closed"', $closed);
    }

    /** Editing an existing schedule must round-trip through the structured form. */
    public function test_an_existing_schedule_round_trips_through_the_editor(): void
    {
        $schedule = Schedule::create([
            'name' => 'Round trip', 'timezone' => 'UTC', 'enabled' => true,
            'schedule_json' => ['mode' => 'after_hours', 'days' => ['thu' => [['start' => '07:30', 'end' => '15:45']]]],
        ]);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/schedules/{$schedule->id}/edit")->assertOk()->getContent();
        self::assertStringContainsString('name="days[thu][0][start]" value="07:30"', $body);
        self::assertStringContainsString('name="days[thu][0][end]" value="15:45"', $body);

        $this->actingAs($this->admin())->put(self::BASE."/schedules/{$schedule->id}", [
            'name' => 'Round trip', 'timezone' => 'UTC', 'mode' => 'after_hours', 'enabled' => '1',
            'days' => ['thu' => [['start' => '07:30', 'end' => '15:45']]],
        ])->assertRedirect();

        self::assertSame(
            ['mode' => 'after_hours', 'days' => ['thu' => [['start' => '07:30', 'end' => '15:45']]]],
            $schedule->fresh()->schedule_json
        );
    }
}
