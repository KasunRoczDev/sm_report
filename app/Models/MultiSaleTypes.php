<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MultiSaleTypes extends Model
{
    protected $fillable = ['transaction_id', 'sale_type'];

    protected $guarded = ['id'];
}
