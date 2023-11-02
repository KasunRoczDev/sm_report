<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BarcodeLayoutSetting extends Model
{
    protected $table = 'barcode_layout_setting';

    protected $fillable = [
        'name',
        'description',
        'label_width',
        'label_height',
        'paper_width',
        'paper_height',
        'top_margin',
        'left_margin',
        'row_distance',
        'col_distance',
        'stickers_in_one_row',
        'is_default',
        'is_continuous',
        'stickers_in_one_sheet',
        'business_id',
        'font',
        'product_name_order',
        'product_variation_order',
        'product_price_order',
        'business_name_order',
        'barcode_order',
        'product_name_font_size',
        'product_variation_font_size',
        'product_price_font_size',
        'business_name_font_size',
        'barcode_font_size',
        'product_name',
        'product_variations',
        'product_price',
        'business_name',
    ];

    /**
     * Get the business associated with the barcode layout setting.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
