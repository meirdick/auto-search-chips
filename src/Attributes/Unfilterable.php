<?php

namespace Meirdick\SearchChips\Attributes;

/**
 * Exclude a field (class-level, with the field name) or a relation (method
 * level). With no argument on the class, the whole model stays out of
 * schema discovery — useful for pivots and system tables.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Unfilterable
{
    public function __construct(public readonly ?string $field = null) {}
}
