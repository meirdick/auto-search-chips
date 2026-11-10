<?php

namespace Meirdick\SearchChips;

use Illuminate\Contracts\Support\Arrayable;
use Meirdick\SearchChips\Data\FilterCollection;

/**
 * What a query was understood to mean: validated chips, the leftover
 * keyword text, and an optional sort.
 */
final class ParseResult implements Arrayable
{
    /**
     * @param  bool  $refinable  true when this is a rules-only reading and an AI engine can still refine it
     */
    public function __construct(
        public readonly FilterCollection $filters,
        public readonly string $leftover,
        public readonly ?array $sort,
        public readonly string $engine,
        public readonly array $warnings = [],
        public readonly array $consumed = [],
        public readonly bool $refinable = false,
        /** @var array<int, string>|null  topic words named by the AI engine */
        public readonly ?array $keywords = null,
    ) {}

    public function withLeftover(string $leftover, ?string $engine = null): self
    {
        return new self($this->filters, $leftover, $this->sort, $engine ?? $this->engine, $this->warnings, $this->consumed, $this->refinable, $this->keywords);
    }

    public function withRefinable(bool $refinable): self
    {
        return new self($this->filters, $this->leftover, $this->sort, $this->engine, $this->warnings, $this->consumed, $refinable, $this->keywords);
    }

    public function toArray(): array
    {
        return [
            'filters' => $this->filters->toArray(),
            'leftover' => $this->leftover,
            'sort' => $this->sort,
            'engine' => $this->engine,
            'warnings' => $this->warnings,
            'refinable' => $this->refinable,
        ];
    }
}
