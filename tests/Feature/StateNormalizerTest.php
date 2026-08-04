<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use InvalidArgumentException;
use LibreNMS\Enum\AlertState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\StateNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Lives in the integration suite because it asserts against the alert-state
 * constants of the checked-out LibreNMS, which is the point of the normalizer.
 */
class StateNormalizerTest extends TestCase
{
    public function test_librenms_numeric_states_map_to_incident_states(): void
    {
        $normalizer = new StateNormalizer;

        self::assertSame('recovered', $normalizer->normalize(AlertState::CLEAR));
        self::assertSame('recovered', $normalizer->normalize(AlertState::RECOVERED));
        self::assertSame('active', $normalizer->normalize(AlertState::ACTIVE));
        self::assertSame('acknowledged', $normalizer->normalize(AlertState::ACKNOWLEDGED));
        self::assertSame('active', $normalizer->normalize(AlertState::WORSE));
        self::assertSame('active', $normalizer->normalize(AlertState::BETTER));
        self::assertSame('active', $normalizer->normalize(AlertState::CHANGED));
    }

    public function test_textual_states_are_accepted_case_insensitively(): void
    {
        $normalizer = new StateNormalizer;

        self::assertSame('active', $normalizer->normalize('Active'));
        self::assertSame('active', $normalizer->normalize(' FIRING '));
        self::assertSame('recovered', $normalizer->normalize('Resolved'));
        self::assertSame('recovered', $normalizer->normalize('ok'));
        self::assertSame('acknowledged', $normalizer->normalize('ACK'));
    }

    public function test_an_unknown_state_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StateNormalizer)->normalize('exploded');
    }

    public function test_an_out_of_range_numeric_state_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StateNormalizer)->normalize(99);
    }
}
