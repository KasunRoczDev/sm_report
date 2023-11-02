<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashRegister extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * Get the Cash registers transactions.
     */
    public function cash_register_transactions()
    {
        return $this->hasMany(CashRegisterTransaction::class);
    }

    /**
     * Check user all ready created the register
     *
     * @return bool
     */
    public static function checkUserAllReadyCreateCashRegister($business_id, $user_id)
    {
        return CashRegister::where('business_id', $business_id)
            ->where('user_id', $user_id)
            ->where('status', 'open')->exists();

    }
}
