<?php

namespace Meirdick\SearchChips\Data;

final class Operator
{
    public const EQUALS = 'equals';

    public const NOT_EQUALS = 'not_equals';

    public const CONTAINS = 'contains';

    public const STARTS_WITH = 'starts_with';

    public const ENDS_WITH = 'ends_with';

    public const IN = 'in';

    public const GT = 'gt';

    public const GTE = 'gte';

    public const LT = 'lt';

    public const LTE = 'lte';

    public const BETWEEN = 'between';

    public const BEFORE = 'before';

    public const AFTER = 'after';

    public const ON = 'on';

    public const IS_TRUE = 'is_true';

    public const IS_FALSE = 'is_false';

    public const IS_EMPTY = 'is_empty';

    public const NOT_EMPTY = 'not_empty';

    /**
     * Operators allowed per field kind. The closed vocabulary every engine
     * must respect; the normalizer rejects anything outside these lists.
     */
    public static function forKind(string $kind): array
    {
        return match ($kind) {
            'text' => [self::EQUALS, self::NOT_EQUALS, self::CONTAINS, self::STARTS_WITH, self::ENDS_WITH, self::IN, self::IS_EMPTY, self::NOT_EMPTY],
            'number' => [self::EQUALS, self::NOT_EQUALS, self::GT, self::GTE, self::LT, self::LTE, self::BETWEEN, self::IS_EMPTY, self::NOT_EMPTY],
            'date', 'datetime' => [self::BEFORE, self::AFTER, self::ON, self::BETWEEN, self::IS_EMPTY, self::NOT_EMPTY],
            'boolean' => [self::IS_TRUE, self::IS_FALSE],
            'enum' => [self::EQUALS, self::NOT_EQUALS, self::IN],
            default => [],
        };
    }

    public static function describe(string $operator): string
    {
        return match ($operator) {
            self::EQUALS => 'is',
            self::NOT_EQUALS => 'is not',
            self::CONTAINS => 'contains',
            self::STARTS_WITH => 'starts with',
            self::ENDS_WITH => 'ends with',
            self::IN => 'is one of',
            self::GT => 'greater than',
            self::GTE => 'at least',
            self::LT => 'less than',
            self::LTE => 'at most',
            self::BETWEEN => 'between',
            self::BEFORE => 'before',
            self::AFTER => 'after',
            self::ON => 'on',
            self::IS_TRUE => 'is yes',
            self::IS_FALSE => 'is no',
            self::IS_EMPTY => 'is empty',
            self::NOT_EMPTY => 'is not empty',
            default => $operator,
        };
    }

    public static function all(): array
    {
        return [
            self::EQUALS, self::NOT_EQUALS, self::CONTAINS, self::STARTS_WITH, self::ENDS_WITH,
            self::IN, self::GT, self::GTE, self::LT, self::LTE, self::BETWEEN, self::BEFORE,
            self::AFTER, self::ON, self::IS_TRUE, self::IS_FALSE, self::IS_EMPTY,
            self::NOT_EMPTY,
        ];
    }
}
