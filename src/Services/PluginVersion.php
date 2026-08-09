<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Composer\InstalledVersions;

final class PluginVersion
{
    public const PACKAGE_NAME = 'drakelid/interface-alert-policy-manager';

    public function current(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'development';
        }

        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);
            if ($this->isUsable($version)) {
                return $this->normalize($version);
            }

            // When this package is the Composer root (development and CI), it may
            // not appear in the installed package list. Use root metadata when it
            // contains a meaningful version instead of Composer's placeholder.
            $root = InstalledVersions::getRootPackage();
            $version = ($root['name'] ?? null) === self::PACKAGE_NAME
                ? ($root['pretty_version'] ?? null)
                : null;
            if ($this->isUsable($version)) {
                return $this->normalize($version);
            }
        } catch (\Throwable) {
            // A copied/path checkout may not have complete Composer metadata.
        }

        return 'development';
    }

    public function display(): string
    {
        $version = $this->current();

        return preg_match('/^\d+(?:\.\d+)+(?:[-+].+)?$/', $version) === 1 ? 'v'.$version : $version;
    }

    private function isUsable(mixed $version): bool
    {
        return is_string($version) && $version !== '' && $version !== '1.0.0+no-version-set';
    }

    private function normalize(string $version): string
    {
        return str_starts_with($version, 'v') ? substr($version, 1) : $version;
    }
}
