<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use InvalidArgumentException;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SmsContentFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmsContentFilterTest extends TestCase
{
    #[DataProvider('messages')]
    public function test_configured_filters_remove_noise_and_preserve_useful_text(string $input, string $expected): void
    {
        $filter = new SmsContentFilter($this->createStub(SettingStore::class));

        self::assertSame($expected, $filter->filterWith($input, SmsContentFilter::DEFAULT_PHRASES, SmsContentFilter::DEFAULT_SYMBOLS));
    }

    public static function messages(): array
    {
        return [
            'hash decoration' => ['### Customer access switch ###', 'Customer access switch'],
            'bundle to' => ['### Bundle to Oslo distribution switch ###', 'Oslo distribution switch'],
            'bundle ether' => ['Bundle-Ether10 to Stockholm core', 'Stockholm core'],
            'bundle ethernet' => ['BUNDLE_ETHERNET20 to Customer A', 'Customer A'],
            'bundle eth' => ['Bundle Eth12: Backup uplink', 'Backup uplink'],
            'spaced bundle number' => ['Bundle-Ethernet 20 to Customer A', 'Customer A'],
            'hash within text' => ['Customer #42 primary', 'Customer 42 primary'],
            'word boundary' => ['Bundled together for Customer A', 'Bundled together for Customer A'],
            'configured wildcard prefix' => ['Bundle-EthernetConnection customer uplink', 'customer uplink'],
            'multiline' => ["Port: Bundle-Ether10\nDescription: ### Bundle to Oslo ###", "Port:\nDescription: Oslo"],
        ];
    }

    public function test_custom_filters_are_case_insensitive_and_symbols_are_exact(): void
    {
        $filter = new SmsContentFilter($this->createStub(SettingStore::class));

        self::assertSame('Keep useful', $filter->filterWith('REMOVE me @@ Keep useful', ['remove me'], ['@@']));
    }

    public function test_filter_lists_are_trimmed_deduplicated_and_allow_a_trailing_wildcard(): void
    {
        $filter = new SmsContentFilter($this->createStub(SettingStore::class));

        self::assertSame(['Noise', 'Bundle*'], $filter->parseLines(" Noise \nnoise\n\nBundle*"));
    }

    public function test_an_asterisk_is_only_allowed_once_at_the_end(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SmsContentFilter($this->createStub(SettingStore::class)))->parseLines('Bun*dle');
    }
}
