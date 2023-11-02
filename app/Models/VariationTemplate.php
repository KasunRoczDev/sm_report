<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Woocommerce\Http\Controllers\WoocommerceVariationAttributeSyncController;

class VariationTemplate extends Model
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

        if (! empty(json_decode(auth()->user()->business->woocommerce_api_settings, true)['enable_woo_auto_sync'])) {
            static::created(function ($variationTemplate) {
                self::syncVariation($variationTemplate);
            });

            static::updated(function ($variationTemplate) {
                self::syncVariation($variationTemplate);
            });
        }
    }

    protected static function syncVariation($variationTemplate)
    {
        if (! empty(auth()->user())) {
            (new WoocommerceVariationAttributeSyncController)->syncVariationAttributes($variationTemplate['business_id'], auth()->user()->id);
        }
    }

    /**
     * Get the attributes for the variation.
     */
    public function values()
    {
        return $this->hasMany(VariationValueTemplate::class);
    }
}
