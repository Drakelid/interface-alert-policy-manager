<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceDescriptionCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InterfaceDescriptionCleanerTest extends TestCase
{
    #[DataProvider('descriptions')]
    public function test_it_removes_bundle_decoration_and_preserves_useful_text(string $input, string $expected): void
    {
        self::assertSame($expected, (new InterfaceDescriptionCleaner)->clean($input));
    }

    public static function descriptions(): array
    {
        return [
            'hash decoration' => ['### Customer access switch ###', 'Customer access switch'],
            'bundle to' => ['### Bundle to Oslo distribution switch ###', 'Oslo distribution switch'],
            'bundle ether' => ['Bundle-Ether10 to Stockholm core', 'Stockholm core'],
            'bundle ethernet' => ['BUNDLE_ETHERNET 20 to Customer A', 'Customer A'],
            'bundle eth' => ['Bundle Eth12: Backup uplink', 'Backup uplink'],
            'hash inside useful text' => ['Customer #42 primary', 'Customer 42 primary'],
            'unrelated text' => ['Primary customer uplink', 'Primary customer uplink'],
            'unrelated bundle word' => ['Bundle-EthernetConnection customer uplink', 'Bundle-EthernetConnection customer uplink'],
            'decoration only' => ['### Bundle-Ether10 ###', ''],
        ];
    }
}
