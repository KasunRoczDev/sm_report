<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessPackagePrice extends Model
{
    protected $table = 'business_package_prices';

    protected $fillable = ['business_id', 'package_id', 'price', 'old_price'];

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id');
    }
}
