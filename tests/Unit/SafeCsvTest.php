<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Support\SafeCsv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeCsvTest extends TestCase
{
    #[DataProvider('formulas')]
    public function test_untrusted_formula_cells_are_serialized_as_text(string $value): void
    {
        $stream = fopen('php://memory', 'w+');
        SafeCsv::write($stream, [12, $value, 'normal,"alias"', null]);
        rewind($stream);
        $row = fgetcsv($stream, 0, ',', '"', '');
        fclose($stream);

        self::assertSame(['12', "'".$value, 'normal,"alias"', ''], $row);
    }

    public static function formulas(): array
    {
        return array_map(static fn (string $value) => [$value], ['=1+1', '+SUM(1,2)', '-1+2', '@SUM(1,2)', "\t=1+1", "\r\n =1+1", '＝1+1', '＋1+1', '－1+1', '＠SUM(1,2)']);
    }
}
