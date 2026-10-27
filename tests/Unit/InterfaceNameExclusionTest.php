<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceNameExclusion;
use PHPUnit\Framework\TestCase;

class InterfaceNameExclusionTest extends TestCase
{
    public function test_normalize_trims_and_drops_blank_and_case_only_duplicates(): void
    {
        self::assertSame(['kundeport', 'Uplink'], InterfaceNameExclusion::normalize([' kundeport ', '', 'KUNDEPORT', "\t", 'Uplink']));
        self::assertSame(['a', 5], InterfaceNameExclusion::normalize(['a', 5]), 'non-strings are left for validation to reject');
    }

    public function test_matching_is_a_case_insensitive_substring_of_any_name(): void
    {
        $patterns = InterfaceNameExclusion::prepare(['Kundeport', 'kundeport', '', 7]);
        self::assertSame(['kundeport'], $patterns);
        self::assertTrue(InterfaceNameExclusion::matches($patterns, 'xe-0/0/1', 'KUNDEPORT: Acme AS', ''));
        self::assertTrue(InterfaceNameExclusion::matches($patterns, 'ge-1/kundeport-12'));
        self::assertTrue(InterfaceNameExclusion::matches(InterfaceNameExclusion::prepare(['Ærlig']), 'ÆRLIG kunde'));
        self::assertFalse(InterfaceNameExclusion::matches($patterns, 'xe-0/0/1', 'Uplink core', 'Kunde port'));
        self::assertFalse(InterfaceNameExclusion::matches([], 'kundeport'));
    }
}
