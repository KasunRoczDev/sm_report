<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KotHistory extends Model
{
    protected $guarded = ['id'];

    protected $table = 'kitchen_history';

    protected $fillable = [
        'kot_number', 'transaction_id', 'business_id', 'location_id', 'table_no', 'contact_id', 'place_at', 'served_at', 'status',
    ];

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'id');
    }

    public static function check_exist($business_id, $location_id, $kot_number)
    {
        return KotHistory::where('business_id', $business_id)
            ->where('location_id', $location_id)
            ->where('kot_number', $kot_number)
            ->select('id')
            ->first();
    }
}
