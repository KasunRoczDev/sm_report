<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class PurchaseLine extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variations()
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }

    /**
     * Set the quantity.
     *
     * @param  string  $value
     * @return float $value
     */
    public function getQuantityAttribute($value)
    {
        return (float) $value;
    }

    /**
     * Get the unit associated with the purchase line.
     */
    public function sub_unit()
    {
        return $this->belongsTo(Unit::class, 'sub_unit_id');
    }

    /**
     * Give the quantity remaining for a particular
     * purchase line.
     *
     * @return float $value
     */
    public function getQuantityRemainingAttribute()
    {
        return (float) ($this->quantity - $this->quantity_used);
    }

    /**
     * Give the sum of quantity sold, adjusted, returned.
     *
     * @return float $value
     */
    public function getQuantityUsedAttribute()
    {
        return (float) ($this->quantity_sold + $this->quantity_adjusted + $this->quantity_returned + $this->mfg_quantity_used);
    }

    public function line_tax()
    {
        return $this->belongsTo(TaxRate::class, 'tax_id');
    }

    /**
     * @return HasMany
     */
    public function transactionSellLinesPurchaseLines()
    {
        return $this->hasMany(TransactionSellLinesPurchaseLines::class, 'purchase_line_id', 'id');
    }

    public function scopeGetVariationAvlQtyPlWise($query, $variation_id)
    {
        return $query->where('purchase_lines.variation_id', $variation_id)
            ->select(
                DB::raw('SUM(purchase_lines.quantity-(purchase_lines.quantity_sold+purchase_lines.quantity_adjusted+purchase_lines.quantity_returned+purchase_lines.mfg_quantity_used)) as qty_avl'),
                'purchase_lines.purchase_price_inc_tax'
            )
            ->havingRaw('qty_avl > 0')
            ->groupBy('purchase_lines.id');
    }
}
