<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class House extends Model
{
    protected $hidden = ['created_at', 'updated_at'];

    public function users() {
        return $this->hasMany(User::class);
    }
}
