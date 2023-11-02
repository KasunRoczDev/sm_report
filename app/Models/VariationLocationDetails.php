<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Woocommerce\Http\Controllers\WoocommerceProductSyncController;

class VariationLocationDetails extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    protected static function boot()
    {
        parent::boot();

        if (isset(auth()->user()->business->woocommerce_api_settings) && ! empty(json_decode(auth()->user()->business->woocommerce_api_settings, true)['enable_woo_auto_sync'])) {
            static::created(function ($model) {
                self::syncVariation($model);
            });

            static::updated(function ($model) {
                self::syncVariation($model);
            });
        }
    }

    protected static function syncVariation($model)
    {
        if (! empty(auth()->user()) && isset(auth()->user()->business_id) && isset(auth()->user()->id) && isset($model->variation_id)) {
            $product = [
                'business_id' => auth()->user()->business_id,
                'user_id' => auth()->user()->id,
                'variation_id' => $model->variation_id,
            ];
            (new WoocommerceProductSyncController())->syncProducts($product);
        }
    }
}
