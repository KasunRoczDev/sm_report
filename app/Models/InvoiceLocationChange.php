<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLocationChange extends Model
{
    public $table = 'invoice_location_changes';

    protected $fillable = ['transaction_id', 'location_to', 'location_from', 'user_id'];

    protected $guarded = ['id'];
}
