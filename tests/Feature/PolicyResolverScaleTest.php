<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\Port;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class PolicyResolverScaleTest extends IntegrationTestCase
{
    public function test_resolver_query_growth_is_bounded_for_five_hundred_ports_and_five_thousand_assignments(): void
    {
        $policy = $this->policy();
        $device = $this->device();
        Port::factory()->count(500)->create([
            'device_id' => $device->device_id,
            'ifType' => 'ethernetCsmacd',
            'ifAdminStatus' => 'up',
            'ifOperStatus' => 'down',
            'ignore' => 0,
            'disabled' => 0,
            'deleted' => 0,
        ]);

        $now = now();
        $rows = [];
        $types = ['port', 'device', 'location', 'interface_type', 'ifname_regex'];
        for ($index = 0; $index < 4999; $index++) {
            $type = $types[$index % count($types)];
            $rows[] = [
                'policy_id' => $policy->id,
                'assignment_type' => $type,
                'assignment_reference' => match ($type) {
                    'port' => (string) (900000000 + $index),
                    'device' => (string) ($device->device_id + $index + 1),
                    'location' => (string) (900000000 + $index),
                    'interface_type' => 'ethernetCsmacd',
                    default => null,
                },
                'match_expression' => $type === 'ifname_regex' ? '/^never-match-'.$index.'$/' : null,
                'match_mode' => 'any',
                'priority' => $index % 100,
                'enabled' => true,
                'metadata_json' => json_encode(['receivers' => ['scale-'.$index]]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $rows[] = [
            'policy_id' => $policy->id,
            'assignment_type' => 'default',
            'assignment_reference' => null,
            'match_expression' => null,
            'match_mode' => 'any',
            'priority' => 0,
            'enabled' => true,
            'metadata_json' => json_encode(['receivers' => ['scale-default']]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('iapm_assignments')->insert($chunk);
        }

        $ports = Port::query()->where('device_id', $device->device_id)->with(['device.location', 'device.groups', 'groups'])->get();
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $contexts = app(InterfaceContextService::class);
        $resolver = app(PolicyResolver::class);
        $started = microtime(true);
        foreach ($ports as $port) {
            $resolution = $resolver->resolve($contexts->forPort($port), writeCache: false);
            self::assertSame($policy->id, $resolution->policy?->id);
        }
        $elapsedMs = (microtime(true) - $started) * 1000;

        self::assertCount(500, $ports);
        self::assertLessThanOrEqual(10, $queries, "Resolver issued {$queries} queries for 500 ports.");
        self::assertLessThan(2000, $elapsedMs, sprintf('Resolver took %.1f ms for 500 ports / 5,000 assignments.', $elapsedMs));
    }
}
