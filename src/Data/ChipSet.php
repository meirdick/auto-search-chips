<?php

namespace Meirdick\SearchChips\Data;

/**
 * Edits to a chip set that a filter control makes. Each field has three
 * slots: a lower bound, an upper bound, and an exact value. Setting a slot
 * replaces what was in it.
 */
final class ChipSet
{
    private const SLOTS = [
        Operator::GT => 'min', Operator::GTE => 'min', Operator::AFTER => 'min',
        Operator::LT => 'max', Operator::LTE => 'max', Operator::BEFORE => 'max',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $chips
     * @return array<int, array<string, mixed>>
     */
    public static function set(array $chips, string $field, string $operator, mixed $value): array
    {
        $slot = self::SLOTS[$operator] ?? 'exact';

        if ($slot !== 'exact') {
            $chips = self::splitRange($chips, $field, in_array($operator, [Operator::AFTER, Operator::BEFORE], true));
        }

        $chips = array_values(array_filter($chips, function ($chip) use ($field, $slot) {
            if (($chip['field'] ?? null) !== $field) {
                return true;
            }

            $existing = self::SLOTS[$chip['operator'] ?? ''] ?? 'exact';

            // An exact value and a bound do not combine on one field.
            return $existing !== $slot && $existing !== 'exact' && $slot !== 'exact';
        }));

        $empty = $value === null || $value === '' || $value === [];

        if ($empty && ! in_array($operator, [Operator::IS_TRUE, Operator::IS_FALSE], true)) {
            return $chips;
        }

        $chips[] = [
            'id' => 'user:'.preg_replace('/[^A-Za-z0-9_.-]/', '', $field).':'.$slot,
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'source' => 'user',
        ];

        return $chips;
    }

    /**
     * A range chip is two bounds. Split it, so a change to one bound keeps the other.
     *
     * @param  array<int, array<string, mixed>>  $chips
     * @return array<int, array<string, mixed>>
     */
    private static function splitRange(array $chips, string $field, bool $date): array
    {
        $out = [];

        foreach ($chips as $chip) {
            $value = $chip['value'] ?? null;

            if (($chip['field'] ?? null) !== $field || ($chip['operator'] ?? null) !== Operator::BETWEEN
                || ! is_array($value) || count($value) < 2 || ($chip['negated'] ?? false)) {
                $out[] = $chip;

                continue;
            }

            $id = 'user:'.preg_replace('/[^A-Za-z0-9_.-]/', '', $field);
            [$low, $high] = array_values($value);

            $out[] = ['id' => $id.':min', 'field' => $field, 'operator' => $date ? Operator::AFTER : Operator::GTE, 'value' => $low, 'source' => 'user'];
            $out[] = ['id' => $id.':max', 'field' => $field, 'operator' => $date ? Operator::BEFORE : Operator::LTE, 'value' => $high, 'source' => 'user'];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chips
     * @return array<int, array<string, mixed>>
     */
    public static function unset(array $chips, string $field): array
    {
        return array_values(array_filter($chips, fn ($chip) => ($chip['field'] ?? null) !== $field));
    }

    /**
     * What each control of a field shows for a chip set.
     *
     * @param  array<int, array<string, mixed>>  $chips
     * @return array{min: mixed, max: mixed, exact: mixed, bool: ?bool}
     */
    public static function controls(array $chips, string $field): array
    {
        $state = ['min' => null, 'max' => null, 'exact' => null, 'bool' => null];

        foreach ($chips as $chip) {
            if (($chip['field'] ?? null) !== $field || ($chip['suggested'] ?? false)) {
                continue;
            }

            $operator = $chip['operator'] ?? '';
            $value = $chip['value'] ?? null;

            match (true) {
                $operator === Operator::IS_TRUE => $state['bool'] = true,
                $operator === Operator::IS_FALSE => $state['bool'] = false,
                $operator === Operator::BETWEEN && is_array($value) => [$state['min'], $state['max']] = [$value[0] ?? null, $value[1] ?? null],
                isset(self::SLOTS[$operator]) => $state[self::SLOTS[$operator]] = $value,
                default => $state['exact'] = is_array($value) ? ($value[0] ?? null) : $value,
            };
        }

        return $state;
    }
}
