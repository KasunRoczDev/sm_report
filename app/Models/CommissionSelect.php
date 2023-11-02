<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionSelect extends Model
{
    public $table = 'commission_selects';

    protected $fillable = ['user_id', 'select_cmmsn_type', 'name'];

    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
