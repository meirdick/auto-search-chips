<?php

namespace Meirdick\SearchChips\Tests\Fixtures;

use Laravel\Scout\Searchable;

class ScoutPost extends DeclaredPost
{
    use Searchable;

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title];
    }
}
