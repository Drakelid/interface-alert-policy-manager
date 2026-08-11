<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Static guards for the two rendering properties the audit asked to be checked
 * at 1366px and at 900px or less, in both themes.
 *
 * These cannot replace looking at the pages in a browser, and are not meant to:
 * they pin the specific mistakes that break narrow layouts and dark mode, so a
 * later edit cannot silently reintroduce one.
 */
class ResponsiveAndThemeTest extends TestCase
{
    private function stylesheet(): string
    {
        $assets = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/partials/assets.blade.php');
        preg_match('#<style>(.*?)</style>#s', $assets, $matches);
        self::assertNotEmpty($matches, 'No stylesheet found in the assets partial.');

        return $matches[1];
    }

    /**
     * A fixed `width` on a layout container cannot shrink, so it forces a
     * horizontal scrollbar on the whole page at narrow widths. max-width can.
     */
    public function test_no_layout_rule_uses_a_fixed_pixel_width(): void
    {
        $offenders = [];
        foreach (explode("\n", $this->stylesheet()) as $number => $line) {
            if (! preg_match('/^\s*\.iapm-[\w-]+[^{]*\{[^}]*[^-]width:\s*\d+px/', $line)) {
                continue;
            }
            // A fixed width paired with a max-width still shrinks, which is the
            // property that matters. Only an unbounded one is a problem.
            if (preg_match('/max-width:\s*(\d+px|\d+vw|\d+%)/', $line)) {
                continue;
            }
            $offenders[] = 'line '.($number + 1).': '.trim($line);
        }

        self::assertSame([], $offenders, "Fixed-width layout rules cannot shrink below 900px:\n".implode("\n", $offenders));
    }

    /**
     * Grids must be able to reflow. Every multi-column grid the plugin defines
     * uses auto-fit with a minmax floor, so it wraps rather than overflowing.
     */
    public function test_multi_column_grids_reflow(): void
    {
        $css = $this->stylesheet();
        preg_match_all('/grid-template-columns:([^;]+);/', $css, $matches);

        self::assertNotEmpty($matches[1], 'No grids defined.');
        foreach ($matches[1] as $declaration) {
            self::assertStringContainsString('auto-fit', $declaration, "Grid `$declaration` cannot reflow at narrow widths.");
            self::assertStringContainsString('minmax', $declaration, "Grid `$declaration` has no minimum column width.");
        }
    }

    /**
     * LibreNMS toggles dark mode with a .dark class on <html>. Any colour the
     * plugin hard-codes must therefore have a dark counterpart, or it will be
     * unreadable in one of the two themes.
     */
    public function test_every_hard_coded_text_colour_has_a_dark_counterpart(): void
    {
        $css = $this->stylesheet();
        $missing = [];

        // Selectors that set `color:` outside a .dark rule and outside a var().
        preg_match_all('/^\s*(\.[\w.\s>-]+)\s*\{[^}]*\bcolor:\s*(#[0-9a-f]{3,6})/mi', $css, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $selector, $colour]) {
            $selector = trim($selector);
            if (str_starts_with($selector, '.dark')) {
                continue;
            }
            if (! str_contains($css, '.dark '.$selector.' {')) {
                $missing[] = "$selector sets $colour with no .dark override";
            }
        }

        self::assertSame([], $missing, implode("\n", $missing));
    }

    /** Backgrounds are as theme-sensitive as text colours. */
    public function test_every_hard_coded_background_has_a_dark_counterpart(): void
    {
        $css = $this->stylesheet();
        $missing = [];

        preg_match_all('/^\s*(\.[\w.\s>-]+)\s*\{[^}]*\bbackground:\s*(rgba?\([^)]*\)|#[0-9a-f]{3,6})/mi', $css, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $selector, $colour]) {
            $selector = trim($selector);
            if (str_starts_with($selector, '.dark')) {
                continue;
            }
            if (! str_contains($css, '.dark '.$selector.' {')) {
                $missing[] = "$selector sets background $colour with no .dark override";
            }
        }

        self::assertSame([], $missing, implode("\n", $missing));
    }

    /** Wide tables must scroll inside their own container, not the page. */
    public function test_wide_tables_are_wrapped_in_a_scroll_container(): void
    {
        $css = $this->stylesheet();
        self::assertMatchesRegularExpression('/\.iapm-table-wrap \{[^}]*overflow-x:\s*auto/', $css);

        $offenders = [];
        foreach (glob(dirname(__DIR__, 2).'/resources/views/**/*.blade.php') + glob(dirname(__DIR__, 2).'/resources/views/*.blade.php') as $file) {
            $contents = (string) file_get_contents($file);
            // Count tables not preceded by a scroll wrapper on the same line.
            preg_match_all('/<table[^>]*>/', $contents, $tables);
            $wrapped = substr_count($contents, 'iapm-table-wrap') + substr_count($contents, 'table-responsive');
            if (count($tables[0]) > $wrapped) {
                $offenders[] = sprintf('%s: %d table(s), %d wrapper(s)', basename($file), count($tables[0]), $wrapped);
            }
        }

        self::assertSame([], $offenders, "Tables that can push the page wider than the viewport:\n".implode("\n", $offenders));
    }
}
