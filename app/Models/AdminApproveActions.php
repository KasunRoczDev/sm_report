<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AdminApproveActions extends Model
{
    protected $guarded = ['id'];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id');
    }

    public static function scopeGetNotApprovedCount($query, $transaction_id)
    {
        return $query->where('transaction_id', $transaction_id)
            ->where('status', '!=', 'approved')
            ->whereNotNull('variation_id')
            ->count();
    }

    public static function scopeGetNotApprovedProduct($query, int $transaction_id)
    {
        return $query->where('transaction_id', $transaction_id)
            ->where('status', '!=', 'approved')
            ->whereNotNull('variation_id')
            ->pluck('sell_line_id')
            ->toArray();
    }

    public static function scopeGetApprovedProductWithPrice($query, $transaction_id)
    {

        $approved_product_with_price = $query->where('transaction_id', $transaction_id)
            ->where('status', 'approved')
            ->whereNotNull('variation_id')
            ->pluck('sell_line_id',
                DB::raw("JSON_EXTRACT(request_note, '$.product_actual_sell_price') as approved_price")
            )
            ->toArray();
        // array_flip to make variation_id as key and approved_price as value
        $approved_product_with_price = array_flip($approved_product_with_price);

        // array_map to convert approved_price from string to float , and remove double &quot; from string
        return array_map(function ($item) {
            return floatval(str_replace('"', '', $item));
        }, $approved_product_with_price);

    }

    public static function scopeLatestOverrideRequest($query, $transaction_id)
    {
        $query->where('transaction_id', $transaction_id)
            ->whereNotNull('variation_id');
    }
}
