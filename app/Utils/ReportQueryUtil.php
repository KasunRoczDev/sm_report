<?php

namespace App\Utils;

use App\Contact;
use App\Product;
use App\ReportQueueData;
use App\Transaction;
use App\TransactionSellLinesPurchaseLines;
use App\Unit;
use App\Variation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportQueryUtil extends Util
{
    private ProductUtil $productUtil;

    private $moduleUtil;

    public function __construct()
    {
        $this->productUtil = new ProductUtil();
        $this->moduleUtil = new ModuleUtil();
    }

    /**
     * Converts date in business format to mysql format
     *
     * @param  bool  $time  (default = false)
     */
    public function auth_pass_uf_date($date_time_format, string $date, bool $time = false): ?string
    {
        $date_format = $date_time_format['date'];
        $mysql_format = 'Y-m-d';

        if ($time) {
            if ($date_time_format['time'] == 12) {
                $date_format = $date_format.' h:i A';
            } else {
                $date_format = $date_format.' H:i';
            }
            $mysql_format = 'Y-m-d H:i:s';
        }

        return ! empty($date_format) ? Carbon::createFromFormat($date_format, $date)->format($mysql_format) : null;
    }

    /**
     * Report Queue Data Return Function Do Not Change
     */
    public function queueReportData($report_name, $report_id = null)
    {
        $report = ReportQueueData::filterByBusinessId(auth()->user()->business_id)
            ->filterByReportName($report_name)
            ->filterByUserId(auth()->user()->id)
            ->filterByCompleted()
            ->when(! empty($report_id), function ($query) use ($report_id) {
                return $query->where('id', $report_id);
            })
            ->orderBy('id', 'desc');

        if (empty($report) || $report->count() == 0) {
            return [];
        }

        if ($report->count() > 1 && ! empty($report_id)) {
            $report = $report->where('id', $report_id);
        }

        if (! ($report->first()->original_data_is_array)) {
            $items = $report->first()->data;

            return $this->array_to_collection($items);
        }

        return $report->first()->data;
    }

    public function product_wise_stock_report($filter): array
    {
        $business_id = $filter['business_id'];
        $location_id = ! empty($filter['location_id']) ? $filter['location_id'] : null;
        $end_date = $this->auth_pass_uf_date($filter['date_time_format'], $filter['stock_date'], true);
        $location = 'AND t1.location_id = '.$location_id;
        if (empty($location_id)) {
            $location = 'AND t1.business_id = '.$business_id;
        }

        $query_string = "SELECT
  temp2.*,
  (temp2.purchase + temp2.sell_return + temp2.stock_transfer_in + temp2.opening_stock + temp2.production_purchase - temp2.sold - temp2.pu_re_1 - temp2.pu_re_2 - temp2.stock_adjustment - temp2.stock_transfer_out - temp2.production_sell) AS 'total'
FROM (SELECT
    temp.product_name,
    temp.gloable_variation_id,
    temp.sub_sku as sku,
    temp.unit,
    ROUND(IF(temp.sold IS NULL, 0, temp.sold),4) AS sold,
    ROUND(IF(temp.sell_return IS NULL, 0, temp.sell_return),4) AS sell_return,
    ROUND(IF(temp.purchase_return IS NULL, 0, temp.purchase_return),4) AS pu_re_1,
    ROUND(IF(temp.purchase_return_direct IS NULL, 0, temp.purchase_return_direct),4) AS pu_re_2,
    ROUND(IF(temp.stock_adjustment IS NULL, 0, temp.stock_adjustment),4) AS stock_adjustment,
    ROUND(IF(temp.purchase IS NULL, 0, temp.purchase),4) AS purchase,
    ROUND(IF(temp.opening_stock IS NULL, 0, temp.opening_stock),4) AS opening_stock,
    ROUND(IF(temp.transfer_out IS NULL, 0, temp.transfer_out),4) AS stock_transfer_out,
    ROUND(IF(temp.transfer_in IS NULL, 0, temp.transfer_in),4) AS stock_transfer_in,
    ROUND(IF(temp.production_sell IS NULL, 0, temp.production_sell),4) AS production_sell,
    ROUND(IF(temp.production_purchase IS NULL, 0, temp.production_purchase),4) AS production_purchase
  FROM (SELECT
      v.id AS gloable_variation_id,
      v.sub_sku,
      u.short_name AS unit,
      IF(v.name = 'DUMMY', p.name, CONCAT(p.name, '-', v.name)) AS product_name,
      COALESCE((SELECT
          SUM(pl1.quantity)
        FROM purchase_lines pl1
          INNER JOIN transactions t1
            ON pl1.transaction_id = t1.id
            AND t1.status = 'received'
            AND t1.type = 'purchase'
            AND v.id = pl1.variation_id
            AND t1.transaction_date < '$end_date' $location)) AS purchase,
      COALESCE((SELECT
          SUM(pl2.quantity)
        FROM purchase_lines pl2
          INNER JOIN transactions t1
            ON pl2.transaction_id = t1.id
            AND t1.type = 'opening_stock'
            AND pl2.variation_id = v.id
            AND t1.transaction_date < '$end_date' $location)) AS opening_stock,
      COALESCE((SELECT
          SUM(pl4.quantity)
        FROM purchase_lines pl4
          INNER JOIN transactions t1
            ON pl4.transaction_id = t1.id
            AND t1.type = 'production_purchase'
            AND t1.status = 'received'
            AND pl4.variation_id = v.id
            AND t1.transaction_date < '$end_date' $location)) AS production_purchase,   
      COALESCE((SELECT
          SUM(tsl.quantity)
        FROM transaction_sell_lines tsl
          INNER JOIN transactions t1
            ON tsl.transaction_id = t1.id
            AND t1.status = 'final'
            AND t1.type = 'sell'
            AND tsl.variation_id = v.id
            AND t1.transaction_date < '$end_date' $location)) AS sold,
      COALESCE((SELECT
          SUM(tsl.quantity)
        FROM transaction_sell_lines tsl
          INNER JOIN transactions t1
            ON tsl.transaction_id = t1.id
            AND t1.status = 'final'
            AND t1.type = 'production_sell'
            AND tsl.variation_id = v.id
            AND t1.transaction_date < '$end_date' $location)) AS production_sell,
      COALESCE((SELECT
          SUM(tsl.quantity_returned)
        FROM transaction_sell_lines tsl
          INNER JOIN transactions t3
            ON tsl.transaction_id = t3.id
          INNER JOIN transactions t1
            ON t3.id = t1.return_parent_id
            AND t1.type = 'sell_return'
            AND tsl.variation_id = v.id
            AND t1.transaction_date < '$end_date' $location)) AS sell_return,
      COALESCE((SELECT
          SUM(pl3.quantity)
        FROM purchase_lines pl3
          INNER JOIN transactions t1
            ON pl3.transaction_id = t1.id
            AND t1.type = 'purchase_transfer'
            AND t1.status = 'received'
            AND pl3.variation_id = v.id
            AND t1.transaction_date < '$end_date' $location)) AS transfer_in,
      COALESCE((SELECT
          SUM(tsl.quantity)
        FROM transaction_sell_lines tsl
          INNER JOIN transactions t1
            ON tsl.transaction_id = t1.id
            AND t1.status IN ('final', 'in_transit')
            AND t1.type = 'sell_transfer'
            AND tsl.variation_id = v.id
            AND t1.transaction_date < '$end_date' $location)) AS transfer_out,
      COALESCE((SELECT
          SUM(pl1.quantity_returned)
        FROM transactions t1
          INNER JOIN transactions t2
            ON t1.return_parent_id = t2.id
          INNER JOIN purchase_lines pl1
            ON t2.id = pl1.transaction_id
            AND pl1.variation_id = v.id
            AND t1.type = 'purchase_return'
            AND t1.transaction_date < '$end_date' $location)) AS purchase_return,
      COALESCE((SELECT
          SUM(pl1.quantity_returned)
        FROM transactions t1
          INNER JOIN purchase_lines pl1
            ON t1.id = pl1.transaction_id
            AND pl1.variation_id = v.id
            AND t1.type = 'purchase_return'
            AND t1.transaction_date < '$end_date' $location)) AS purchase_return_direct,
      COALESCE((SELECT
          SUM(sal.quantity)
        FROM stock_adjustment_lines sal
          INNER JOIN transactions t1
            ON sal.transaction_id = t1.id
            AND t1.type = 'stock_adjustment'
            AND sal.variation_id = v.id
            AND t1.transaction_date < '$end_date' $location)) AS stock_adjustment
    FROM variations v
      INNER JOIN products p
        ON v.product_id = p.id
      INNER JOIN units u
        ON p.unit_id = u.id
    WHERE p.business_id = '$business_id'
    GROUP BY v.id) temp
  GROUP BY temp.gloable_variation_id) temp2";

        $this->dynamicDatabaseConnection('reports_landlord', $filter['database']);

        $data = DB::connection('reports_landlord')->select($query_string);

        return [
            'data' => $data,
            'query_string' => $query_string,
        ];

    }

    public function items_report($filter)
    {
        return TransactionSellLinesPurchaseLines::on('reports')->leftJoin('transaction_sell_lines
                    as SL', 'SL.id', '=', 'transaction_sell_lines_purchase_lines.sell_line_id')
            ->leftJoin('transactions as sale', 'SL.transaction_id', '=', 'sale.id')
            ->join('purchase_lines as PL', 'PL.id', '=', 'transaction_sell_lines_purchase_lines.purchase_line_id')
            ->join('transactions as purchase', 'PL.transaction_id', '=', 'purchase.id')
            ->join('business_locations as bl', 'purchase.location_id', '=', 'bl.id')
            ->join(
                'variations as v',
                'PL.variation_id',
                '=',
                'v.id'
            )
            ->join('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
            ->join('products as p', 'PL.product_id', '=', 'p.id')
            ->join('units as u', 'p.unit_id', '=', 'u.id')
            ->leftJoin('contacts as suppliers', 'purchase.contact_id', '=', 'suppliers.id')
            ->leftJoin('contacts as customers', 'sale.contact_id', '=', 'customers.id')
            ->leftJoin('categories as category', 'p.category_id', '=', 'category.id')
            ->join('users as user', 'user.id', '=', 'sale.created_by')
            ->leftJoin('users as agent', 'agent.id', '=', 'sale.commission_agent')
            ->where('purchase.business_id', $filter['business_id'])
            ->where('sale.type', 'sell')
            ->whereBetween('purchase.transaction_date',
                [(string) $filter['purchase_start'], (string) $filter['purchase_end']])
            ->where(function ($query) use ($filter) {
                $query->whereBetween('sale.transaction_date',
                    [(string) $filter['sale_start'], (string) $filter['sale_end']]);
                //                    ->orWhere(function ($qr) use ($filter) {
                ////                        $qr->whereBetween('stock_adjustment.transaction_date',
                ////                            [(string) $filter['sale_start'], (string) $filter['sale_end']]);
                //                    });
            })
            ->when(! empty($filter['supplier_id']), function ($query) use ($filter) {
                $query->where('suppliers.id', $filter['supplier_id']);
            })
            ->when(! empty($filter['customer_id']), function ($query) use ($filter) {
                $query->where('customers.id', $filter['customer_id']);
            })
            ->when(! empty($filter['location_id']), function ($query) use ($filter) {
                $query->where('purchase.location_id', $filter['location_id']);
            })
            ->when(! empty($filter['category_id']), function ($query) use ($filter) {
                $query->where('p.category_id', $filter['category_id']);
            })
            ->when(! empty($filter['only_mfg_products']) && $filter['only_mfg_products'] == 1,
                function ($query) {
                    $query->where('purchase.type', 'production_purchase');
                })
            ->when(! empty($filter['sale_cmsn_agent_id']), function ($query) use ($filter) {
                $query->where('sale.commission_agent', $filter['sale_cmsn_agent_id']);
            })
            ->select(
                'v.sub_sku as sku',
                'p.type as product_type',
                'p.name as product_name',
                'v.name as variation_name',
                'category.name as category_name',
                'pv.name as product_variation',
                'u.short_name as unit',
                'purchase.transaction_date as purchase_date',
                'purchase.ref_no as purchase_ref_no',
                'purchase.type as purchase_type',
                'suppliers.name as supplier',
                'PL.purchase_price_inc_tax as purchase_price',
                'sale.transaction_date as sell_date',
                'sale.invoice_no as sale_invoice_no',
                'customers.name as customer',
                'transaction_sell_lines_purchase_lines.quantity as quantity',
                'SL.unit_price_inc_tax as selling_price',
                'transaction_sell_lines_purchase_lines.stock_adjustment_line_id',
                'transaction_sell_lines_purchase_lines.sell_line_id',
                'transaction_sell_lines_purchase_lines.purchase_line_id',
                'transaction_sell_lines_purchase_lines.qty_returned',
                'bl.name as location',
                'user.first_name as user',
                'agent.first_name as agent'
            );
    }

    public function sell_report($filter)
    {
        $sells = Transaction::on('reports_landlord')->leftJoin('contacts', 'transactions.contact_id', '=',
            'contacts.id')
            // ->leftJoin('transaction_payments as tp', 'transactions.id', '=', 'tp.transaction_id')
            ->leftJoin('transaction_sell_lines as tsl', 'transactions.id', '=', 'tsl.transaction_id')
            ->leftJoin('users as u', 'transactions.created_by', '=', 'u.id')
            ->leftJoin('users as ss', 'transactions.res_waiter_id', '=', 'ss.id')
            ->leftJoin('res_tables as tables', 'transactions.res_table_id', '=', 'tables.id')
            ->leftJoin('commission_agent_payments AS cap', 'transactions.id', '=', 'cap.transaction_id')
            ->leftJoin('multi_sale_types AS msts', 'transactions.id', '=', 'msts.transaction_id')
            ->join(
                'business_locations AS bl',
                'transactions.location_id',
                '=',
                'bl.id'
            )
            ->leftJoin(
                'transactions AS SR',
                'transactions.id',
                '=',
                'SR.return_parent_id'
            )
            ->leftJoin(
                'types_of_services AS tos',
                'transactions.types_of_service_id',
                '=',
                'tos.id'
            )->leftJoin(
                'sale_type AS st',
                'transactions.sale_type_id',
                '=',
                'st.id'
            )
            ->leftJoin(
                'courier_companies AS co_c',
                'transactions.courier_company_id',
                '=',
                'co_c.id'
            )
            ->leftJoin(
                'users AS comi_agent',
                'transactions.commission_agent',
                '=',
                'comi_agent.id'
            )
            ->where('transactions.business_id', $filter['business_id'])
            ->where('transactions.status', 'final')
            ->when(! empty($filter['created_by']), function ($query) use ($filter) {
                $query->where('transactions.created_by', $filter['created_by']);
            })
            ->when(! empty($filter['view_own_sell_only']), function ($query) use ($filter) {
                $query->where('transactions.created_by', $filter['user_id']);
            })
            ->when(! empty($filter['location_id']), function ($query) use ($filter) {
                $query->where('transactions.location_id', $filter['location_id']);
            })
            ->when(! empty($filter['sale_type']), function ($query) use ($filter) {
                $query->where('transactions.sale_type_id', $filter['sale_type']);
            })
            ->when(! empty($filter['multi_sale_type']), function ($query) use ($filter) {
                $query->whereIn('msts.sale_type', $filter['multi_sale_type']);
            })
            ->when(! empty($filter['rewards_only']), function ($query) {
                $query->whereNotNull('transactions.rp_earned')
                    ->orWhere('transactions.rp_redeemed', '>', 0);
            })
            ->when(! empty($filter['customer_id']), function ($query) use ($filter) {
                $query->where('contacts.id', $filter['customer_id']);
            })
            ->when(! empty($filter['start_date']) && ! empty($filter['end_date']), function ($query) use ($filter) {
                $query->whereDate('transactions.transaction_date', '>=', $filter['start_date'])
                    ->whereDate('transactions.transaction_date', '<=', $filter['end_date']);
            })
            ->when(! empty($filter['is_direct_sale']) && $filter['is_direct_sale'] == 0,
                function ($query) {
                    $query->where('transactions.is_direct_sale', 0)
                        ->whereNull('transactions.sub_type');
                })
            ->when(! empty($filter['commission_agent']), function ($query) use ($filter) {
                $query->where('transactions.commission_agent', $filter['commission_agent']);
            })
            ->addSelect('transactions.shopify_order_id')
            ->when($filter['is_woocommerce'], function ($query) use ($filter) {
                $query->addSelect('transactions.woocommerce_order_id')
                    ->when(! empty($filter['only_woocommerce_sells']) && $filter['is_direct_sale'] == 0,
                        function ($sub_query) {
                            $sub_query->whereNotNull('transactions.woocommerce_order_id');
                        })
                    ->when($filter['is_direct_sale'] == 1, function ($sub_query) {
                        $sub_query->whereNull('transactions.woocommerce_order_id');
                    });
            })
            ->when(! empty($filter['only_subscriptions']), function ($query) {
                $query->whereNotNull('transactions.recur_parent_id')
                    ->orWhere('transactions.is_recurring', 1);
            })
            ->when(! empty($filter['service_staff_report']), function ($query) {
                $query->whereNotNull('transactions.res_waiter_id');
            })
            ->when(! empty($filter['res_waiter_id']), function ($query) use ($filter) {
                $query->where('transactions.res_waiter_id', $filter['res_waiter_id']);
            })
            ->when(! empty($filter['sub_type']), function ($query) use ($filter) {
                $query->where('transactions.sub_type', $filter['sub_type']);
            })
            ->when(! empty($filter['created_by']), function ($query) use ($filter) {
                $query->where('transactions.created_by', $filter['created_by']);
            })
            ->when(! empty($filter['sales_cmsn_agnt']), function ($query) use ($filter) {
                $query->where('transactions.commission_agent', $filter['sales_cmsn_agnt']);
            })
            ->when(! empty($filter['service_staffs']), function ($query) use ($filter) {
                $query->where('transactions.res_waiter_id', $filter['service_staffs']);
            })
            ->when(! empty($filter['only_shipments']), function ($query) {
                $query->whereNotNull('transactions.shipping_status');
            })
            ->when(! empty($filter['shipping_status']), function ($query) use ($filter) {
                $query->where('transactions.shipping_status', $filter['shipping_status']);
            })
            ->when(! empty($filter['b2b_sales']), function ($query) {
                $query->where('transactions.is_mapping_transaction', 1);
            })
            ->when(! empty($filter['subscription']), function ($query) {
                $query->addSelect('transactions.is_recurring', 'transactions.recur_parent_id');
            })
            ->when(! empty($filter['reminder']), function ($query) {
                $query->addSelect('transactions.is_recurring_reminder');
            });

        if (! empty($filter['sale_category'])) {
            $sale_categorys = $filter['sale_category'];
            $search_woocommerce = 0;
            $search_cod = 0;
            $search_shopify = 0;
            $search_suspend = 0;
            $search_sale_return = 0;
            $search_exchange = 0;
            $search_subscription = 0;
            $search_is_direct_sale = 0;
            foreach ($sale_categorys as $sale_category) {
                if ($sale_category == 'woocommerce') {
                    $search_woocommerce = 1;
                }
                if ($sale_category == 'cod') {
                    $search_cod = 1;
                }
                if ($sale_category == 'shopify') {
                    $search_shopify = 1;
                }
                if ($sale_category == 'suspend') {
                    $search_suspend = 1;
                }
                if ($sale_category == 'sale_return') {
                    $search_sale_return = 1;
                }
                if ($sale_category == 'exchange') {
                    $search_exchange = 1;
                }
                if ($sale_category == 'subscriptions') {
                    $search_subscription = 1;
                }
                if ($sale_category == 'direct_sale') {
                    $search_is_direct_sale = 1;
                }
            }

            $msc = [
                $search_woocommerce, $search_cod, $search_shopify, $search_suspend,
                $search_sale_return, $search_exchange, $search_subscription, $search_is_direct_sale,
            ];
            $msc_string = implode('', $msc);

            $sells->where(function ($sub_sale) use ($msc_string) {
                if (substr($msc_string, 0, 1)) {
                    $sub_sale->orWhereNotNull('transactions.woocommerce_order_id');
                }
                if (substr($msc_string, 1, 1)) {
                    $sub_sale->orWhere('transactions.is_cod', 1);
                }
                if (substr($msc_string, 2, 1)) {
                    $sub_sale->orWhereNotNull('transactions.shopify_order_id');
                }
                if (substr($msc_string, 3, 1)) {
                    $sub_sale->orWhere('transactions.is_suspend', 1);
                }
                if (substr($msc_string, 4, 1)) {
                    $sub_sale->Join(
                        'transactions AS SR',
                        'transactions.id',
                        '=',
                        'SR.return_parent_id'
                    )->orWhere('SR.type', 'sell_return')
                        ->whereNotIn('SR.is_exchange', [1]);
                }
                if (substr($msc_string, 5, 1)) {
                    $sub_sale->Join(
                        'transactions AS SR',
                        'transactions.id',
                        '=',
                        'SR.return_parent_id'
                    )->orWhere('SR.is_exchange', 1);
                }
                if (substr($msc_string, 6, 1)) {
                    $sub_sale->orWhere('transactions.is_recurring', 1);
                }
                if (substr($msc_string, 7, 1)) {
                    $sub_sale->orWhere('transactions.is_direct_sale', 1);
                }
            });
        }

        $sells->select(
            'transactions.id',
            'transactions.transaction_date',
            'transactions.is_direct_sale',
            'transactions.invoice_no',
            'transactions.invoice_no as invoice_no_text',
            'contacts.name',
            'contacts.mobile',
            'contacts.supplier_business_name',
            'contacts.address_line_1',
            'contacts.address_line_2',
            'contacts.city',
            'contacts.state',
            'contacts.country',
            'contacts.zip_code',
            'contacts.contact_id',
            'transactions.payment_status',
            'transactions.final_total',
            'transactions.tax_amount',
            'transactions.discount_amount',
            'transactions.discount_type',
            'transactions.total_before_tax',
            'transactions.rp_redeemed',
            'transactions.rp_redeemed_amount',
            'transactions.rp_earned',
            'transactions.types_of_service_id',
            'transactions.shipping_status',
            'transactions.pay_term_number',
            'transactions.pay_term_type',
            'transactions.additional_notes',
            'transactions.staff_note',
            'transactions.shipping_details',
            'transactions.created_at',
            'transactions.updated_at',
            'cap.commision',
            'cap.commission_payment',
            'transactions.is_suspend',
            DB::raw('DATE_FORMAT(transactions.transaction_date, "%Y/%m/%d") as sale_date'),
            DB::raw("CONCAT(COALESCE(u.surname, ''),' ',COALESCE(u.first_name, ''),' ',COALESCE(u.last_name,'')) as added_by"),
            DB::raw('(SELECT SUM(IF(TP.is_return = 1,-1*TP.amount,TP.amount)) FROM transaction_payments AS TP WHERE
                        TP.transaction_id=transactions.id) as total_paid'),
            'bl.name as business_location',
            DB::raw('COUNT(SR.id) as return_exists'),
            DB::raw('SUM( IF(SR.is_exchange = 1,SR.is_exchange,null)) as exchange_exists'),
            DB::raw('(SELECT SUM(TP2.amount) FROM transaction_payments AS TP2 WHERE
                        TP2.transaction_id=SR.id ) as return_paid'),
            DB::raw('COALESCE(SR.final_total, 0) as amount_return'),
            'SR.invoice_no AS return_invoive_number',
            DB::raw('DATE_FORMAT(SR.transaction_date, "%Y/%m/%d") as return_date'),
            'SR.id as return_transaction_id',
            'tos.name as types_of_service_name',
            'transactions.service_custom_field_1',
            DB::raw('COUNT( DISTINCT tsl.id) as total_items'),
            DB::raw("CONCAT(COALESCE(ss.surname, ''),' ',COALESCE(ss.first_name, ''),' ',COALESCE(ss.last_name,'')) as waiter"),
            'tables.name as table_name',
            'st.name as sale_type',
            'msts.sale_type as multi_sale_type',
            'co_c.Name as courier_name',
            'transactions.waybill_no as waybill_number',
            'transactions.return_parent_id',
            DB::raw("CONCAT(COALESCE(comi_agent.surname, ''),' ',COALESCE(comi_agent.first_name, ''),' ',COALESCE(comi_agent.last_name,'')) as com_agent"),
            DB::raw('(SELECT IF(SUM(temp.status) / COUNT(temp.status) < 1, 0, 1) AS is_approved FROM
                              (SELECT IF(aaa.status = \'approved\', 1, 0) AS status FROM admin_approve_actions aaa
                                 WHERE aaa.transaction_id = transactions.id) temp) as approved ')

        )->groupBy('transactions.id');

        $with[] = 'payment_lines';
        if (! empty($with)) {
            $sells->with($with);
        }

        return $sells->get();
    }

    public function stock_report($filters): array
    {
        $business_id = $filters['business_id'];

        $this->dynamicDatabaseConnection('reports_landlord', $filters['database']);

        $query = Variation::on('reports_landlord')->join('products as p', 'p.id', '=', 'variations.product_id')
            ->join('units', 'p.unit_id', '=', 'units.id')
            ->leftjoin('repair_device_models as rdm', 'rdm.id', '=', 'p.repair_model_id')
            ->leftjoin('variation_location_details as vld', 'variations.id', '=', 'vld.variation_id')
            ->leftjoin('business_locations as l', 'vld.location_id', '=', 'l.id')
            ->leftjoin('mfg_recipes as mr', 'mr.variation_id', '=', 'variations.id')
            ->join('product_variations as pv', 'variations.product_variation_id', '=', 'pv.id')
            ->where('p.business_id', $business_id)
            ->whereIn('p.type', ['single', 'variable']);

        //TODO::Check if result is correct after changing LEFT JOIN to INNER JOIN
        $pl_query_string = $this->get_pl_quantity_sum_string('pl');

        $products = $query->select(
            DB::raw("(SELECT SUM(TSL.quantity - TSL.quantity_returned) FROM transactions 
                  JOIN transaction_sell_lines AS TSL ON transactions.id=TSL.transaction_id
                  WHERE transactions.status='final' AND transactions.type='sell' AND transactions.location_id=vld.location_id
                  AND TSL.variation_id=variations.id) as total_sold"),
            DB::raw("(SELECT SUM(IF(transactions.type='sell_transfer', TSL.quantity, 0) ) FROM transactions 
                  JOIN transaction_sell_lines AS TSL ON transactions.id=TSL.transaction_id
                  WHERE transactions.status='final' AND transactions.type='sell_transfer' AND transactions.location_id=vld.location_id AND (TSL.variation_id=variations.id)) as total_transfered"),
            DB::raw("(SELECT SUM(IF(transactions.type='stock_adjustment', SAL.quantity, 0) ) FROM transactions 
                  JOIN stock_adjustment_lines AS SAL ON transactions.id=SAL.transaction_id
                  WHERE transactions.type='stock_adjustment' AND transactions.location_id=vld.location_id 
                    AND (SAL.variation_id=variations.id)) as total_adjusted"),
            DB::raw("(SELECT SUM( COALESCE(pl.quantity - ($pl_query_string), 0) * purchase_price_inc_tax) FROM transactions 
                  JOIN purchase_lines AS pl ON transactions.id=pl.transaction_id
                  WHERE transactions.status='received' AND transactions.location_id=vld.location_id 
                  AND (pl.variation_id=variations.id)) as stock_price"),
            DB::raw('SUM(vld.qty_available) as stock'),
            'variations.sub_sku as sku',
            'p.name as product',
            'rdm.name as model',
            'p.type',
            'p.category_id as category_id',
            'p.sub_category_id as sub_category_id',
            'p.brand_id as brand_id',
            'p.id as product_id',
            'units.short_name as unit',
            'p.enable_stock as enable_stock',
            'variations.sell_price_inc_tax as unit_price',
            'pv.name as product_variation',
            'variations.name as variation_name',
            'l.name as location_name',
            'l.id as location_id',
            'variations.id as variation_id',
            'variations.dpp_inc_tax as stock_avg_price',
            'p.sub_unit_ids as sub_units',
            'p.business_id as business_id',
            'p.unit_id as unit_id'
        )->groupBy('variations.id', 'vld.location_id');

        if (true) {
            $pl_query_string = $this->get_pl_quantity_sum_string('PL');
            $products->addSelect(
                DB::raw("(SELECT COALESCE(SUM(PL.quantity - ($pl_query_string)), 0) FROM transactions 
                    JOIN purchase_lines AS PL ON transactions.id=PL.transaction_id
                    WHERE transactions.status='received' AND transactions.type='production_purchase' AND transactions.location_id=vld.location_id  
                    AND (PL.variation_id=variations.id)) as total_mfg_stock")
            );
        }

        $data = $this->getStockReportDatatableData($products->get(), $filters['database']);

        return [
            'query_string' => $products->toSql(),
            'data' => $data,
        ];
    }

    public function supplier_wise_stock_report($filter): array
    {
        $business_id = $filter['business_id'];
        $location_id = $filter['location_id'];

        $end_date = $this->auth_pass_uf_date($filter['date_time_format'], $filter['stock_date'], true);

        $this->dynamicDatabaseConnection('reports_landlord', $filter['database']);

        //TODO::Need to fix this query for stock adjustment after fixing stock adjustment issue;
        /**
         * IF(SUM(tslpl.quantity)>sal.quantity,sal.quantity,SUM(tslpl.quantity)) at stock_adjustment
         * when after fixing stock adjustment issue need re write this code
         */
        $query_string =
            "
    SELECT 
        temp2.*, 
        (temp2.purchase + temp2.sell_return - temp2.sold - temp2.pu_re_1 - temp2.pu_re_2 - temp2.stock_adjustment - temp2.stock_transfer - temp2.production_sell) as 'Total'
    FROM(
        SELECT
            temp.contact_name,
            temp.contact_id,
            temp.product_name,
            temp.variation_id,
            temp.last_purchased_price,
            temp.unit,
            temp.sku,
            IF(temp.purchase IS null,0,temp.purchase) as purchase,
            IF(temp.sold IS null,0,temp.sold) as sold,
            IF(temp.sell_return IS null,0,temp.sell_return) as sell_return,
            IF(temp.purchase_return IS null,0,temp.purchase_return) as pu_re_1,
            IF(temp.purchase_return_direct IS null,0,temp.purchase_return_direct) as pu_re_2,
            IF(temp.stock_adjustment IS null,0,temp.stock_adjustment) as stock_adjustment,
            IF(temp.transfer_out IS null,0,temp.transfer_out) as stock_transfer,
            IF(temp.production_sell IS null,0,temp.production_sell) as production_sell
        FROM(
            SELECT 
                c.name as contact_name,
                c.id as contact_id,
                v.id as variation_id,
                v.sub_sku as sku,
                u.short_name as unit,
                IF(v.name = 'DUMMY',p.name,CONCAT(p.name, '-', v.name)) as product_name,
                SUM(pl.quantity) as purchase,
                    COALESCE((SELECT SUM(tslpl.quantity) FROM transaction_sell_lines tsl
                        INNER JOIN transactions t1 ON tsl.transaction_id = t1.id
                            AND t1.status = 'final' 
                            AND t1.type = 'sell'
                        INNER JOIN transaction_sell_lines_purchase_lines tslpl ON tslpl.sell_line_id = tsl.id
                        INNER JOIN purchase_lines pl1 ON tslpl.purchase_line_id = pl1.id
                        INNER JOIN transactions t2 ON pl1.transaction_id = t2.id
                            AND t2.contact_id = c.id
                            AND pl1.variation_id = pl.variation_id
                            AND t1.transaction_date < '$end_date'
                            AND t1.location_id = '$location_id'
                    )) as sold,
                    COALESCE((
                        SELECT ROUND(pl1.purchase_price,2) FROM purchase_lines pl1
                        INNER JOIN transactions t1 ON pl1.transaction_id = t1.id
                            AND t1.status = 'received'
                            AND t1.type = 'purchase'
                            AND t1.contact_id = c.id
                            AND pl1.variation_id = pl.variation_id
                            AND t1.transaction_date < '$end_date'
                            AND t1.location_id = '$location_id'
                        ORDER BY t1.transaction_date DESC LIMIT 1
                    )) as last_purchased_price,
                    COALESCE((SELECT SUM(tslpl.quantity) FROM transaction_sell_lines tsl
                        INNER JOIN transactions t1 ON tsl.transaction_id = t1.id
                            AND t1.status = 'final' 
                            AND t1.type = 'production_sell'
                        INNER JOIN transaction_sell_lines_purchase_lines tslpl ON tslpl.sell_line_id = tsl.id
                        INNER JOIN purchase_lines pl1 ON tslpl.purchase_line_id = pl1.id
                        INNER JOIN transactions t2 ON pl1.transaction_id = t2.id
                            AND t2.contact_id = c.id
                            AND pl1.variation_id = pl.variation_id
                            AND t1.transaction_date < '$end_date'
                            AND t1.location_id = '$location_id'
                    )) as production_sell,
                    COALESCE((SELECT IF(SUM(tslpl.quantity) > SUM(tsl.quantity),SUM(tsl.quantity),SUM(tslpl.quantity)) FROM transaction_sell_lines tsl
                    INNER JOIN transactions t1 ON tsl.transaction_id = t1.id
                        AND t1.status IN ('final','in_transit') and t1.type = 'sell_transfer'
                    INNER JOIN transaction_sell_lines_purchase_lines tslpl ON tslpl.sell_line_id = tsl.id
                    INNER JOIN purchase_lines pl1 ON tslpl.purchase_line_id = pl1.id
                    INNER JOIN transactions t2 ON pl1.transaction_id = t2.id
                        AND t2.contact_id = c.id
                        AND pl1.variation_id = pl.variation_id
                        AND t1.transaction_date < '$end_date'
                        and t1.location_id = '$location_id'
                    )) as transfer_out,
                    COALESCE((SELECT SUM(tslpl.qty_returned) FROM transaction_sell_lines tsl
                    INNER JOIN transactions t1 ON tsl.transaction_id = t1.id
                    INNER JOIN transactions t3 ON t1.id = t3.return_parent_id
                        AND t3.type = 'sell_return'
                    INNER JOIN transaction_sell_lines_purchase_lines tslpl ON tslpl.sell_line_id = tsl.id
                    INNER JOIN purchase_lines pl1 ON tslpl.purchase_line_id = pl1.id
                    INNER JOIN transactions t2 ON pl1.transaction_id = t2.id
                        AND t2.contact_id = c.id
                        AND pl1.variation_id = pl.variation_id
                        AND t3.transaction_date < '$end_date'
                        AND t3.location_id = '$location_id'
                    )) as sell_return,
                    COALESCE((SELECT SUM(pl1.quantity_returned) FROM transactions t1
                    INNER JOIN transactions t2 ON t1.return_parent_id = t2.id
                    INNER JOIN purchase_lines pl1 ON t2.id = pl1.transaction_id
                        AND pl1.variation_id = pl.variation_id
                        AND t2.contact_id = c.id
                        AND t1.type = 'purchase_return'
                        AND t1.transaction_date < '$end_date'
                        AND t1.location_id = '$location_id'
                    )) as purchase_return,
                    COALESCE((SELECT SUM(pl1.quantity_returned) FROM transactions t2
                    INNER JOIN purchase_lines pl1 ON t2.id = pl1.transaction_id
                        AND pl1.variation_id = pl.variation_id
                        AND t2.contact_id = c.id
                        AND t2.type = 'purchase_return'
                        AND t2.transaction_date < '$end_date'
                        AND t2.location_id = '$location_id'
                    )) as purchase_return_direct,
                    COALESCE((SELECT IF(SUM(tslpl.quantity)>SUM(sal.quantity),SUM(sal.quantity),SUM(tslpl.quantity)) FROM stock_adjustment_lines sal
                        INNER JOIN transactions t1 ON sal.transaction_id = t1.id
                            AND t1.type = 'stock_adjustment'
                        INNER JOIN transaction_sell_lines_purchase_lines tslpl ON tslpl.stock_adjustment_line_id = sal.id
                        INNER JOIN purchase_lines pl1 ON tslpl.purchase_line_id = pl1.id
                        INNER JOIN transactions t2 ON pl1.transaction_id = t2.id
                            AND t2.contact_id = c.id
                            AND sal.variation_id = pl.variation_id
                            AND t1.transaction_date < '$end_date'
                            AND t1.location_id = '$location_id'
                    )) as stock_adjustment
                FROM purchase_lines pl 
                    INNER JOIN transactions t ON t.id = pl.transaction_id
                    INNER JOIN contacts c ON t.contact_id = c.id
                    INNER JOIN variations v ON pl.variation_id = v.id
                    INNER JOIN products p ON pl.product_id = p.id
                    INNER JOIN units u ON p.unit_id = u.id
                    WHERE t.business_id = '$business_id'
                        AND t.type = 'purchase'
                        AND t.status = 'received'
                        AND t.location_id = '$location_id'
                        AND t.transaction_date < '$end_date' 
            GROUP BY pl.variation_id,c.id)temp
        GROUP BY temp.contact_id,temp.variation_id) temp2
    ";

        $data = DB::connection('reports_landlord')->select($query_string);

        return [
            'data' => $data,
            'query_string' => $query_string,
        ];
    }

    public function filterQueryData($query_data, $input)
    {
        if (is_array($query_data)) {
            $query_data = collect($query_data);
        }
        if (! empty($input['variation_id'])) {
            $query_data = $query_data->where('variation_id', $input['variation_id']);
        }

        if (! empty($input['product_id'])) {
            $query_data = $query_data->where('product_id', $input['product_id']);
        }

        if (! empty($input['location_id'])) {
            $query_data = $query_data->where('location_id', $input['location_id']);
        }

        if (! empty($input['unit_id'])) {
            $query_data = $query_data->where('unit_id', $input['unit_id']);
        }

        if (! empty($input['contact_id'])) {
            $query_data = $query_data->where('contact_id', $input['contact_id']);
        }

        if (! empty($input['category_id'])) {
            $query_data = $query_data->where('category_id', $input['category_id']);
        }

        if (! empty($input['sub_category_id'])) {
            $query_data = $query_data->where('sub_category_id', $input['sub_category_id']);
        }

        if (! empty($input['brand_id'])) {
            $query_data = $query_data->where('brand_id', $input['brand_id']);
        }

        if (! empty($input['product_id'])) {
            $query_data = $query_data->where('product_id', $input['product_id']);
        }

        return $query_data;
    }

    public function customer_contact_report($filters): array
    {
        $data = $this->__contact_query($filters, 'customer');

        return [
            'query_string' => $data->toSql(),
            'data' => $data->get(),
        ];
    }

    public function supplier_contact_report($filters): array
    {
        $data = $this->__contact_query($filters, 'supplier');

        return [
            'query_string' => $data->toSql(),
            'data' => $data->get(),
        ];
    }

    private function __contact_query($filters, $type)
    {
        $query = Contact::on('reports_landlord')
            ->leftjoin('transactions AS t', 'contacts.id', '=', 't.contact_id')
            ->leftjoin('customer_groups AS cg', 'contacts.customer_group_id', '=', 'cg.id')
            ->where('contacts.business_id', $filters['business_id']);

        if ($type == 'supplier') {
            $query->onlySuppliers();
        } elseif ($type == 'customer') {
            $query->onlyCustomers();
        }
        if (! empty($contact_ids)) {
            $query->whereIn('contacts.id', $contact_ids);
        }

        $query->select([
            'contacts.*',
            'cg.name as customer_group',
            DB::raw("SUM(IF(t.type = 'opening_balance', final_total, 0)) as opening_balance"),
            DB::raw("SUM(IF(t.type = 'opening_balance', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as opening_balance_paid"),
        ]);

        if (in_array($type, ['supplier', 'both'])) {
            $query->addSelect([
                DB::raw("SUM(IF(t.type = 'purchase', final_total, 0)) as total_purchase"),
                DB::raw("SUM(IF(t.type = 'purchase', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as purchase_paid"),
                DB::raw("SUM(IF(t.type = 'purchase_return', final_total, 0)) as total_purchase_return"),
                DB::raw("SUM(IF(t.type = 'purchase_return', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as purchase_return_paid"),
            ]);
        }

        if (in_array($type, ['customer', 'both'])) {
            $query->addSelect([
                DB::raw("SUM(IF(t.type = 'sell' AND t.status = 'final', final_total, 0)) as total_invoice"),
                DB::raw("SUM(IF(t.type = 'sell' AND t.status = 'final', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as invoice_received"),
                DB::raw("SUM(IF(t.type = 'sell_return', final_total, 0)) as total_sell_return"),
                DB::raw("SUM(IF(t.type = 'sell_return', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as sell_return_paid"),
            ]);
        }
        $query->groupBy('contacts.id');

        return $query;
    }

    private function getStockReportDatatableData($stock_data, $database)
    {
        return $stock_data->map(function ($stock) use ($database) {
            $stock['sub_units_stock'] = $this->__addSubUnitArray($stock['product_id'], $stock['stock'], $stock['unit'],
                $database);
            $stock['potential_profit'] = $this->__addPotentialProfit($stock['stock'], $stock['unit_price'],
                $stock['stock_price']);

            $stock['stock_price_blade'] = $stock['stock_price'];

            $stock['stock_value_by_sale_price'] = $this->__stockValueBySalePrice($stock['stock'], $stock['unit_price']);

            //quantity_based
            $stock['stock'] = $this->__addBaseUnit($stock['stock'], $stock['unit']);
            $stock['total_sold'] = $this->__addBaseUnit($stock['total_sold'], $stock['unit']);
            $stock['total_transfered'] = $this->__addBaseUnit($stock['total_transfered'], $stock['unit']);
            $stock['total_adjusted'] = $this->__addBaseUnit($stock['total_adjusted'], $stock['unit']);

            //name
            if ($stock['type'] == 'variable') {
                $stock['product'] .= ' - '.$stock['product_variation'].'-'.$stock['variation_name'];
            }

            //with html tags
            $stock['stock_price'] = '<span 
            class="display_currency total_stock_price" 
            data-currency_symbol=true 
            data-orig-value="'.$stock['stock_price'].'">'.$stock['stock_price'].'</span>';

            $stock['stock_avg_price'] = '<span 
            class="display_currency stock_avg_price" 
            data-currency_symbol=true 
            data-orig-value="'.$stock['stock_avg_price'].'">'.$stock['stock_avg_price'].'</span>';

            return $stock;
        });
    }

    private function __addBaseUnit($quantity, $unit): string
    {
        return (float) $quantity.' '.$unit;
    }

    private function __addSubUnitArray($product_id, $stock, $sub_units, $database): string
    {
        $this->dynamicDatabaseConnection('reports_landlord', $database);

        $unit_ids = Product::on('reports_landlord')->where('id', $product_id)
            ->value('sub_unit_ids');

        if (! empty($unit_ids)) {
            $unit_names = Unit::on('reports_landlord')->whereIn('id', $unit_ids)
                ->select('short_name', 'base_unit_multiplier', 'allow_decimal')
                ->get();

            $sub_units = '';
            foreach ($unit_names as $unit_name) {
                if ($unit_name->allow_decimal == 1) {
                    if ($unit_name->base_unit_multiplier != 0) {
                        $sub_units_for_round_up = $stock / $unit_name->base_unit_multiplier;
                    } else {
                        $sub_units_for_round_up = $stock;
                    }
                    $output = '<span data-is_quantity="true"> '
                        .floor($sub_units_for_round_up * 10) / 10 .' '.$unit_name->short_name.'</span><br><br>';
                } else {
                    if ($unit_name->base_unit_multiplier != 0) {
                        $sub_units_for_round_up = $stock / $unit_name->base_unit_multiplier;
                        $output = '<span data-is_quantity="true"> '
                            .floor($sub_units_for_round_up).' '.$unit_name->short_name.'</span><br><br>';
                    } else {
                        $sub_units_for_round_up = $stock;
                        $output = '<span data-is_quantity="true"> '
                            .floor($sub_units_for_round_up).' '.$unit_name->short_name.'</span><br><br>';
                    }
                }
                $sub_units .= $output;
            }

            return $sub_units;
        } else {
            return ' - - ';
        }

    }

    private function __addPotentialProfit($stock, $unit_price, $stock_price): string
    {
        $stock = $stock ?? 0;
        //(float)$row->group_price > 0 ? $row->group_price :
        $unit_selling_price = $unit_price;
        $stock_price_by_sp = $stock * $unit_selling_price;
        $potential_profit = $stock_price_by_sp - $stock_price;

        return '<span class="potential_profit display_currency" data-orig-value="'.(float) $potential_profit.'" data-currency_symbol=true > '.(float) $potential_profit.'</span>';

    }

    private function __stockValueBySalePrice($stock, $unit_price): string
    {
        $stock = $stock ?? 0;
        $unit_selling_price = $unit_price;
        $stock_price = $stock * $unit_selling_price;

        return '<span class="stock_value_by_sale_price display_currency" data-orig-value="'.(float) $stock_price.'" data-currency_symbol=true > '
            .(float) $stock_price.'</span>';
    }

    public function product_stock_details()
    {

        $query = Variation::join('products as p', 'p.id', '=', 'variations.product_id')
            ->join('units', 'p.unit_id', '=', 'units.id')
            ->leftjoin('variation_location_details as vld', 'variations.id', '=', 'vld.variation_id')
            ->leftjoin('business_locations as l', 'vld.location_id', '=', 'l.id')
            ->leftjoin('mfg_recipes as mr', 'mr.variation_id', '=', 'variations.id')
            ->join('product_variations as pv', 'variations.product_variation_id', '=', 'pv.id')
            ->where('p.business_id', auth()->user()->business_id)
            ->whereIn('p.type', ['single', 'variable'])
            ->where('p.id', request()->product_id);

        $products = $query->select(
            DB::raw("(SELECT SUM(TSL.quantity - TSL.quantity_returned) FROM transactions 
                  JOIN transaction_sell_lines AS TSL ON transactions.id=TSL.transaction_id
                  WHERE transactions.status='final' AND transactions.type='sell' AND transactions.location_id=vld.location_id
                  AND TSL.variation_id=variations.id) as total_sold"),
            DB::raw("(SELECT SUM(IF(transactions.type='sell_transfer', TSL.quantity, 0) ) FROM transactions 
                  JOIN transaction_sell_lines AS TSL ON transactions.id=TSL.transaction_id
                  WHERE transactions.status='final' AND transactions.type='sell_transfer' AND transactions.location_id=vld.location_id AND (TSL.variation_id=variations.id)) as total_transfered"),
            DB::raw("(SELECT SUM(IF(transactions.type='stock_adjustment', SAL.quantity, 0) ) FROM transactions 
                  JOIN stock_adjustment_lines AS SAL ON transactions.id=SAL.transaction_id
                  WHERE transactions.type='stock_adjustment' AND transactions.location_id=vld.location_id 
                    AND (SAL.variation_id=variations.id)) as total_adjusted"),
            DB::raw('SUM(vld.qty_available) as stock'),
            'variations.sub_sku as sku',
            'p.name as product',
            'p.type',
            'units.short_name as unit',
            'p.enable_stock as enable_stock',
            'variations.sell_price_inc_tax as unit_price',
            'pv.name as product_variation',
            'variations.name as variation_name',
            'l.name as location_name',
            'l.id as location_id',
            'variations.id as variation_id',
        )->groupBy('variations.id', 'vld.location_id');

        $product_stock_details = $products->get();

        return view('product.partials.product_stock_details')->with(compact('product_stock_details'));
    }
}
