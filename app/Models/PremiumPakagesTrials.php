<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PremiumPakagesTrials extends Model
{
    protected $fillable = ['business_id', 'trials_start_date', 'premium_pakages_trials', 'trials_end_date'];
}
