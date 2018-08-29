<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class House extends Model
{
    protected $hidden = ['created_at', 'updated_at'];
    protected $fillable = ['owner_id', 'color', 'name'];

    public function owner() {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users() {
        return $this->hasMany(User::class);
    }
}
