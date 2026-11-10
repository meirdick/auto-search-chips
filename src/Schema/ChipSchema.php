<?php

namespace Meirdick\SearchChips\Schema;

use BackedEnum;
use Meirdick\SearchChips\Data\FieldSchema;

/**
 * The explicit filter definition of a model. A model that declares
 * searchChips(ChipSchema $chips) exposes exactly these fields and no others:
 *
 *   public function searchChips(ChipSchema $chips): void
 *   {
 *       $chips->text('title')->searchable();
 *       $chips->enum('type', PostType::class);
 *       $chips->number('views')->aliases('plays')->sortable();
 *       $chips->relation('author', 'name');
 *   }
 */
final class ChipSchema
{
    /** @var array<string, ChipField> */
    private array $fields = [];

    public function text(string $column): ChipField
    {
        return $this->add(new ChipField($column, 'text'));
    }

    public function number(string $column): ChipField
    {
        return $this->add(new ChipField($column, 'number'));
    }

    public function date(string $column): ChipField
    {
        return $this->add(new ChipField($column, 'date'));
    }

    public function datetime(string $column): ChipField
    {
        return $this->add(new ChipField($column, 'datetime'));
    }

    public function boolean(string $column): ChipField
    {
        return $this->add(new ChipField($column, 'boolean'));
    }

    /**
     * @param  class-string<BackedEnum>|array<int|string, mixed>  $values  an enum class, a list of values, or a value => label map
     */
    public function enum(string $column, string|array $values): ChipField
    {
        return $this->add((new ChipField($column, 'enum'))->values($values));
    }

    /** Filter by a column of a related model: "by Ada" over author.name. */
    public function relation(string $relation, string $column = 'name'): ChipField
    {
        return $this->add(
            // A bare name ("hopper") is what people type, so the related
            // name is part of the keyword search unless ->searchable(false).
            (new ChipField($relation.'.'.$column, 'text', $relation, $column))->aliases($relation)->searchable(),
        );
    }

    /** @return array<string, FieldSchema> */
    public function fields(): array
    {
        return array_map(fn (ChipField $field) => $field->toFieldSchema(), $this->fields);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    private function add(ChipField $field): ChipField
    {
        return $this->fields[$field->name] = $field;
    }
}
