<?php

namespace Meirdick\SearchChips\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Meirdick\SearchChips\Attributes\Unfilterable;

#[Unfilterable]
class AuditLog extends Model
{
    protected $guarded = [];
}
