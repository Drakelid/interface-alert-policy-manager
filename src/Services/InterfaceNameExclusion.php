<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Name-based interface exclusions for group assignments, e.g. "kundeport".
 * A pattern is a case-insensitive substring checked against the interface's
 * ifName, ifAlias and ifDescr. Plain text rather than regex: operators type
 * a word they see in LibreNMS, and substrings cannot backtrack catastrophically.
 */
class InterfaceNameExclusion
{
    public const COLUMNS = ['ifName', 'ifAlias', 'ifDescr'];

    /**
     * Trim, drop blanks and remove case-only duplicates. Non-string entries are
     * kept so validation can reject them instead of silently discarding input.
     *
     * @return list<mixed>
     */
    public static function normalize(array $patterns): array
    {
        $seen = [];
        $normalized = [];
        foreach ($patterns as $pattern) {
            if (is_string($pattern)) {
                $pattern = trim($pattern);
                $key = mb_strtolower($pattern);
                if ($pattern === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }
            $normalized[] = $pattern;
        }

        return $normalized;
    }

    /** @return list<string> lower-cased patterns, ready for matches() */
    public static function prepare(mixed $patterns): array
    {
        $prepared = [];
        foreach ((array) $patterns as $pattern) {
            if (is_string($pattern) && trim($pattern) !== '') {
                $prepared[] = mb_strtolower(trim($pattern));
            }
        }

        return array_values(array_unique($prepared));
    }

    /** @param  list<string>  $prepared  output of prepare() */
    public static function matches(array $prepared, string ...$names): bool
    {
        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }
            $name = mb_strtolower($name);
            foreach ($prepared as $pattern) {
                if (str_contains($name, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Restrict a ports query to interfaces whose names contain none of the patterns. */
    public static function exclude(Builder $query, mixed $patterns): Builder
    {
        foreach (self::prepare($patterns) as $pattern) {
            // '!' as the LIKE escape character works identically on MariaDB and
            // SQLite, unlike backslash which depends on the SQL mode.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $pattern).'%';
            foreach (self::COLUMNS as $column) {
                $query->whereRaw("LOWER(COALESCE(ports.{$column}, '')) NOT LIKE ? ESCAPE '!'", [$like]);
            }
        }

        return $query;
    }
}
