<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleType_Multi extends Model
{
    public $table = 'sale_type_multis';

    protected $guarded = ['id'];

    /**
     * Return list of multi sale types
     *
     * @param  int  $business_id
     * @return array
     */
    public static function forDropdown($business_id)
    {
        $multi_sale_types = SaleType_Multi::where('business_id', $business_id)->where('is_active', '=', 1)
            ->get();

        $dropdown = [];

        foreach ($multi_sale_types as $multi_sale_type) {
            $dropdown[$multi_sale_type->id] = $multi_sale_type->name;
        }

        return $dropdown;
    }
}
