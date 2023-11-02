<?php

namespace App\Models;

use App\Jobs\CallDBProcedure;
use App\Traits\QueueThisJob;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    //Transaction types = ['purchase','sell','expense','stock_adjustment','sell_transfer','purchase_transfer','opening_stock','sell_return','opening_balance','purchase_return', 'payroll', 'expense_refund']

    //Transaction status = ['received','pending','ordered','draft','final', 'in_transit', 'completed']

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'transactions';

    public function purchase_lines()
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function transferParent()
    {
        return $this->hasOne(Transaction::class, 'transfer_parent_id', 'id');
    }

    public function sell_lines()
    {
        return $this->hasMany(TransactionSellLine::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'id');
    }

    public function payment_lines()
    {
        return $this->hasMany(TransactionPayment::class, 'transaction_id');
    }

    public function location()
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function tax()
    {
        return $this->belongsTo(TaxRate::class, 'tax_id');
    }

    public function stock_adjustment_lines()
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }

    public function sales_person()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function return_parent()
    {
        return $this->hasOne(Transaction::class, 'return_parent_id');
    }

    public function return_parent_sell()
    {
        return $this->belongsTo(Transaction::class, 'return_parent_id');
    }

    public function table()
    {
        return $this->belongsTo(Restaurant\ResTable::class, 'res_table_id');
    }

    public function service_staff()
    {
        return $this->belongsTo(User::class, 'res_waiter_id');
    }

    public function recurring_invoices()
    {
        return $this->hasMany(Transaction::class, 'recur_parent_id');
    }

    public function recurring_parent()
    {
        return $this->hasOne(Transaction::class, 'id', 'recur_parent_id');
    }

    public function price_group()
    {
        return $this->belongsTo(SellingPriceGroup::class, 'selling_price_group_id');
    }

    public function types_of_service()
    {
        return $this->belongsTo(TypesOfService::class, 'types_of_service_id');
    }

    /**
     * Retrieves documents path if exists
     */
    public function getDocumentPathAttribute()
    {
        $path = ! empty($this->document) ? asset('/uploads_new/documents/'.$this->document) : null;

        return $path;
    }

    /**
     * Removes timestamp from document name
     */
    public function getDocumentNameAttribute()
    {
        $document_name = ! empty(explode('_', $this->document, 2)[1]) ? explode('_', $this->document, 2)[1] : $this->document;

        return $document_name;
    }

    public function subscription_invoices()
    {
        return $this->hasMany(Transaction::class, 'recur_parent_id');
    }

    /**
     * Shipping address custom method
     */
    public function shipping_address($array = false)
    {
        $addresses = ! empty($this->order_addresses) ? json_decode($this->order_addresses, true) : [];

        $shipping_address = [];

        if (! empty($addresses['shipping_address'])) {
            if (! empty($addresses['shipping_address']['shipping_name'])) {
                $shipping_address['name'] = $addresses['shipping_address']['shipping_name'];
            }
            if (! empty($addresses['shipping_address']['company'])) {
                $shipping_address['company'] = $addresses['shipping_address']['company'];
            }
            if (! empty($addresses['shipping_address']['shipping_address_line_1'])) {
                $shipping_address['address_line_1'] = $addresses['shipping_address']['shipping_address_line_1'];
            }
            if (! empty($addresses['shipping_address']['shipping_address_line_2'])) {
                $shipping_address['address_line_2'] = $addresses['shipping_address']['shipping_address_line_2'];
            }
            if (! empty($addresses['shipping_address']['shipping_city'])) {
                $shipping_address['city'] = $addresses['shipping_address']['shipping_city'];
            }
            if (! empty($addresses['shipping_address']['shipping_state'])) {
                $shipping_address['state'] = $addresses['shipping_address']['shipping_state'];
            }
            if (! empty($addresses['shipping_address']['shipping_country'])) {
                $shipping_address['country'] = $addresses['shipping_address']['shipping_country'];
            }
            if (! empty($addresses['shipping_address']['shipping_zip_code'])) {
                $shipping_address['zipcode'] = $addresses['shipping_address']['shipping_zip_code'];
            }
        }

        if ($array) {
            return $shipping_address;
        } else {
            return implode(', ', $shipping_address);
        }
    }

    /**
     * billing address custom method
     */
    public function billing_address($array = false)
    {
        $addresses = ! empty($this->order_addresses) ? json_decode($this->order_addresses, true) : [];

        $billing_address = [];

        if (! empty($addresses['billing_address'])) {
            if (! empty($addresses['billing_address']['billing_name'])) {
                $billing_address['name'] = $addresses['billing_address']['billing_name'];
            }
            if (! empty($addresses['billing_address']['company'])) {
                $billing_address['company'] = $addresses['billing_address']['company'];
            }
            if (! empty($addresses['billing_address']['billing_address_line_1'])) {
                $billing_address['address_line_1'] = $addresses['billing_address']['billing_address_line_1'];
            }
            if (! empty($addresses['billing_address']['billing_address_line_2'])) {
                $billing_address['address_line_2'] = $addresses['billing_address']['billing_address_line_2'];
            }
            if (! empty($addresses['billing_address']['billing_city'])) {
                $billing_address['city'] = $addresses['billing_address']['billing_city'];
            }
            if (! empty($addresses['billing_address']['billing_state'])) {
                $billing_address['state'] = $addresses['billing_address']['billing_state'];
            }
            if (! empty($addresses['billing_address']['billing_country'])) {
                $billing_address['country'] = $addresses['billing_address']['billing_country'];
            }
            if (! empty($addresses['billing_address']['billing_zip_code'])) {
                $billing_address['zipcode'] = $addresses['billing_address']['billing_zip_code'];
            }
        }

        if ($array) {
            return $billing_address;
        } else {
            return implode(', ', $billing_address);
        }
    }

    public function cash_register_payments()
    {
        return $this->hasMany(CashRegisterTransaction::class);
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'model');
    }

    public function transaction_for()
    {
        return $this->belongsTo(User::class, 'expense_for');
    }

    /**
     * Returns the list of discount types.
     */
    public static function discountTypes()
    {
        return [
            'fixed' => __('lang_v1.fixed'),
            'percentage' => __('lang_v1.percentage'),
        ];
    }

    public static function transactionTypes()
    {
        return [
            'sell' => __('sale.sale'),
            'purchase' => __('lang_v1.purchase'),
            'sell_return' => __('lang_v1.sell_return'),
            'purchase_return' => __('lang_v1.purchase_return'),
            'opening_balance' => __('lang_v1.opening_balance'),
            'payment' => __('lang_v1.payment'),
        ];
    }

    public static function getPaymentStatus($transaction)
    {
        $payment_status = $transaction->payment_status;

        if (in_array($payment_status, ['partial', 'due']) && ! empty($transaction->pay_term_number) && ! empty($transaction->pay_term_type)) {
            $transaction_date = Carbon::parse($transaction->transaction_date);
            $due_date = $transaction->pay_term_type == 'days' ? $transaction_date->addDays($transaction->pay_term_number) : $transaction_date->addMonths($transaction->pay_term_number);
            $now = Carbon::now();
            if ($now->gt($due_date)) {
                $payment_status = $payment_status == 'due' ? 'overdue' : 'partial-overdue';
            }
        }

        return $payment_status;
    }

    /**
     * Due date custom attribute
     */
    public function getDueDateAttribute()
    {
        $due_date = null;
        if (! empty($this->pay_term_type) && ! empty($this->pay_term_number)) {
            $transaction_date = Carbon::parse($this->transaction_date);
            $due_date = $this->pay_term_type == 'days' ? $transaction_date->addDays($this->pay_term_number) : $transaction_date->addMonths($this->pay_term_number);
        }

        return $due_date;
    }

    public function adminResuestMinPiceSale()
    {
        return $this->hasMany(AdminApproveActions::class, 'transaction_id');
    }

    public function getTotalExchangeAmount()
    {
        //$this->payment_lines()->get()
        return $this->payment_lines()
            ->selectRaw('sum(IF(method = "exchange", amount, 0)) as exchange_amount')
            ->first()->exchange_amount;
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function ($model) {
            $newId = $model->id;
            $model->finalized_at = $model->created_at;
            $model->save();
            //self::db_mismatch_procedure_call($newId);
        });

        static::updated(function ($model) {
            $updatedId = $model->id;
            //self::db_mismatch_procedure_call($updatedId);
        });

        static::saving(function ($model) {
            $originalStatus = $model->getOriginal('status');
            $updatedStatus = $model->status;

            if ($originalStatus === 'draft' && $updatedStatus === 'final') {
                $model->finalized_at = $model->freshTimestamp();
            }
        });
    }

    public static function db_mismatch_procedure_call($id)
    {
        $data['transaction_id'] = $id;
        QueueThisJob::process_this_job(CallDBProcedure::class, $data, 'db_procedure');
    }
}
