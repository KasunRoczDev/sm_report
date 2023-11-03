<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportQueueData extends Model
{
    protected $connection = 'tenants';

    protected $table = 'report_queue_data';

    protected $fillable = [
        'report_name',
        'filter',
        'expiring_date',
        'data',
        'business_id',
        'user_id',
        'original_data_is_array',
    ];

    protected $casts = [
        'data' => 'json',
        'filter' => 'json',
        'original_data_is_array' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function scopeFilter($query, $filter)
    {
        if (isset($filter['report_name'])) {
            $query->where('report_name', $filter['report_name']);
        }
        if (isset($filter['business_id'])) {
            $query->where('business_id', $filter['business_id']);
        }
        if (isset($filter['user_id'])) {
            $query->where('user_id', $filter['user_id']);
        }
        if (isset($filter['expiring_date'])) {
            $query->where('expiring_date', $filter['expiring_date']);
        }

        return $query;
    }

    public static function scopeSort($query, $sort)
    {
        if (isset($sort['report_name'])) {
            $query->orderBy('report_name', $sort['report_name']);
        }
        if (isset($sort['user_id'])) {
            $query->orderBy('user_id', $sort['user_id']);
        }
        if (isset($sort['expiring_date'])) {
            $query->orderBy('expiring_date', $sort['expiring_date']);
        }

        return $query;
    }

    public static function scopeFilterByBusinessId($query, $business_id)
    {
        return $query->where('business_id', $business_id);
    }

    public static function scopeFilterByUserId($query, $user_id)
    {
        return $query->where('user_id', $user_id);
    }

    public static function scopeFilterByReportName($query, $report_name)
    {
        return $query->where('report_name', $report_name);
    }

    public static function scopeFilterByFilter($query, $filter)
    {
        return $query->where('filter', $filter);
    }

    public static function scopeFilterByCompleted($query)
    {
        return $query->whereNotNull('completed_at');
    }

    public static function reportForDropDown($report_name, $show_none = false)
    {
        return self::filterByReportName($report_name)
            ->filterByBusinessId(auth()->user()->business_id)
            ->filterByCompleted()
            ->orderBy('id', 'DESC')
            ->pluck(
                'filter',
                'id'
            );

    }

    public static function filterLables($records)
    {
        foreach ($records as $key => $value) {
            $rows = json_decode($value['filter'], true);
            $row_string = '';
            foreach ($rows as $filter_name => $filter_value) {
                if ($filter_name == 'business_id') {
                    unset($rows[$filter_name]);

                    continue;
                }
                if ($filter_name == 'user_id') {
                    unset($rows[$filter_name]);

                    continue;
                }

                if ($filter_name == 'date_time_format') {
                    unset($rows[$filter_name]);

                    continue;
                }

                if (empty($filter_value)) {
                    unset($rows[$filter_name]);

                    continue;
                }
                if ($filter_name == 'location_id') {
                    $filter_value = BusinessLocation::find($filter_value)->name;
                }

                if ($filter_name == 'category_id') {
                    $filter_value = Category::find($filter_value)->name;
                }

                if ($filter_name == 'sub_category_id') {
                    $filter_value = Category::find($filter_value)->name;
                }

                if ($filter_name == 'brand_id') {
                    $filter_value = Brands::find($filter_value)->name;
                }

                if ($filter_name == 'unit_id') {
                    $filter_value = Unit::find($filter_value)->actual_name;
                }

                if (is_array($filter_value)) {
                    $filter_value = implode(', ', $filter_value);
                    if (empty($filter_value)) {
                        $filter_value = 'All';
                    }
                }
                $row_string = ! empty($row_string) ? $row_string.'</br>' : $row_string;
                $row_string .= self::filterHumanName($filter_name).'    :&nbsp;'.$filter_value;

            }
            $records[$key]['filter'] = ! empty($row_string) ? $row_string : 'No Filter Applied';
        }

        return $records;
    }

    private static function filterHumanName($filter_name): string
    {
        $names = [
            'location_id' => 'Location',
            'stock_date' => 'Stock Date',
            'category_id' => 'Category',
            'sub_category_id' => 'Sub Category',
            'brand_id' => 'Brand',
            'unit_id' => 'Unit',
            'supplier_id' => 'Supplier',
            'expiry_date' => 'Expiry Date',
            'purchase_date_range' => 'Purchase Date Range',
            'sale_date_range' => 'Sale Date Range',
            'sale_cmsn_agent_id' => 'Sale Commission Agent',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
            'is_direct_sale' => 'Is Direct Sale',
            'payment_status' => 'Payment Status',
            'sale_category' => 'Sale Category',
            'is_woocommerce' => 'Is Woocommerce',
            'only_mfg' => 'Only Manufacturing Products',
        ];

        return $names[$filter_name];
    }

    public static function genaratedReportDropdownonDate($report_name)
    {
        return self::filterByReportName($report_name)
            ->filterByCompleted()
            ->pluck(
                'created_at',
                'id'
            );

    }
}
