<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];

    protected $casts = ['items' => 'array'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
