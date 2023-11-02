<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleType extends Model
{
    public $table = 'sale_type';

    protected $guarded = ['id'];

    /**
     * Return list of sale types
     *
     * @param  int  $business_id
     * @return array
     */
    public static function forDropdown($business_id)
    {
        $sale_types = SaleType::where('business_id', $business_id)->where('is_active', '=', 1)
            ->get();

        $dropdown = [];

        if (auth()->user()->can('access_default_sale_type')) {
            $dropdown[0] = __('lang_v1.default_sale_type');
        }

        foreach ($sale_types as $sale_type) {
            $dropdown[$sale_type->id] = $sale_type->name;
        }

        return $dropdown;
    }
}
