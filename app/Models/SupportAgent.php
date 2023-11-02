<?php

namespace App\Models;

use Carbon\Traits\Timestamp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportAgent extends Model
{
    use SoftDeletes;
    use Timestamp;

    protected $fillable = [
        'id',
        'name',
        'created_by',
        'deleted_at',
    ];
}
