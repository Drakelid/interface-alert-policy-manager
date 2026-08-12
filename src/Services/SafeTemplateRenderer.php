<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use InvalidArgumentException;

class SafeTemplateRenderer
{
    private const MAX_CONDITIONAL_DEPTH = 10;

    /** GSM 03.38 default and extension alphabets. Extension characters use two septets. */
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1BÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENSION = "^{}\\[~]|€\f";

    public function render(string $template, array $values, ?int $limit = null): string
    {
        $tokens = preg_split('/(\{\{.*?\}\})/s', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            throw new InvalidArgumentException('Invalid template.');
        }

        $position = 0;
        [$nodes, $stop] = $this->parseBlock($tokens, $position, $values);
        if ($stop !== null) {
            throw new InvalidArgumentException($stop === 'else'
                ? 'Template contains {{else}} without a matching {{#if}}.'
                : 'Template contains {{/if}} without a matching {{#if}}.');
        }
        $rendered = $this->renderNodes($nodes);

        if ($limit !== null && mb_strlen($rendered) > $limit) {
            $suffix = isset($values['incident_id']) ? "\nIncident: {$values['incident_id']}" : '';
            $rendered = rtrim(mb_substr($rendered, 0, max(0, $limit - mb_strlen($suffix) - 1))).'…'.$suffix;
        }

        return $rendered;
    }

    /** Render and guarantee that an SMS gateway receives at most one SMS segment. */
    public function renderSingleSms(string $template, array $values): string
    {
        return $this->limitToSingleSms($this->render($template, $values), $values['incident_id'] ?? null);
    }

    /** @return array{encoding: string, units: int, segments: int, single_limit: int} */
    public function smsMetrics(string $message): array
    {
        $gsmUnits = $this->gsmUnits($message);
        $encoding = $gsmUnits === null ? 'Unicode' : 'GSM-7';
        $units = $gsmUnits ?? $this->unicodeUnits($message);
        $singleLimit = $gsmUnits === null ? 70 : 160;
        $multipartLimit = $gsmUnits === null ? 67 : 153;

        return [
            'encoding' => $encoding,
            'units' => $units,
            'segments' => $units <= $singleLimit ? 1 : (int) ceil($units / $multipartLimit),
            'single_limit' => $singleLimit,
        ];
    }

    public function limitToSingleSms(string $message, string|int|null $incidentId = null): string
    {
        if ($this->smsMetrics($message)['segments'] === 1) {
            return $message;
        }

        $suffix = $incidentId === null || $incidentId === '' ? '...' : "...\nIncident: $incidentId";
        $result = '';
        foreach ($this->characters($message) as $character) {
            if ($this->smsMetrics($result.$character.$suffix)['segments'] > 1) {
                break;
            }
            $result .= $character;
        }

        return rtrim($result).$suffix;
    }

    /**
     * Parse and validate a block. Every branch is parsed before rendering, so
     * an unknown placeholder cannot hide in a branch that the sample skips.
     *
     * @param  list<string>  $tokens
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private function parseBlock(array $tokens, int &$position, array $values, int $depth = 0): array
    {
        $nodes = [];

        while ($position < count($tokens)) {
            $token = $tokens[$position];
            if (! str_starts_with($token, '{{') || ! str_ends_with($token, '}}')) {
                $nodes[] = ['type' => 'text', 'value' => $token];
                $position++;

                continue;
            }

            $tag = trim(substr($token, 2, -2));
            if ($tag === 'else' || $tag === '/if') {
                return [$nodes, $tag];
            }

            if (str_starts_with($tag, '#if')) {
                if ($depth >= self::MAX_CONDITIONAL_DEPTH) {
                    throw new InvalidArgumentException('Template conditionals may be nested at most '.self::MAX_CONDITIONAL_DEPTH.' levels deep.');
                }

                $condition = $this->parseCondition($tag, $values);
                $position++;
                [$truthy, $stop] = $this->parseBlock($tokens, $position, $values, $depth + 1);
                $falsy = [];
                if ($stop === 'else') {
                    $position++;
                    [$falsy, $stop] = $this->parseBlock($tokens, $position, $values, $depth + 1);
                }
                if ($stop !== '/if') {
                    throw new InvalidArgumentException('Template contains an unclosed {{#if}} block.');
                }
                $position++;
                $nodes[] = ['type' => 'if', 'condition' => $condition, 'truthy' => $truthy, 'falsy' => $falsy];

                continue;
            }

            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $tag) === 1) {
                $nodes[] = ['type' => 'text', 'value' => $this->value($tag, $values)];
                $position++;

                continue;
            }

            // Keep non-template brace expressions literal, preserving the old
            // no-execution behaviour. Reserved control tags fail visibly.
            if (str_starts_with($tag, '#') || str_starts_with($tag, '/')) {
                throw new InvalidArgumentException("Invalid template control tag: $token");
            }

            $nodes[] = ['type' => 'text', 'value' => $token];
            $position++;
        }

        return [$nodes, null];
    }

    /** @return array{value: scalar|null, operator: string|null, expected: string|null} */
    private function parseCondition(string $tag, array $values): array
    {
        if (preg_match('/^#if\s+([a-zA-Z][a-zA-Z0-9_]*)(?:\s*(==|!=)\s*(["\'])(.*?)\3)?$/s', $tag, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid condition. Use {{#if placeholder}} or {{#if placeholder == "value"}}.');
        }

        return [
            'value' => $this->value($matches[1], $values),
            'operator' => $matches[2] ?? null,
            'expected' => $matches[4] ?? null,
        ];
    }

    private function value(string $name, array $values): string|int|float|bool|null
    {
        if (! array_key_exists($name, $values)) {
            throw new InvalidArgumentException("Unknown template placeholder: $name");
        }

        $value = $values[$name];
        if (! is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException("Invalid template value: $name");
        }

        return $value;
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private function renderNodes(array $nodes): string
    {
        $rendered = '';
        foreach ($nodes as $node) {
            if ($node['type'] === 'text') {
                $rendered .= (string) $node['value'];

                continue;
            }

            $condition = $node['condition'];
            $matches = match ($condition['operator']) {
                '==' => (string) $condition['value'] === $condition['expected'],
                '!=' => (string) $condition['value'] !== $condition['expected'],
                default => (bool) $condition['value'],
            };
            $rendered .= $this->renderNodes($matches ? $node['truthy'] : $node['falsy']);
        }

        return $rendered;
    }

    private function gsmUnits(string $message): ?int
    {
        $units = 0;
        foreach ($this->characters($message) as $character) {
            if (str_contains(self::GSM_BASIC, $character)) {
                $units++;
            } elseif (str_contains(self::GSM_EXTENSION, $character)) {
                $units += 2;
            } else {
                return null;
            }
        }

        return $units;
    }

    private function unicodeUnits(string $message): int
    {
        return (int) (strlen(mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')) / 2);
    }

    /** @return list<string> */
    private function characters(string $message): array
    {
        return preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
