<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionAgentPayments extends Model
{
    public $table = 'commission_agent_payments';

    protected $fillable = ['transaction_id', 'commission_agent', 'commision', 'commission_payment'];

    protected $guarded = ['id'];
}
