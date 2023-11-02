<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class payment_receipt_layout extends Model
{
    protected $guarded = ['id'];

    public function locations()
    {
        return $this->hasMany(BusinessLocation::class);
    }

    /**
     * Returns list of invoice schemes in array format
     */
    public static function forDropdown($business_id)
    {
        $dropdown = payment_receipt_layout::where('business_id', $business_id)
            ->pluck('name', 'id');

        return $dropdown;
    }

    /**
     * Retrieves the default invoice scheme
     */
    public static function getDefault($business_id)
    {
        $default = payment_receipt_layout::where('business_id', $business_id)
            ->where('is_default', 1)
            ->first();

        return $default;
    }
}
