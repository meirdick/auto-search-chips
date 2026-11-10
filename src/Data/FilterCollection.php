<?php

namespace Meirdick\SearchChips\Data;

use Illuminate\Support\Collection;

/**
 * @extends Collection<int, Filter>
 */
class FilterCollection extends Collection
{
    public function fields(): array
    {
        return $this->map(fn (Filter $f) => $f->field)->unique()->values()->all();
    }

    public function hasField(string $field): bool
    {
        return $this->contains(fn (Filter $f) => $f->field === $field);
    }

    public function whereField(string $field): static
    {
        return $this->filter(fn (Filter $f) => $f->field === $field);
    }

    public function removeId(string $id): static
    {
        return $this->reject(fn (Filter $f) => $f->id === $id);
    }

    public function explicit(): static
    {
        return $this->filter(fn (Filter $f) => $f->explicit);
    }

    /**
     * All character spans covered by these filters, flattened and sorted.
     * Used to compute the exact leftover text of a query.
     */
    public function coveredSpans(): array
    {
        $spans = [];

        // A suggested chip is not applied, so its words stay in the keyword search.
        foreach ($this->filter(fn (Filter $f) => $f->spans !== null && ! $f->suggested) as $filter) {
            foreach ($filter->spans as $span) {
                $spans[] = [(int) $span['start'], (int) $span['end']];
            }
        }

        usort($spans, fn ($a, $b) => $a[0] <=> $b[0]);

        return $spans;
    }

    public function allHaveSpans(): bool
    {
        return $this->isNotEmpty() && $this->every(fn (Filter $f) => $f->spans !== null);
    }

    public function toArray(): array
    {
        return $this->map(fn (Filter $f) => $f->toArray())->values()->all();
    }
}
