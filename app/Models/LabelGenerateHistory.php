<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabelGenerateHistory extends Model
{
    protected $table = 'label_generate_history';

    protected $fillable = [
        'user_id',
        'business_id',
        'path_and_filename',
        'layout_id',
        'label_count',
        'request_at',
    ];
}
