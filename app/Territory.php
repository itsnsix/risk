<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Territory extends Model
{
    protected $hidden = ['pivot'];
    protected $fillable = ['x', 'y', 'size'];

    public $timestamps = false;

    public function borders() {
        return $this->belongsToMany(Territory::class, 'borders', 'territory_id', 'bordering_id');
    }

    public function borderedTo() {
        return $this->belongsToMany(Territory::class, 'borders', 'bordering_id', 'territory_id');
    }

    public function occupation() {
        return $this->hasOne(Occupation::class)->where('active', true);
    }

    public function starter() {
        return $this->hasOne(User::class, 'starting_territory');
    }
}
