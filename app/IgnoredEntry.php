<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class IgnoredEntry extends Model
{
    protected $fillable = ['user_id', 'api_data_id', 'api_created_at'];

    public function user() {
        return $this->belongsTo(User::class);
    }
}
