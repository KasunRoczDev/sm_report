<?php

namespace App\Utils;

use Illuminate\Database\Eloquent\Model;

class DiscountVariations extends Model
{
    protected $table = 'discount_variations';

    protected $fillable = [
        'discount_id',
        'variation_id',
        'value',
    ];
}
