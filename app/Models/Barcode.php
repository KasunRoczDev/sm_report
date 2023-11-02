<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Barcode extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    //    protected $appends = ['full_name'];
    //    protected $fillable=['name', 'description', 'id', 'is_default'];
    //    public function getFullNameAttribute()
    //    {
    //        return "";
    //    }
}
