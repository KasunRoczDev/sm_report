<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionType extends Model
{
    public $table = 'commission_type';

    protected $guarded = ['id'];

    /**
     * Return list of commision types
     *
     * @param  int  $business_id
     * @return array
     */
    public static function forDropdown($business_id)
    {
        $commision_types = CommissionType::where('business_id', $business_id)->where('is_active', '=', 1)
            ->get();

        $dropdown = [];

        if (auth()->user()->can('access_default_commision_type')) {
            $dropdown[0] = __('lang_v1.default_commision_type');
        }

        foreach ($commision_types as $commision_type) {
            $dropdown[$commision_type->id] = $commision_type->name;
        }

        return $dropdown;
    }

    public function saletype($business_id)
    {
        return $this->belongsTo(SaleType::class, 'sale_type_id');

    }

    public function commissionAgentTypes()
    {
        return $this->hasMany(CommisonAgentTypes::class, 'commision_type_id');

    }
}
