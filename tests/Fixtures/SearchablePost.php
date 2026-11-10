<?php

namespace Meirdick\SearchChips\Tests\Fixtures;

use Meirdick\SearchChips\HasSearchChips;

class SearchablePost extends Post
{
    use HasSearchChips;

    protected $table = 'posts';
}
