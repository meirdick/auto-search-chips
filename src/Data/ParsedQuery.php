<?php

namespace Meirdick\SearchChips\Data;

/**
 * Raw engine output before normalization. Everything here is untrusted;
 * the FilterNormalizer validates and coerces it into a FilterCollection.
 */
final class ParsedQuery
{
    /**
     * @param  array<int, mixed>  $filters  untrusted raw filter arrays: field, operator, value, negated, confidence, spans, unit, suggested, explicit
     * @param  array<int, array<string, int>>  $consumed  extra covered spans beyond the filters (sort words, etc.)
     * @param  array<string, mixed>|null  $sort  ['field' => ..., 'direction' => ..., 'label' => ...]
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly array $filters,
        public readonly ?string $leftover = null,
        public readonly ?array $sort = null,
        public readonly string $engine = 'rules',
        public readonly array $warnings = [],
        public readonly array $consumed = [],
        /** @var array<int, string>|null  the words the engine says are the topic of the search; null when it gave no view */
        public readonly ?array $keywords = null,
    ) {}

    public static function empty(string $engine = 'rules', array $warnings = []): self
    {
        return new self([], null, null, $engine, $warnings);
    }
}
