<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use InvalidArgumentException;

class SafeTemplateRenderer
{
    private const MAX_CONDITIONAL_DEPTH = 10;

    private const MAX_CONDITIONS_PER_BLOCK = 10;

    public function render(string $template, array $values): string
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

        return $this->renderNodes($nodes);
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

    /** @return list<array{name: string, value: scalar|null, operator: string|null, expected: string|null}> */
    private function parseCondition(string $tag, array $values): array
    {
        if (! str_starts_with($tag, '#if ')) {
            throw $this->invalidCondition();
        }

        $parts = $this->splitConditions(substr($tag, 4));
        if ($parts === [] || count($parts) > self::MAX_CONDITIONS_PER_BLOCK) {
            throw $this->invalidCondition();
        }

        return array_map(function (string $part) use ($values): array {
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9_]*)(?:\s*(==|!=|contains|not\s+contains)\s*(?:(["\'])(.*?)\3|([a-zA-Z0-9_.:\-]+)))?$/s', trim($part), $matches) !== 1) {
                throw $this->invalidCondition();
            }

            return [
                'name' => $matches[1],
                'value' => $this->value($matches[1], $values),
                'operator' => isset($matches[2]) ? preg_replace('/\s+/', ' ', $matches[2]) : null,
                'expected' => ($matches[3] ?? '') !== '' ? ($matches[4] ?? '') : ($matches[5] ?? null),
            ];
        }, $parts);
    }

    /** @return list<string> */
    private function splitConditions(string $expression): array
    {
        $parts = [];
        $start = 0;
        $quote = null;
        $length = strlen($expression);
        for ($position = 0; $position < $length; $position++) {
            $character = $expression[$position];
            if ($character === '"' || $character === "'") {
                $quote = $quote === null ? $character : ($quote === $character ? null : $quote);

                continue;
            }
            if ($quote === null && $character === '&' && ($expression[$position + 1] ?? null) === '&') {
                $parts[] = trim(substr($expression, $start, $position - $start));
                $start = $position + 2;
                $position++;
            }
        }
        if ($quote !== null) {
            throw $this->invalidCondition();
        }
        $parts[] = trim(substr($expression, $start));

        return $parts;
    }

    private function invalidCondition(): InvalidArgumentException
    {
        return new InvalidArgumentException('Invalid condition. Use {{#if placeholder}}, {{#if placeholder == "value"}}, {{#if placeholder contains "value"}}, and combine conditions with &&.');
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

            $matches = true;
            foreach ($node['condition'] as $condition) {
                $matches = $matches && match ($condition['operator']) {
                    '==' => $this->equals($condition['name'], $condition['value'], (string) $condition['expected']),
                    '!=' => ! $this->equals($condition['name'], $condition['value'], (string) $condition['expected']),
                    'contains' => $this->contains($condition['name'], $condition['value'], (string) $condition['expected']),
                    'not contains' => ! $this->contains($condition['name'], $condition['value'], (string) $condition['expected']),
                    default => (bool) $condition['value'],
                };
            }
            $rendered .= $this->renderNodes($matches ? $node['truthy'] : $node['falsy']);
        }

        return $rendered;
    }

    private function equals(string $name, mixed $value, string $expected): bool
    {
        return (string) $value === $expected || ($name === 'device_groups' && $this->contains($name, $value, $expected));
    }

    private function contains(string $name, mixed $value, string $expected): bool
    {
        if ($name === 'device_groups') {
            return in_array($expected, array_map('trim', explode(',', (string) $value)), true);
        }

        return str_contains((string) $value, $expected);
    }
}
