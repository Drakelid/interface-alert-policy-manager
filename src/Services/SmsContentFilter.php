<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use InvalidArgumentException;

class SmsContentFilter
{
    public const DEFAULT_PHRASES = [
        'Bundle to',
        'Bundle-Ethernet *',
        'Bundle-Ether*',
        'BundleEthernet *',
        'BundleEther*',
        'Bundle_Ethernet *',
        'Bundle_Ether*',
        'Bundle Ethernet *',
        'Bundle Ethernet*',
        'Bundle Ether *',
        'Bundle Ether*',
        'Bundle Eth *',
        'Bundle_Ethernet*',
        'Bundle Eth*',
    ];

    public const DEFAULT_SYMBOLS = ['#'];

    public function __construct(private readonly SettingStore $settings) {}

    /** @return list<string> */
    public function phrases(): array
    {
        return $this->stored('sms_filter_phrases', self::DEFAULT_PHRASES);
    }

    /** @return list<string> */
    public function symbols(): array
    {
        return $this->stored('sms_filter_symbols', self::DEFAULT_SYMBOLS);
    }

    public function filter(string $message): string
    {
        return $this->filterWith($message, $this->phrases(), $this->symbols());
    }

    /** @param list<string> $phrases @param list<string> $symbols */
    public function filterWith(string $message, array $phrases, array $symbols): string
    {
        foreach ($this->longestFirst($phrases) as $phrase) {
            $message = preg_replace($this->phrasePattern($phrase), '', $message) ?? $message;
        }
        foreach ($this->longestFirst($symbols) as $symbol) {
            $message = str_replace($symbol, '', $message);
        }

        $message = preg_replace('/[ \t]{2,}/u', ' ', $message) ?? $message;

        return preg_replace('/^[ \t]+|[ \t]+$/m', '', $message) ?? $message;
    }

    /** @return list<string> */
    public function parseLines(string $value): array
    {
        $lines = preg_split('/\R/u', $value) ?: [];

        return $this->validateList($lines);
    }

    /** @param list<string> $values */
    public function asLines(array $values): string
    {
        return implode("\n", $values);
    }

    /** @param list<string> $default @return list<string> */
    private function stored(string $key, array $default): array
    {
        $stored = $this->settings->get($key, null);
        if (! is_array($stored)) {
            return $default;
        }

        try {
            return $this->validateList($stored);
        } catch (InvalidArgumentException) {
            return $default;
        }
    }

    /** @param array<mixed> $values @return list<string> */
    private function validateList(array $values): array
    {
        $result = [];
        $seen = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                throw new InvalidArgumentException('Every filter must be text.');
            }
            $filter = trim((string) $value);
            if ($filter === '') {
                continue;
            }
            if (mb_strlen($filter) > 100) {
                throw new InvalidArgumentException('Each filter may contain at most 100 characters.');
            }
            if (str_contains($filter, '*') && (! str_ends_with($filter, '*') || substr_count($filter, '*') > 1)) {
                throw new InvalidArgumentException('An asterisk is only allowed once, at the end of a word or phrase filter.');
            }
            if ($filter === '*') {
                throw new InvalidArgumentException('A filter cannot consist only of an asterisk.');
            }
            $identity = mb_strtolower($filter);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $result[] = $filter;
        }
        if (count($result) > 100) {
            throw new InvalidArgumentException('At most 100 filters are allowed in each list.');
        }

        return $result;
    }

    private function phrasePattern(string $phrase): string
    {
        $wildcard = str_ends_with($phrase, '*');
        $literal = $wildcard ? mb_substr($phrase, 0, -1) : $phrase;
        $escaped = preg_quote($literal, '/');
        $leftBoundary = preg_match('/^[\p{L}\p{N}]/u', $literal) === 1 ? '(?<![\p{L}\p{N}])' : '';
        $rightBoundary = ! $wildcard && preg_match('/[\p{L}\p{N}]$/u', $literal) === 1 ? '(?![\p{L}\p{N}])' : '';
        $suffix = $wildcard ? '[\p{L}\p{N}._:\/-]*(?:[ \t]+to\b)?[ \t]*(?:[-–—:;|][ \t]*)?' : '';

        return '/'.$leftBoundary.$escaped.$suffix.$rightBoundary.'/iu';
    }

    /** @param list<string> $values @return list<string> */
    private function longestFirst(array $values): array
    {
        usort($values, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return $values;
    }
}
