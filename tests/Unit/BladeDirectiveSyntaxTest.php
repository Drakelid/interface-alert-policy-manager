<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Static guard for the defect class behind P0-1.
 *
 * Blade matches directives with `\B@(\w+)`, so an `@` immediately preceded by a
 * word character is NOT recognised as a directive and is emitted verbatim into
 * the response. `@endforeach@else` is the trap: both branches of the conditional
 * then render and the literal text `@else` reaches the browser.
 *
 * This runs in the unit suite so the whole view tree is checked without booting
 * LibreNMS; RouteSmokeTest additionally asserts on real rendered output.
 */
class BladeDirectiveSyntaxTest extends TestCase
{
    /**
     * Control-flow and output directives. A stray one of these mid-template is
     * always a bug — unlike, say, an e-mail address in help copy.
     */
    private const DIRECTIVES = [
        'if', 'else', 'elseif', 'endif', 'unless', 'endunless',
        'foreach', 'endforeach', 'forelse', 'empty', 'endforelse',
        'for', 'endfor', 'while', 'endwhile',
        'switch', 'case', 'break', 'continue', 'default', 'endswitch',
        'isset', 'endisset', 'php', 'endphp', 'verbatim', 'endverbatim',
        'section', 'endsection', 'yield', 'extends', 'include', 'csrf', 'method',
        'json', 'error', 'enderror', 'checked', 'selected', 'disabled', 'inject',
        'can', 'elsecan', 'endcan', 'cannot', 'elsecannot', 'endcannot',
        'canany', 'endcanany',
    ];

    public function test_no_blade_directive_is_preceded_by_a_word_character(): void
    {
        $pattern = '/\w@('.implode('|', self::DIRECTIVES).')\b/';
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            foreach (file($file) as $number => $line) {
                // `@@foreach` is the documented escape for a literal `@foreach`,
                // and `{{-- --}}` comments are stripped before compilation.
                $scannable = str_replace('@@', '', $line);
                if (preg_match($pattern, $scannable, $m)) {
                    $offenders[] = sprintf('%s:%d contains "%s"', basename($file), $number + 1, $m[0]);
                }
            }
        }

        self::assertSame([], $offenders, "Blade will emit these directives as literal text instead of compiling them:\n".implode("\n", $offenders));
    }

    public function test_the_guard_detects_the_original_policy_test_defect(): void
    {
        // Regression anchor: proves the assertion above would have failed on the
        // exact markup that shipped in 1.3.2, rather than passing vacuously.
        $shipped = '<td>@if(count($d[\'receivers\']))@foreach($d[\'receivers\'] as $rcv)<span>x</span> @endforeach@else<span>no receiver</span>@endif</td>';

        self::assertMatchesRegularExpression('/\w@('.implode('|', self::DIRECTIVES).')\b/', $shipped);
    }

    public function test_the_guard_detects_an_inline_authorization_directive(): void
    {
        $shipped = 'Open the preview page@can(\'manage iapm settings\') settings @endcan';

        self::assertMatchesRegularExpression('/\w@('.implode('|', self::DIRECTIVES).')\b/', $shipped);
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $root = dirname(__DIR__, 2).'/resources/views';
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        self::assertNotEmpty($files, 'No Blade views were found to scan.');

        return $files;
    }
}
