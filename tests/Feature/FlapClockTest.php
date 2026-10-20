<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\FlapDetector;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class FlapClockTest extends IntegrationTestCase
{
    public function test_batch_flap_detection_uses_the_application_clock_with_an_offset_database_session(): void
    {
        $policy = $this->policy(['flap_threshold' => 2, 'flap_window_seconds' => 600]);
        $incident = $this->incident($policy, $this->downPort($this->device()))->load('policy');
        foreach (range(1, 2) as $cycle) {
            $incident->events()->create(['event_type' => 'reopened', 'event_message' => 'Recent cycle.']);
        }
        $connection = DB::connection();
        $originalTimezone = $connection->selectOne('SELECT @@session.time_zone AS timezone')->timezone;
        try {
            $connection->statement("SET SESSION time_zone = '+02:00'");
            $flapper = app(FlapDetector::class);
            self::assertTrue($flapper->isFlapping($incident, $policy));
            $flapper->prime(collect([$incident]));
            self::assertTrue($flapper->shouldDampen($incident, $policy));
        } finally {
            $connection->statement('SET SESSION time_zone = ?', [$originalTimezone]);
        }
    }
}
