<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Occupation extends Model
{
    protected $hidden = ['created_at', 'updated_at'];
    protected $fillable = ['user_id', 'active', 'api_data_id',
        'api_created_at', 'territory_id', 'previous_occupation'];

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
