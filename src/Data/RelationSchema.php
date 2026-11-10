<?php

namespace Meirdick\SearchChips\Data;

final class RelationSchema
{
    public function __construct(
        public readonly string $name,
        public readonly string $relatedModel,
        public readonly ?string $nameColumn = null,
    ) {}
}
