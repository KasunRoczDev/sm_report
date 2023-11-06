<?php

namespace App\Utils;

use App\CashRegister;
use App\Transaction;
use DB;

class CashRegisterUtil extends Util
{
    /**
     * Returns number of opened Cash Registers for the
     * current logged in user
     *
     * @return int
     */
    public function countOpenedRegister()
    {
        $user_id = auth()->user()->id;
        $count = CashRegister::where('user_id', $user_id)
            ->where('status', 'open')
            ->count();

        return $count;
    }

    /**
     * Retrieves details of given rigister id else currently opened register
     *
     * @param $register_id default null
     * @return object
     */
    public function getRegisterDetails($register_id = null)
    {

        $business_id = request()->session()->get('user.business_id');
        $module_util = new \App\Utils\ModuleUtil();
        $is_admin = $module_util->is_admin(auth()->user(), $business_id);

        $query = CashRegister::leftjoin(
            'cash_register_transactions as ct',
            'ct.cash_register_id',
            '=',
            'cash_registers.id'
        )
            ->join(
                'users as u',
                'u.id',
                '=',
                'cash_registers.user_id'
            )
            ->leftJoin(
                'business_locations as bl',
                'bl.id',
                '=',
                'cash_registers.location_id'
            );
        if (empty($register_id)) {
            $user_id = auth()->user()->id;
            $query->where('user_id', $user_id)
                ->where('cash_registers.status', 'open');
        } else {
            $query->where('cash_registers.id', $register_id);
        }

        $register_details = $query->select(
            'cash_registers.id as id',
            'cash_registers.created_at as open_time',
            'cash_registers.closed_at as closed_at',
            'cash_registers.user_id',
            'cash_registers.closing_note',
            'cash_registers.location_id',
            DB::raw("SUM(IF(transaction_type='initial', amount, 0)) as cash_in_hand"),
            DB::raw("SUM(IF(transaction_type='sell', amount, IF(transaction_type='refund', -1 * amount, 0))) as total_sale"),
            DB::raw("SUM(IF(pay_method='cash', IF(transaction_type='sell', amount, 0), 0)) as total_cash"),
            DB::raw("SUM(IF(pay_method='cheque', IF(transaction_type='sell', amount, 0), 0)) as total_cheque"),
            DB::raw("SUM(IF(pay_method='card', IF(transaction_type='sell', amount, 0), 0)) as total_card"),
            DB::raw("SUM(IF(pay_method='bank_transfer', IF(transaction_type='sell', amount, 0), 0)) as total_bank_transfer"),
            DB::raw("SUM(IF(pay_method='other', IF(transaction_type='sell', amount, 0), 0)) as total_other"),
            DB::raw("SUM(IF(pay_method='advance', IF(transaction_type='sell', amount, 0), 0)) as total_advance"),
            DB::raw("SUM(IF(pay_method='custom_pay_1', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_1"),
            DB::raw("SUM(IF(pay_method='custom_pay_2', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_2"),
            DB::raw("SUM(IF(pay_method='custom_pay_3', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_3"),
            DB::raw("SUM(IF(pay_method='custom_pay_4', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_4"),
            DB::raw("SUM(IF(pay_method='custom_pay_5', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_5"),
            DB::raw("SUM(IF(transaction_type='refund', amount, 0)) as total_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='cash', amount, 0), 0)) as total_cash_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='cheque', amount, 0), 0)) as total_cheque_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='card', amount, 0), 0)) as total_card_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='bank_transfer', amount, 0), 0)) as total_bank_transfer_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='other', amount, 0), 0)) as total_other_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='advance', amount, 0), 0)) as total_advance_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='custom_pay_1', amount, 0), 0)) as total_custom_pay_1_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='custom_pay_2', amount, 0), 0)) as total_custom_pay_2_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='custom_pay_3', amount, 0), 0)) as total_custom_pay_3_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='custom_pay_4', amount, 0), 0)) as total_custom_pay_4_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='custom_pay_5', amount, 0), 0)) as total_custom_pay_5_refund"),
            DB::raw("SUM(IF(pay_method='cheque', 1, 0)) as total_cheques"),
            DB::raw("SUM(IF(pay_method='card', 1, 0)) as total_card_slips"),
            DB::raw("CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as user_name"),
            DB::raw("SUM(IF(transaction_type='credit', amount, 0)) as credit_sale"),
            'u.email',
            'bl.name as location_name',
            'cash_registers.card_count_enter_by_user as card_count_enter_by_user',
            'cash_registers.cheque_count_enter_by_user as cheque_count_enter_by_user',
            'cash_registers.cash_amount_enter_by_user as cash_amount_enter_by_user',
            'cash_registers.card_amount_enter_by_user as card_amount_enter_by_user',
            'cash_registers.cheque_amount_enter_by_user as cheque_amount_enter_by_user'
        )->first();
        $user_id = $register_details->user_id;
        $close_time = null;
        if (! empty($register_id) && isset($register_details->closed_at)) {
            $close_time = $register_details->closed_at;
        }

        $sells = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->join(
                'transaction_payments AS TP',
                'transactions.id',
                '=',
                'TP.transaction_id'
            )
            ->where('transactions.business_id', $business_id)
            ->where('transactions.created_by', $user_id)
            ->where('TP.created_by', $user_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->where('transactions.is_direct_sale', 0)
            ->where('TP.is_direct_payment', 0)
            ->where('transactions.finalized_at', '>=', $register_details->open_time)
            ->where('TP.created_at', '>=', $register_details->open_time)
            ->where(function ($query) use ($close_time) {
                if (isset($close_time)) {
                    $query->where('transactions.finalized_at', '<=', $close_time);
                }
            })
            ->select(
                DB::raw("SUM(IF(TP.is_return ='0', amount, -1*amount)) as sell_total"),
                DB::raw("SUM(IF(TP.method='cash', IF( TP.is_return ='0', amount, -1*amount), 0)) as total_cash"),
                DB::raw("SUM(IF(TP.method='cheque', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_cheque"),
                DB::raw("SUM(IF(TP.method='card', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_card"),
                DB::raw("SUM(IF(TP.method='bank_transfer', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_bank_transfer"),
                DB::raw("SUM(IF(TP.method='other', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_other"),
                DB::raw("SUM(IF(TP.method='advance', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_advance"),
                DB::raw("SUM(IF(TP.method='custom_pay_1', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_1"),
                DB::raw("SUM(IF(TP.method='custom_pay_2', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_2"),
                DB::raw("SUM(IF(TP.method='custom_pay_3', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_3"),
                DB::raw("SUM(IF(TP.method='custom_pay_4', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_4"),
                DB::raw("SUM(IF(TP.method='custom_pay_5', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_5"),
                DB::raw("SUM(IF(TP.method='exchange', IF( TP.is_return ='0', amount, -1*amount), 0)) as total_exchange")
                /*   comment for testing purpose*/
                //                'transactions.id',
                //                'transactions.transaction_date',
                //                'transactions.invoice_no',
                //                'transactions.final_total',
                //                'transactions.payment_status',
                //                'transactions.type',
                //                'TP.method',
                //                'TP.amount'

            )
            ->first();

        $all_sells = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')

            ->where('transactions.business_id', $business_id)
            ->where('transactions.created_by', $user_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->where('transactions.is_direct_sale', 0)
            ->where('transactions.finalized_at', '>=', $register_details->open_time)
            ->where(function ($query) use ($close_time) {
                if (isset($close_time)) {
                    $query->where('transactions.finalized_at', '<=', $close_time);
                }
            })
            ->select(
                DB::raw(" SUM(IF(transactions.type='sell', transactions.final_total, 0)) as final_total")
                /*   comment for testing purpose*/
                //                'transactions.id',
                //                'transactions.transaction_date',
                //                'transactions.invoice_no',
                //                'transactions.final_total',
                //                'transactions.payment_status',
                //                'transactions.type',
                //                'transactions.final_total',
                //                'TP.amount',
                ////                'transactions.created_by',
                //                'TP.created_by'

            )->first();

        $all_sells_payment = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->leftjoin(
                'transaction_payments AS TP',
                'transactions.id',
                '=',
                'TP.transaction_id'
            )
            ->where('transactions.business_id', $business_id)
            ->where('transactions.created_by', $user_id)
//          ->where('TP.created_by', $user_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->where('transactions.is_direct_sale', 0)
            ->where('TP.is_direct_payment', 0)
            ->where('TP.created_by', $user_id)
            ->where('transactions.finalized_at', '>=', $register_details->open_time)
            ->where(function ($query) use ($close_time) {
                if (isset($close_time)) {
                    $query->where('transactions.finalized_at', '<=', $close_time);
                }
            })
//            ->where('TP.created_at','>=',$register_details->open_time)
            ->select(
                DB::raw("SUM( IF(TP.is_return ='0', amount, -1*amount)) as final_total")

                /*   comment for testing purpose*/
                //                'transactions.id',
                //                'transactions.transaction_date',
                //                'transactions.invoice_no',
                //                'transactions.final_total',
                //                'transactions.payment_status',
                //                'transactions.type',
                //                'TP.method',
                //                'TP.amount',
                ////                'transactions.created_by',
                //                'TP.created_by'

            )
            ->first();

        $open_time = $register_details->open_time;
        $credit_sells = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->join(
                'transaction_payments AS TP',
                'transactions.id',
                '=',
                'TP.transaction_id'
            )
            ->where('transactions.business_id', $business_id)
            ->where('TP.created_by', $user_id)
            ->where('TP.is_direct_payment', 0)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
       /*    need direct sale ane pos sale*/
//            ->where('transactions.is_direct_sale', 0)
            ->where(function ($query) use ($user_id, $open_time) {
                $query->where('transactions.finalized_at', '<=', $open_time)

                    ->orWhere([['transactions.finalized_at', '>=', $open_time], ['transactions.created_by', '!=', $user_id]])
                    ->orWhere([['transactions.finalized_at', '>=', $open_time], ['transactions.created_by', '=', $user_id], ['transactions.is_direct_sale', 1]]);
            })
            ->where(function ($query) use ($close_time) {
                if (isset($close_time)) {
                    $query->where('TP.created_at', '<=', $close_time);
                }
            })
            ->where('TP.created_at', '>=', $register_details->open_time)
            ->select(
                DB::raw("SUM(IF(transactions.type='sell', IF(TP.is_return ='0', amount, -1*amount), 0)) as credit_total"),
                DB::raw("SUM(IF(TP.method='cash', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_cash"),
                DB::raw("SUM(IF(TP.method='cheque', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_cheque"),
                DB::raw("SUM(IF(TP.method='card', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_card"),
                DB::raw("SUM(IF(TP.method='bank_transfer', IF(TP.is_return ='0', amount, -1*amount), 0))as total_bank_transfer"),
                DB::raw("SUM(IF(TP.method='other', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_other"),
                DB::raw("SUM(IF(TP.method='advance', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_advance"),
                DB::raw("SUM(IF(TP.method='custom_pay_1', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_1"),
                DB::raw("SUM(IF(TP.method='custom_pay_2', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_2"),
                DB::raw("SUM(IF(TP.method='custom_pay_3', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_3"),
                DB::raw("SUM(IF(TP.method='custom_pay_4', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_4"),
                DB::raw("SUM(IF(TP.method='custom_pay_5', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_5")
                /*   comment for testing purpose*/
                //                'transactions.id',
                //                'transactions.transaction_date',
                //                'transactions.invoice_no',
                //                'transactions.final_total',
                //                'transactions.payment_status',
                //                'transactions.type',
                //                'TP.method',
                //                'TP.amount'

            )
            ->first();

        $sells_return = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->join(
                'transaction_payments AS TP',
                'transactions.id',
                '=',
                'TP.transaction_id'
            )
            ->where('transactions.business_id', $business_id)
            ->where('TP.created_by', $user_id)
            ->where('transactions.type', 'sell_return')
            ->where('transactions.status', 'final')
            /*old transaction*/
//            ->where('transactions.created_at','<=',$register_details->open_time)
            ->where('TP.created_at', '>=', $register_details->open_time)
            ->where(function ($query) use ($close_time) {
                if (isset($close_time)) {
                    $query->where('TP.created_at', '<=', $close_time);
                }
            })
            ->select(
                DB::raw("SUM(IF(transactions.type='sell_return', amount, 0)) as sells_return_total"),
                DB::raw("SUM(IF(TP.method='cash' AND TP.is_exchange = 0 , IF(transactions.type='sell_return', amount, 0), 0)) as total_cash"),
                DB::raw("SUM(IF(TP.method='cheque', IF(transactions.type='sell_return', amount, 0), 0)) as total_cheque"),
                DB::raw("SUM(IF(TP.method='card', IF(transactions.type='sell_return', amount, 0), 0)) as total_card"),
                DB::raw("SUM(IF(TP.method='bank_transfer', IF(transactions.type='sell_return', amount, 0), 0)) as total_bank_transfer"),
                DB::raw("SUM(IF(TP.method='other', IF(transactions.type='sell_return', amount, 0), 0)) as total_other"),
                DB::raw("SUM(IF(TP.method='custom_pay_1', IF(transactions.type='sell_return', amount, 0), 0)) as total_custom_pay_1"),
                DB::raw("SUM(IF(TP.method='custom_pay_2', IF(transactions.type='sell_return', amount, 0), 0)) as total_custom_pay_2"),
                DB::raw("SUM(IF(TP.method='custom_pay_3', IF(transactions.type='sell_return', amount, 0), 0)) as total_custom_pay_3"),
                DB::raw("SUM(IF(TP.method='custom_pay_4', IF(transactions.type='sell_return', amount, 0), 0)) as total_custom_pay_4"),
                DB::raw("SUM(IF(TP.method='custom_pay_5', IF(transactions.type='sell_return', amount, 0), 0)) as total_custom_pay_5"),
                DB::raw("SUM(IF(TP.method='exchange' or TP.is_exchange = 1 , IF(transactions.type='sell_return', amount, 0), 0)) as total_exchange")
                /*   comment for testing purpose*/
                //                'transactions.id',
                //                'transactions.transaction_date',
                //                'transactions.invoice_no',
                //                'transactions.final_total',
                //                'transactions.payment_status',
                //                'transactions.type',
                //                'TP.method',
                //                'TP.amount'

            )
            ->first();

        $expenses = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->join(
                'transaction_payments AS TP',
                'transactions.id',
                '=',
                'TP.transaction_id'
            )
            ->where('transactions.business_id', $business_id)
            ->where('transactions.isexpensefrompos', '1')
//            ->where('TP.is_direct_payment', 0)
            ->where('TP.created_by', $user_id)
            ->where('transactions.finalized_at', '>=', $register_details->open_time)
            ->where(function ($query) use ($close_time) {
                if (isset($close_time)) {
                    $query->where('transactions.finalized_at', '<=', $close_time);
                }
            })
            ->select(
                DB::raw("SUM(IF(transactions.type='expense', TP.amount, 0)) as expense_total"),
                DB::raw("SUM(IF(TP.method='cash', IF(transactions.type='expense', TP.amount, 0), 0)) as total_cash"),
                DB::raw("SUM(IF(TP.method='cheque', IF(transactions.type='expense', TP.amount, 0), 0)) as total_cheque"),
                DB::raw("SUM(IF(TP.method='card', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_card"),
                DB::raw("SUM(IF(TP.method='bank_transfer', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_bank_transfer"),
                DB::raw("SUM(IF(TP.method='other', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_other"),
                DB::raw("SUM(IF(TP.method='custom_pay_1', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_custom_pay_1"),
                DB::raw("SUM(IF(TP.method='custom_pay_2', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_custom_pay_2"),
                DB::raw("SUM(IF(TP.method='custom_pay_3', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_custom_pay_3"),
                DB::raw("SUM(IF(TP.method='custom_pay_4', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_custom_pay_4"),
                DB::raw("SUM(IF(TP.method='custom_pay_5', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_custom_pay_5")
                /*   comment for testing purpose*/
                //                'transactions.id',
                //                'transactions.transaction_date',
                //                'transactions.invoice_no',
                //                'transactions.final_total',
                //                'transactions.isexpensefrompos',
                //                'transactions.payment_status',
                //                'transactions.type',
                //                'TP.method',
                //                'TP.amount'

            )
            ->first();

        /*assign expence detailes to register detailed*/
        $register_details['sells'] = $sells;
        $register_details['credit_sells'] = $credit_sells;
        $register_details['sells_return'] = $sells_return;
        $register_details['expense'] = $expenses;
        $register_details['total_cash_inflow'] = $register_details->cash_in_hand + $register_details->sells->total_cash + $register_details->credit_sells->total_cash;
        $register_details['due_sells_final_total'] = $all_sells['final_total'] - $all_sells_payment['final_total'];
        $register_details['net_cash_amount'] = $register_details->cash_in_hand
            + $register_details->sells->total_cash + $register_details->credit_sells->total_cash
            - $register_details->sells_return->total_cash
            - $register_details->expense->total_cash;
        $register_details['total_sales'] = $register_details->sells->sell_total + $register_details->due_sells_final_total;
        $register_details['payment_received'] = $register_details->sells->sell_total; //+$register_details->total_advance;
        $register_details['total_cash'] = $register_details->cash_in_hand
            + $register_details->sells->total_cash + $register_details->credit_sells->total_cash
            - $register_details->sells_return->total_cash
            - $register_details->expense->total_cash;
        $register_details['total_card_amount'] = $register_details->sells->total_card + $register_details->credit_sells->total_card
            - $register_details->total_card_refund - $register_details->sells_return->total_card
            - $register_details->expense->total_card;
        $register_details['total_cheque_amount'] = $register_details->sells->total_cheque + $register_details->credit_sells->total_cheque
            - $register_details->total_cheque_refund - $register_details->sells_return->total_cheque
            - $register_details->expense->total_cheque;
        $register_details['is_admin'] = $is_admin;

        return $register_details;
    }

    /**
     * Get the transaction details for a particular register
     *
     * @param $user_id int
     * @param $open_time datetime
     * @param $close_time datetime
     * @return array
     */
    public function getRegisterTransactionDetails($user_id, $open_time, $close_time, $is_types_of_service_enabled = false)
    {
        $product_details = Transaction::where('transactions.created_by', $user_id)
            ->whereBetween('transaction_date', [$open_time, $close_time])
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->where('transactions.is_direct_sale', 0)
            ->join('transaction_sell_lines AS TSL', 'transactions.id', '=', 'TSL.transaction_id')
            ->join('products AS P', 'TSL.product_id', '=', 'P.id')
            ->leftjoin('brands AS B', 'P.brand_id', '=', 'B.id')
            ->groupBy('B.id')
            ->select(
                'B.name as brand_name',
                DB::raw('SUM(TSL.quantity) as total_quantity'),
                DB::raw('SUM(TSL.unit_price_inc_tax*TSL.quantity) as total_amount')
            )
            ->orderByRaw('CASE WHEN brand_name IS NULL THEN 2 ELSE 1 END, brand_name')
            ->get();

        //If types of service
        $types_of_service_details = null;
        if ($is_types_of_service_enabled) {
            $types_of_service_details = Transaction::where('transactions.created_by', $user_id)
                ->whereBetween('transaction_date', [$open_time, $close_time])
                ->where('transactions.is_direct_sale', 0)
                ->where('transactions.type', 'sell')
                ->where('transactions.status', 'final')
                ->leftjoin('types_of_services AS tos', 'tos.id', '=', 'transactions.types_of_service_id')
                ->groupBy('tos.id')
                ->select(
                    'tos.name as types_of_service_name',
                    DB::raw('SUM(final_total) as total_sales')
                )
                ->orderBy('total_sales', 'desc')
                ->get();
        }

        $transaction_details = Transaction::where('transactions.created_by', $user_id)
            ->whereBetween('transaction_date', [$open_time, $close_time])
            ->where('transactions.type', 'sell')
            ->where('transactions.is_direct_sale', 0)
            ->where('transactions.status', 'final')
            ->select(
                DB::raw('SUM(tax_amount) as total_tax'),
                DB::raw('SUM(IF(discount_type = "percentage", total_before_tax*discount_amount/100, discount_amount)) as total_discount'),
                DB::raw('SUM(final_total) as total_sales')
            )
            ->first();

        return ['product_details' => $product_details,
            'transaction_details' => $transaction_details,
            'types_of_service_details' => $types_of_service_details,
        ];
    }

    /**
     * Retrieves the currently opened cash register for the user
     *
     * @param $int user_id
     * @return obj
     */
    public function getCurrentCashRegister($user_id)
    {
        $register = CashRegister::where('user_id', $user_id)
            ->where('status', 'open')
            ->first();

        return $register;
    }
}
