<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P3-5: help text used Bootstrap 3's .text-muted (#777), which is 4.48:1 on
 * white — under WCAG AA's 4.5:1 for body text — and far worse on the dark theme.
 *
 * The plugin's replacement, .iapm-hint, is defined once per theme in
 * partials/assets. This computes the real WCAG contrast ratio for the colours
 * actually declared there, so the values cannot drift below AA unnoticed.
 */
class ContrastTest extends TestCase
{
    private const AA_BODY_TEXT = 4.5;

    /** Backgrounds the hint text sits on in each theme. */
    private const LIGHT_BACKGROUNDS = ['#ffffff', '#f9f9f9'];

    private const DARK_BACKGROUNDS = ['#212529', '#2b3035', '#343a40'];

    public function test_the_light_theme_hint_colour_meets_wcag_aa(): void
    {
        $colour = $this->declaredColour('/^\.iapm-hint \{ color:(#[0-9a-f]{6}); \}\s*$/mi');

        foreach (self::LIGHT_BACKGROUNDS as $background) {
            self::assertGreaterThanOrEqual(
                self::AA_BODY_TEXT,
                $ratio = $this->ratio($colour, $background),
                sprintf('.iapm-hint %s on %s is %.2f:1, below WCAG AA.', $colour, $background, $ratio)
            );
        }
    }

    public function test_the_dark_theme_hint_colour_meets_wcag_aa(): void
    {
        $colour = $this->declaredColour('/^\.dark \.iapm-hint \{ color:(#[0-9a-f]{6}); \}\s*$/mi');

        foreach (self::DARK_BACKGROUNDS as $background) {
            self::assertGreaterThanOrEqual(
                self::AA_BODY_TEXT,
                $ratio = $this->ratio($colour, $background),
                sprintf('.dark .iapm-hint %s on %s is %.2f:1, below WCAG AA.', $colour, $background, $ratio)
            );
        }
    }

    /**
     * Guards the premise: if Bootstrap's #777 already passed, this whole change
     * would be pointless. It does not, on the lightest background we use.
     */
    public function test_the_bootstrap_default_this_replaced_really_did_fail(): void
    {
        self::assertLessThan(self::AA_BODY_TEXT, $this->ratio('#777777', '#ffffff'));
    }

    public function test_the_ratio_calculation_matches_known_values(): void
    {
        // Black on white is the maximum, 21:1; identical colours are 1:1.
        self::assertEqualsWithDelta(21.0, $this->ratio('#000000', '#ffffff'), 0.01);
        self::assertEqualsWithDelta(1.0, $this->ratio('#123456', '#123456'), 0.01);
    }

    private function declaredColour(string $pattern): string
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/partials/assets.blade.php');
        self::assertSame(1, preg_match($pattern, $css, $matches), "No colour declaration matched $pattern.");

        return strtolower($matches[1]);
    }

    /** WCAG 2.x relative-luminance contrast ratio. */
    private function ratio(string $foreground, string $background): float
    {
        $light = $this->luminance($foreground);
        $dark = $this->luminance($background);
        if ($light < $dark) {
            [$light, $dark] = [$dark, $light];
        }

        return ($light + 0.05) / ($dark + 0.05);
    }

    private function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(
            fn (string $pair) => $this->channel(hexdec($pair) / 255),
            str_split(ltrim($hex, '#'), 2)
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function channel(float $value): float
    {
        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }
}
