<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OverideOTP extends Model
{
    protected $table = 'overide_otps';

    protected $fillable = ['business_id', 'otp', 'user_id'];
}
