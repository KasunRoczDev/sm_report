<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommisonAgentTypes extends Model
{
    public $table = 'commison_agent_types';

    protected $fillable = ['user_id', 'commision_type_id'];

    protected $guarded = ['id'];

    public function commision()
    {
        return $this->belongsTo(CommissionType::class, 'commision_type_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public static function forDropdown($business_id)
    {
        $commision_agent_types = CommisonAgentTypes::where('business_id', $business_id)
            ->get();

        $dropdown = [];

        if (auth()->user()->can('access_default_commision_agent_type')) {
            $dropdown[0] = __('lang_v1.default_commision_agent_type');
        }

        foreach ($commision_agent_types as $commision_agent_type) {
            $dropdown[$commision_agent_type->id] = $commision_agent_type->name;
        }

        return $dropdown;
    }
}
