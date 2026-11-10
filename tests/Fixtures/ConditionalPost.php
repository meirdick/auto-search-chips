<?php

namespace Meirdick\SearchChips\Tests\Fixtures;

use Meirdick\SearchChips\Schema\ChipSchema;

/** The author filter must not reveal who wrote a "featured" (stand-in for anonymous) post. */
class ConditionalPost extends DeclaredPost
{
    public function searchChips(ChipSchema $chips): void
    {
        $chips->text('title')->searchable();
        $chips->relation('author', 'name')->onlyWhen(['is_featured' => false]);
    }
}
