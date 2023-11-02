<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Courier_company extends Model
{
    public $table = 'courier_companies';

    protected $guarded = ['id'];

    public function locations()
    {
        return $this->hasMany(BusinessLocation::class);
    }

    public static function forDropdown($business_id)
    {
        $dropdown = Courier_company::where('business_id', $business_id)
            ->pluck('name', 'id');

        return $dropdown;
    }
}
