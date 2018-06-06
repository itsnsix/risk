<?php

namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $hidden = ['updated_at'];
    protected $appends = ['timestamp'];

    public function getTimestampAttribute()
    {
        if ($this->created_at) {
            return Carbon::parse($this->created_at)->format('d/m/Y H:i');
        } else {
            return null;
        }
    }

    public function getExtraAttribute($value)
    {
        if ($value) {
            return json_decode($value);
        } else {
            return null;
        }
    }

    public function user() {
        return $this->belongsTo(User::class);
    }
}
