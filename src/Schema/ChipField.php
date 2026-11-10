<?php

namespace Meirdick\SearchChips\Schema;

use BackedEnum;
use Meirdick\SearchChips\Data\FieldSchema;

/**
 * One fluent field definition inside a ChipSchema.
 */
final class ChipField
{
    private ?string $label = null;

    /** @var array<int, string> */
    private array $aliases = [];

    /** @var array<int, mixed>|null */
    private ?array $values = null;

    /** @var array<string, string> */
    private array $valueLabels = [];

    private ?string $unit = null;

    private ?float $scale = null;

    private bool $searchable = false;

    private bool $sortable = false;

    /** @var array<string, scalar|null> */
    private array $conditions = [];

    /** @var array<string, scalar> */
    private array $synonyms = [];

    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly ?string $relation = null,
        public readonly ?string $relationColumn = null,
    ) {}

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** Words in a query that mean this field: ->aliases('plays', 'view count'). */
    public function aliases(string ...$aliases): static
    {
        $this->aliases = [...$this->aliases, ...$aliases];

        return $this;
    }

    /**
     * @param  class-string<BackedEnum>|array<int|string, mixed>  $values
     */
    public function values(string|array $values): static
    {
        if (is_string($values)) {
            $this->values = array_map(fn (BackedEnum $case) => $case->value, $values::cases());

            return $this;
        }

        if (array_is_list($values)) {
            $this->values = $values;

            return $this;
        }

        $this->values = array_keys($values);
        $this->valueLabels = array_map('strval', $values);

        return $this;
    }

    /** "above 8%" on a 0..1 column: ->unit('percent', scale: 0.01). */
    public function unit(string $unit, ?float $scale = null): static
    {
        $this->unit = $unit;
        $this->scale = $scale;

        return $this;
    }

    /**
     * Rows this filter may select. A filter on an author's name must not
     * find posts that were published anonymously:
     *
     *   $chips->relation('user', 'name')->onlyWhen(['is_anonymous' => false]);
     *
     * @param  array<string, scalar|null>  $conditions  column => value on the model's own table
     */
    public function onlyWhen(array $conditions): static
    {
        $this->conditions = $conditions;

        return $this;
    }

    /**
     * Other words people use for a value. Plurals are understood.
     *
     *   $chips->enum('type', PostType::class)->synonyms(['movie' => 'video', 'clip' => 'video', 'show' => 'podcast']);
     *
     * @param  array<string, scalar>  $synonyms  word => value
     */
    public function synonyms(array $synonyms): static
    {
        $this->synonyms = [...$this->synonyms, ...$synonyms];

        return $this;
    }

    /** Leftover words search this text field. */
    public function searchable(bool $searchable = true): static
    {
        $this->searchable = $searchable;

        return $this;
    }

    /** "latest", "top", "most viewed" can sort by this field. */
    public function sortable(bool $sortable = true): static
    {
        $this->sortable = $sortable;

        return $this;
    }

    public function toFieldSchema(): FieldSchema
    {
        return new FieldSchema(
            name: $this->name,
            kind: $this->kind,
            label: $this->label,
            aliases: $this->aliases,
            values: $this->values,
            valueSource: 'declared',
            valueLabels: $this->valueLabels,
            unit: $this->unit,
            scale: $this->scale,
            relation: $this->relation,
            relationColumn: $this->relationColumn,
            searchable: $this->searchable,
            orderable: $this->sortable,
            conditions: $this->conditions,
            synonyms: $this->synonyms,
        );
    }
}
