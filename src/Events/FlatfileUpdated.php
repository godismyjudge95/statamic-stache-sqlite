<?php

namespace Thoughtco\StatamicStacheSqlite\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class FlatfileUpdated
{
    use Dispatchable;

    public $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }
}
