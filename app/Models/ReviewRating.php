<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewRating extends Model
{
    public $table = 'review_ratings';

    protected $guarded = ['id'];
    /**
     * Return list of sale types
     *
     * @param  int  $business_id
     * @return array
     */
}
