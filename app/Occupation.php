<?php

namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Occupation extends Model
{
    protected $hidden = ['created_at', 'updated_at'];

    public function territory() {
        return $this->belongsTo(Territory::class);
    }

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function previousOccupation() {
        return $this->belongsTo(Occupation::class, 'previous_occupation');
    }
}
