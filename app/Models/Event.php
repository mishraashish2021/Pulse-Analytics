<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];
}
