<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Border extends Pivot
{
    protected $table = 'borders';

    public $timestamps = false;
}
