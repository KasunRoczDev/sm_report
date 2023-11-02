<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhysiclStockCount extends Model
{
    use NewAuditable;

    protected $guarded = ['id'];

    protected $fillable = [
        'business_location_id',
        'business_id',
        'product_id',
        'variation_id',
        'current_stock',
        'physical_Count',
        'batch_number',
        'lot_no_line_id',
        'user_id',
    ];
}
