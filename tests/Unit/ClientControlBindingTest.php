<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ClientControlBindingTest extends TestCase
{
    public function test_token_controls_use_delegated_handlers(): void
    {
        $assets = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/partials/assets.blade.php');

        self::assertStringContainsString("closest('[data-iapm-reveal-token]')", $assets);
        self::assertStringContainsString("closest('[data-iapm-copy-text], [data-copy]')", $assets);
        self::assertStringNotContainsString("document.querySelectorAll('[data-iapm-reveal-token]').forEach", $assets);
        self::assertStringNotContainsString("document.querySelectorAll('[data-copy]').forEach", $assets);
    }

    public function test_copy_has_a_fallback_for_non_secure_http_pages(): void
    {
        $assets = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/partials/assets.blade.php');

        self::assertStringContainsString('navigator.clipboard && navigator.clipboard.writeText', $assets);
        self::assertStringContainsString("document.execCommand('copy')", $assets);
    }
}
