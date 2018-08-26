<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class House extends Model
{
    protected $hidden = ['created_at', 'updated_at'];

    public function owner() {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users() {
        return $this->hasMany(User::class);
    }
}
