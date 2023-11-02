<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotNumberPrice extends Model
{
    protected $table = 'lot_number_prices';

    /**
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * @var string[]
     */
    protected $fillable = ['purchase_line_id', 'variation_id', 'lot_number', 'lot_price'];
    /**
     * @var int|mixed|string
     */
    //    protected $casts = ['lot_number' => 'string'];

    public function variation()
    {
        return $this->belongsTo(Variation::class);
    }
}
