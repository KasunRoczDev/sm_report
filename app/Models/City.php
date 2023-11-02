<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    public $table = 'cities';

    protected $guarded = ['id'];
    /**
     * Return list of sale types
     *
     * @param  int  $business_id
     * @return array
     */
}
