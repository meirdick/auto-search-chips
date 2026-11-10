<?php

namespace Meirdick\SearchChips\Registry;

use Meirdick\SearchChips\Data\ModelSchema;

final class RegisteredModel
{
    public ?ModelSchema $schema = null;

    /**
     * @param  bool  $exposed  whether the HTTP routes and UI components can reach this alias
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly string $alias,
        public readonly array $options = [],
        public readonly bool $exposed = true,
    ) {}
}
