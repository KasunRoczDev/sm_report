<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Variation extends Model
{
    use SoftDeletes;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'combo_variations' => 'array',
        'grouped_products' => 'array',
    ];

    public function product_variation()
    {
        return $this->belongsTo(ProductVariation::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Get the sell lines associated with the variation.
     */
    public function sell_lines()
    {
        return $this->hasMany(TransactionSellLine::class);
    }

    /**
     * Get the location wise details of the the variation.
     */
    public function variation_location_details()
    {
        return $this->hasMany(VariationLocationDetails::class);
    }

    /**
     * Get Selling price group prices.
     */
    public function group_prices()
    {
        return $this->hasMany(VariationGroupPrice::class, 'variation_id');
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'model');
    }

    public function getFullNameAttribute()
    {
        $name = $this->product->name;
        if ($this->product->type == 'variable') {
            $name .= ' - '.$this->product_variation->name.' - '.$this->name;
        }
        $name .= ' ('.$this->sub_sku.')';

        return $name;
    }

    public function belonglotNumber()
    {
        return $this->hasMany(PurchaseLine::class);
    }
}
