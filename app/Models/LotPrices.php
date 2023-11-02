<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotPrices extends Model
{
    protected $fillable = ['variation_id', 'lot_number', 'lot_price'];

    /**
     * @var int|mixed|string
     */
    protected $casts = ['lot_number' => 'string'];

    public function variation()
    {
        return $this->belongsTo(Variation::class);
    }
}
