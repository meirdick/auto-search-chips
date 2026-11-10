<?php

namespace Meirdick\SearchChips\Attributes;

use BackedEnum;

/**
 * Add filter metadata to a model's columns. Class-level and repeatable —
 * property-level attributes on Eloquent models shadow the magic attribute
 * access, so the column name is passed explicitly.
 *
 *   #[Filterable('engagement_rate', unit: 'percent', scale: 0.01)]
 *   #[Filterable('views', aliases: ['view count', 'plays'], orderable: true)]
 *   class Post extends Model {}
 *
 * A `values` enum class also works for string columns with a fixed
 * vocabulary that isn't a backed-enum cast.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Filterable
{
    /**
     * @param  array<int, string>  $aliases  natural-language tokens that resolve to this field
     * @param  class-string<BackedEnum>|array<int, mixed>  $values
     */
    public function __construct(
        public readonly string $field,
        public readonly ?string $label = null,
        public readonly ?string $unit = null,
        public readonly ?float $scale = null,
        public readonly array $aliases = [],
        public readonly array|string|null $values = null,
        public readonly bool $searchable = false,
        public readonly bool $orderable = false,
    ) {}
}
