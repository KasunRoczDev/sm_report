<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecurringInvoiceReminder extends Model
{
    protected $fillable = ['transaction_id', 'recur_reminder_interval', 'recur_reminder_interval_type', 'recur_reminder_repetitions', 'recur_reminder_stopped_on', 'reminder_no', 'reminder_repeat_on'];

    protected $guarded = ['id'];
}
