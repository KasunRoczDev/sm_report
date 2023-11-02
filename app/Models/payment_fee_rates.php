<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class payment_fee_rates extends Model
{
    use SoftDeletes;
    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * Return list of payment fee dropdown for a business
     *
     * @param $business_id int
     * @param $prepend_none = true (boolean)
     * @param $include_attributes = false (boolean)
     * @return array['payment_fee_rate', 'attributes']
     */
    public static function forBusinessDropdown(
        $business_id,
        $prepend_none = true,
        $include_attributes = false,
        $exclude_for_tax_group = true
    ) {
        $all_payment_fee = payment_fee_rates::where('business_id', $business_id);
        $result = $all_payment_fee->get();
        $payment_fee = $result->pluck('name', 'id');

        //Prepend none
        if ($prepend_none) {
            $payment_fee = $payment_fee->prepend(__('lang_v1.none'), '');
        }

        //Add payment fee attributes
        $payment_fee_attributes = null;
        if ($include_attributes) {
            $payment_fee_attributes = collect($result)->mapWithKeys(function ($item) {
                return [$item->id => ['data-rate' => $item->amount, 'data-interest' => $item->interest]];
            })->all();
        }
        $output = ['payment_fee_rates' => $payment_fee, 'attributes' => $payment_fee_attributes];

        return $output;
    }

    /**
     * Return list of payment fee rate for a business
     *
     * @return array
     */
    public static function forBusiness($business_id)
    {
        $payment_fee = payment_fee_rates::where('business_id', $business_id)
            ->select(['id', 'name', 'amount'])
            ->get()
            ->toArray();

        return $payment_fee;
    }
}
