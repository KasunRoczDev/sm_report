<?php

namespace App\Http\Controllers;

use App\Models\MultiSaleTypes;
use App\Models\Product;
use App\Models\SellingPriceGroup;
use App\Models\Transaction;
use App\Utils\BusinessUtil;
use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use App\Utils\ReportQueryUtil;
use App\Utils\TransactionUtil;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

class ReportController extends Controller
{
    private BusinessUtil $businessUtil;
    private TransactionUtil $transactionUtil;
    private ModuleUtil $moduleUtil;
    private ProductUtil $productUtil;
    private ReportQueryUtil $reportQueryUtil;

    public function __construct()
    {
        $this->businessUtil = new BusinessUtil();
        $this->transactionUtil = new TransactionUtil();
        $this->moduleUtil = new ModuleUtil();
        $this->productUtil = new ProductUtil();
        $this->reportQueryUtil = new ReportQueryUtil();
    }

    public function sells_datatables():JsonResponse
    {

        if (
            !auth()->user()->can('sell.view')
            && !auth()->user()->can('sell.create')
            && !auth()->user()->can('direct_sell.access')
            && !auth()->user()->can('view_own_sell_only')
        ) {
            abort(403, 'Unauthorized action.');
        }

        ini_set('max_execution_time', 180);
        $business_id = auth()->user()->business_id;
        $is_woocommerce = $this->moduleUtil->isModuleInstalled('Woocommerce');
        $is_tables_enabled = $this->transactionUtil->isModuleEnabled('tables');
        $is_service_staff_enabled = $this->transactionUtil->isModuleEnabled('service_staff');

        $business_details = $this->businessUtil->getDetails($business_id);

        $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings,
            true);

        $payment_types = $this->transactionUtil->payment_types(null, true, $business_id);
        $with = [];
        if (!empty(request()->input('table_type'))) {
            if (request()->input('table_type') == 'sell' || request()->input('table_type') == 'suspend' || request()->input('table_type') == 'due') {
                $all_sale_id = 'sell';
            } else {
                if (request()->input('table_type') == 'return') {
                    $all_sale_id = 'sell_return';
                }
            }
        } else {
            $all_sale_id = 'sell';
        }
        $shipping_statuses = $this->transactionUtil->shipping_statuses();

        if (!auth()->user()->can('direct_sell.access') && auth()->user()->can('view_own_sell_only')) {
            $filter['view_own_sell_only'] = 1;
        }

        $only_shipments = request()->only_shipments == 'true' ? true : false;

        $sells = $this->transactionUtil->getListSells($business_id, $all_sale_id);

        if (!empty($pos_settings['enable_suspend_to_all_sale']) && (request()->input('table_type') === 'sell')) {
            $sells->where('transactions.is_suspend', 0);
        }

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $sells->whereIn('transactions.location_id', $permitted_locations);
        }
        //Add condition for created_by,used in sales representative sales report
        if (request()->has('created_by')) {
            $created_by = request()->get('created_by');
            if (!empty($created_by)) {
                $sells->where('transactions.created_by', $created_by);
            }
        }
        if (!auth()->user()->can('direct_sell.access') && auth()->user()->can('view_own_sell_only')) {
            $sells->where('transactions.created_by', auth()->user()->id);
        }
        if (request()->input('payment_status')) {
            if (!empty(request()->input('payment_status')) && !in_array('overdue',
                    request()->input('payment_status')) && !in_array(null,
                    request()->input('payment_status'))) {
                $sells->whereIn('transactions.payment_status', request()->input('payment_status'));
            } else {
                if (in_array('overdue', request()->input('payment_status'))) {
                    $array = null;
                    if (($key = array_search('overdue', request()->input('payment_status'))) !== false) {
                        $array = request()->input('payment_status');
                        unset($array[$key]);
                    }
                    $sells->whereIn('transactions.payment_status',
                        array_unique(array_merge(['due', 'partial'], $array)))
                        ->whereNotNull('transactions.pay_term_number')
                        ->whereNotNull('transactions.pay_term_type')
                        ->whereRaw("IF(transactions.pay_term_type='days', DATE_ADD(transactions.transaction_date, INTERVAL transactions.pay_term_number DAY) < CURDATE(), DATE_ADD(transactions.transaction_date, INTERVAL transactions.pay_term_number MONTH) < CURDATE())");
                }
            }
        }
        //Add condition for location,used in sales representative expense report
        if (request()->has('location_id')) {
            $location_id = request()->get('location_id');
            if (!empty($location_id)) {
                $sells->where('transactions.location_id', $location_id);
            }
        }
        if (request()->has('sale_type')) {
            $sale_types = request()->get('sale_type');
            if (!empty($sale_types)) {
                $sells->where('transactions.sale_type_id', $sale_types);
            }
        }
        if (request()->has('multi_sale_type')) {
            $multi_sale_types = request()->get('multi_sale_type');
            if (!empty($multi_sale_types)) {
                $sells->whereIn('msts.sale_type', $multi_sale_types);
            }
        }
        if (!empty(request()->input('rewards_only')) && request()->input('rewards_only') == true) {
            $sells->where(function ($q) {
                $q->whereNotNull('transactions.rp_earned')
                    ->orWhere('transactions.rp_redeemed', '>', 0);
            });
        }
        if (!empty(request()->customer_id)) {
            $customer_id = request()->customer_id;
            $sells->where('contacts.id', $customer_id);
        }
        if (!empty(request()->start_date) && !empty(request()->end_date)) {
            $start = request()->start_date;
            $end = request()->end_date;
            $sells->whereDate('transactions.transaction_date', '>=', $start)
                ->whereDate('transactions.transaction_date', '<=', $end);
        }
        //Check is_direct sell
        if (request()->has('is_direct_sale')) {
            $is_direct_sale = request()->is_direct_sale;
            if ($is_direct_sale == 0) {
                $sells->where('transactions.is_direct_sale', 0);
                $sells->whereNull('transactions.sub_type');
            }
        }

        //Add condition for commission_agent,used in sales representative sales with commission report
        if (request()->has('commission_agent')) {
            $commission_agent = request()->get('commission_agent');
            if (!empty($commission_agent)) {
                $sells->where('transactions.commission_agent', $commission_agent);
            }
        }

        $sells->addSelect('transactions.shopify_order_id');

        if ($is_woocommerce) {
            $sells->addSelect('transactions.woocommerce_order_id');
            if (request()->only_woocommerce_sells) {
                $sells->whereNotNull('transactions.woocommerce_order_id');
            }
        }
        //check none woocommerce sale
        if ($is_woocommerce) {
            if (request()->only_direct_sells) {
                $sells->where(function ($q) {
                    $q->whereNull('transactions.woocommerce_order_id');
                });
            }
        }

        if (request()->only_subscriptions) {
            $sells->where(function ($q) {
                $q->whereNotNull('transactions.recur_parent_id')
                    ->orWhere('transactions.is_recurring', 1);
            });
        }

        if (!empty(request()->list_for) && request()->list_for == 'service_staff_report') {
            $sells->whereNotNull('transactions.res_waiter_id');
        }

        if (!empty(request()->res_waiter_id)) {
            $sells->where('transactions.res_waiter_id', request()->res_waiter_id);
        }

        if (!empty(request()->input('sub_type'))) {
            $sells->where('transactions.sub_type', request()->input('sub_type'));
        }

        if (!empty(request()->input('created_by'))) {
            $sells->where('transactions.created_by', request()->input('created_by'));
        }

        if (!empty(request()->input('sales_cmsn_agnt'))) {
            $sells->where('transactions.commission_agent', request()->input('sales_cmsn_agnt'));
        }

        if (!empty(request()->input('service_staffs'))) {
            $sells->where('transactions.res_waiter_id', request()->input('service_staffs'));
        }

        if ($only_shipments && auth()->user()->can('access_shipping')) {
            $sells->whereNotNull('transactions.shipping_status');
        }

        if (!empty(request()->input('shipping_status'))) {
            $sells->where('transactions.shipping_status', request()->input('shipping_status'));
        }

        if (!empty(request()->input('shipping_status'))) {
            $sells->where('transactions.shipping_status', request()->input('shipping_status'));
        }

        if (!empty(request()->input('sale_category'))) {
            $sale_categorys = request()->get('sale_category');
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
        if (!empty(request()->input('b2b_sales'))) {
            $sells->where('transactions.is_mapping_transaction', 1);
        }

        $sells->groupBy('transactions.id');

        if (!empty(request()->suspended)) {
            $transaction_sub_type = request()->get('transaction_sub_type');
            if (!empty($transaction_sub_type)) {
                $sells->where('transactions.sub_type', $transaction_sub_type);
            } else {
                $sells->where('transactions.sub_type', null);
            }

            $with = ['sell_lines', 'adminResuestMinPiceSale'];

            if ($is_tables_enabled) {
                $with[] = 'table';
            }

            if ($is_service_staff_enabled) {
                $with[] = 'service_staff';
            }

            $sales = $sells->where('transactions.is_suspend', 1)
                ->with($with)
                ->addSelect('transactions.is_suspend', 'transactions.res_table_id',
                    'transactions.res_waiter_id', 'transactions.additional_notes')
                ->get();

            return view('sale_pos.partials.suspended_sales_modal')->with(compact('sales',
                'is_tables_enabled', 'is_service_staff_enabled', 'transaction_sub_type'));
        }

        $with[] = 'payment_lines';
        if (!empty($with)) {
            $sells->with($with);
        }

        //$business_details = $this->businessUtil->getDetails($business_id);
        if ($this->businessUtil->isModuleEnabled('subscription')) {
            $sells->addSelect('transactions.is_recurring', 'transactions.recur_parent_id');
        }
        if ($this->businessUtil->isModuleEnabled('reminder')) {
            $sells->addSelect('transactions.is_recurring_reminder');
        }

        $sells->addSelect('transactions.is_suspend');

        $datatable = Datatables::of($sells)
            ->addColumn(
                'action',
                function ($row) use ($only_shipments) {
                    if (request()->length < 500 && request()->length != -1) {
                        $html = '<div class="btn-group">
                                    <button type="button" class="btn btn-info dropdown-toggle btn-xs"
                                        data-toggle="dropdown" aria-expanded="false">' .
                            __('messages.actions') .
                            '<span class="caret"></span><span class="sr-only">Toggle Dropdown
                                        </span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-left" role="menu">';

                        if (auth()->user()->can('sell.view') || auth()->user()->can('direct_sell.access') || auth()->user()->can('view_own_sell_only')) {
                            $html .= '<li><a href="#" data-href="' . remote_action('SellController@show',
                                    [$row->id]) . '" class="btn-modal" data-container=".view_modal"><i class="fas fa-eye" aria-hidden="true"></i> ' . __('messages.view') . '</a></li>';
                        }
                        if (!$only_shipments) {
                            //edit Button hide
                            if ($row->is_direct_sale == 0) {
                                if (auth()->user()->can('sell.update')) {
                                    $html .= '<li ><a target="_blank" href="' . remote_action('SellPosController@edit',
                                            [$row->id]) . '"><i class="fas fa-edit"></i> ' . __('messages.edit') . '</a></li>';
                                }
                                if (auth()->user()->can('access_sell_return')) {
                                    $html .= '<li><a href="#" class="cancel-invoice" data-href="' . remote_route('sell.return-all',
                                            [$row->id]) . '"><i class="fas fa-window-close" aria-hidden="true"></i> ' . __('lang_v1.cancel') . '</a></li>';
                                }
                            } else {
                                if (auth()->user()->can('direct_sell.access')) {
                                    $html .= '<li ><a target="_blank" href="' . remote_action('SellController@edit',
                                            [$row->id]) . '"><i class="fas fa-edit"></i> ' . __('messages.edit') . '</a></li>';
                                }
                                if (auth()->user()->can('access_sell_return')) {
                                    $html .= '<li><a href="#" class="cancel-invoice" data-href="' . remote_route('sell.return-all',
                                            [$row->id]) . '"><i class="fas fa-window-close" aria-hidden="true"></i> ' . __('lang_v1.cancel') . '</a></li>';
                                }
                            }
                            //Delete Button hide
                            if (auth()->user()->can('direct_sell.delete') || auth()->user()->can('sell.delete')) {
                                $html .= '<li class="hide"><a href="' . remote_action('SellPosController@destroy',
                                        [$row->id]) . '" class="delete-sale"><i class="fas fa-trash"></i> ' . __('messages.delete') . '</a></li>';
                            }
                        }
                        if (auth()->user()->can('sell.view') || auth()->user()->can('direct_sell.access')) {
                            $html .= '<li><a href="#" class="print-invoice" data-href="' . remote_route('sell.printInvoice',
                                    [$row->id]) . '?is_suspend=' . $row->is_suspend . '"><i class="fas fa-print" aria-hidden="true"></i> ' . __('messages.print') . '</a></li>
                                <li><a href="#" class="print-invoice" data-href="' . remote_route('sell.printInvoice',
                                    [$row->id]) . '?package_slip=true"><i class="fas fa-file-alt" aria-hidden="true"></i> ' . __('lang_v1.packing_slip') . '</a></li>
                                <li><a href="#" class="print-invoice" data-href="' . remote_route('sell.printInvoice',
                                    [$row->id]) . '?waybill_summary=true"><i class="fas fa-file-alt" aria-hidden="true"></i> ' . __('lang_v1.waybill_summary') . '</a></li>
                                <li><a href="#" class="print-invoice" data-href="' . remote_route('sell.printInvoice',
                                    [$row->id]) . '?waybill_detailed=true"><i class="fas fa-file-alt" aria-hidden="true"></i> ' . __('lang_v1.waybill_detailed') . '</a></li>
                                <li><a href="#" class="print-invoice" data-href="' . remote_route('sell.printInvoice',
                                    [$row->id]) . '?delivery_condition=true"><i class="fa fa-check-square" aria-hidden="true"></i> ' . __('lang_v1.delivery_condition') . '</a></li>';
                        }
                        if (auth()->user()->can('access_shipping')) {
                            $html .= '<li><a href="#" data-href="' . remote_action('SellController@editShipping',
                                    [$row->id]) . '" class="btn-modal" data-container=".view_modal"><i class="fas fa-truck" aria-hidden="true"></i>' . __('lang_v1.edit_shipping') . '</a></li>';
                        }
                        if (!$only_shipments) {
                            $is_admin = $this->moduleUtil->is_admin(auth()->user(), auth()->user()->business_id);
                            $html .= '<li class="divider"></li>';

                            if ($row->payment_status != 'paid' || $row->payment_status != 'setoff' && (auth()->user()->can('sell.create') || auth()->user()->can('direct_sell.access')) && auth()->user()->can('sell.payments')) {
                                if (!(!auth()->user()->can('sell.minimu_price_override') && $row->is_suspend) || $row->approved || $is_admin) {
                                    $html .= '<li><a href="' . remote_action('TransactionPaymentController@addPayment',
                                            [$row->id]) . '" class="add_payment_modal"><i class="fas fa-money-bill-alt"></i> ' . __('purchase.add_payment') . '</a></li>';
                                }
                            }
                            if (!(!auth()->user()->can('sell.minimu_price_override') && $row->is_suspend) || $row->approved || $is_admin) {
                                $html .= '<li><a href="' . remote_action('TransactionPaymentController@show',
                                        [$row->id]) . '" class="view_payment_modal"><i class="fas fa-money-bill-alt"></i> ' . __('purchase.view_payments') . '</a></li>';
                            }

                            if (auth()->user()->can('sell.create')) {
                                $html .= '<li><a href="' . remote_action('SellController@duplicateSell',
                                        [$row->id]) . '"><i class="fas fa-copy"></i> ' . __('lang_v1.duplicate_sell') . '</a></li>';
                            }
                            if (auth()->user()->can('access_sell_return')) {
                                $html .= '<li><a href="' . remote_action('SellReturnController@add',
                                        [$row->id]) . '"><i class="fas fa-undo"></i> ' . __('lang_v1.sell_return') . '</a></li>';
                            }
                            if (auth()->user()->can('sell.create') && $row->payment_status != 'due') {
                                $html .= '
                                <li><a href="' . remote_action('SellReturnController@add', [
                                        $row->id, 'status' => 'exchange',
                                    ]) . '"><i class="fas fa-exchange-alt"></i> ' . __('lang_v1.exchange') . '</a></li>';
                            }
                            if (auth()->user()->can('sell.create')) {
                                $html .= '
                                <li><a href="' . remote_action('SellPosController@showInvoiceUrl',
                                        [$row->id]) . '" class="view_invoice_url"><i class="fas fa-eye"></i> ' . __('lang_v1.view_invoice_url') . '</a></li>';
                            }

                            $html .= '<li><a href="#" data-href="' . remote_action('NotificationController@getTemplate',
                                    [
                                        'transaction_id' => $row->id, 'template_for' => 'new_sale',
                                    ]) . '" class="btn-modal" data-container=".view_modal"><i class="fa fa-envelope" aria-hidden="true"></i>' . __('lang_v1.new_sale_notification') . '</a></li>';
                        }

                        $html .= '</ul></div>';
                    } else {
                        $html = '<div >-</div>';
                    }

                    return $html;
                }
            )
            ->removeColumn('id')
            ->editColumn(
                'final_total',
                '<span class="display_currency final-total" data-currency_symbol="true" data-orig-value="{{$final_total}}">{{$final_total}}</span>'
            )
            ->editColumn(
                'tax_amount',
                '<span class="display_currency total-tax" data-currency_symbol="true" data-orig-value="{{$tax_amount}}">{{$tax_amount}}</span>'
            )
            ->editColumn(
                'total_paid',
                '<span class="display_currency total-paid" data-currency_symbol="true" data-orig-value="{{$total_paid}}">{{$total_paid}}</span>'
            )
            ->editColumn(
                'total_before_tax',
                '<span class="display_currency total_before_tax" data-currency_symbol="true" data-orig-value="{{$total_before_tax}}">{{$total_before_tax}}</span>'
            )
            ->editColumn(
                'discount_amount',
                function ($row) {
                    $discount = !empty($row->discount_amount) ? $row->discount_amount : 0;

                    if (!empty($discount) && $row->discount_type == 'percentage') {
                        $discount = $row->total_before_tax * ($discount / 100);
                    }

                    return '<span class="display_currency total-discount" data-currency_symbol="true" data-orig-value="' . $discount . '">' . $discount . '</span>';
                }
            )
            ->editColumn('transaction_date',
                function ($row) {
                    $carbonDate = Carbon::createFromFormat('Y-m-d H:i:s', $row->transaction_date);
                    $date_format = auth()->user()->business->date_format;
                    $time_format = (auth()->user()->business->time_format = '24') ? 'H:i' : 'h:i A';
                    $datetime_format = $date_format . ' ' . $time_format;

                    return $carbonDate->format($datetime_format);
                })
            ->editColumn(
                'payment_status',
                function ($row) {
                    $is_admin = auth()->user()->can('Admin');
                    $payment_status = Transaction::getPaymentStatus($row);

                    return (string)view('sell.partials.payment_status', [
                        'payment_status' => $payment_status, 'id' => $row->id, 'is_suspend' => $row->is_suspend,
                        'overide_permission' => auth()->user()->can('sell.minimu_price_override'),
                        'approved' => $row->approved, 'is_admin' => $is_admin,
                    ]);
                }
            )
            ->editColumn(
                'types_of_service_name',
                '<span class="service-type-label" data-orig-value="{{$types_of_service_name}}" data-status-name="{{$types_of_service_name}}">{{$types_of_service_name}}</span>'
            )
            ->addColumn('total_remaining', function ($row) {
                $total_remaining = $row->final_total - $row->total_paid;
                $total_remaining_html = '<span class="display_currency payment_due" data-currency_symbol="true" data-orig-value="' . $total_remaining . '">' . $total_remaining . '</span>';

                return $total_remaining_html;
            })
            ->addColumn('return_due', function ($row) {
                $return_due_html = '';
                if (!empty($row->return_exists)) {
                    $return_due = $row->amount_return - $row->return_paid;
                    $return_due_html .= '<a href="' . remote_action('TransactionPaymentController@show',
                            [$row->return_transaction_id]) . '" class="view_purchase_return_payment_modal"><span class="display_currency sell_return_due" data-currency_symbol="true" data-orig-value="' . $return_due . '">' . $return_due . '</span></a>';
                }

                return $return_due_html;
            })
            ->addColumn('Business_name', function ($row) {
                return $row->supplier_business_name;
            })
            ->addColumn('address', function ($row) {
                $address_line_1 = '';
                $address_line_2 = '';
                $city = '';
                $state = '';
                $country = '';
                $zip_code = '';
                if (!empty($row->address_line_1)) {
                    $address_line_1 = $row->address_line_1 . ',';
                }
                if (!empty($row->address_line_2)) {
                    $address_line_2 = $row->address_line_2 . ',';
                }
                if (!empty($row->city)) {
                    $city = $row->city . ',';
                }
                if (!empty($row->state)) {
                    $state = $row->state . ',';
                }
                if (!empty($row->country)) {
                    $country = $row->country . ',';
                }
                if (!empty($row->zip_code)) {
                    $zip_code = $row->zip_code . '';
                }
                $address = '<div>' . $address_line_1 . '' . $address_line_2 . '' . $city . '' . $state . '' . $country . '' . $zip_code . '</div>';

                return $address;
            })
            ->editColumn('invoice_no', function ($row) {
                $invoice_no = $row->invoice_no;
                if (Transaction::find($row->id)->is_cod) {
                    $invoice_no .= ' <i class="fa fa-motorcycle text-primary no-print" title="' . __('lang_v1.synced_from_woocommerce') . '"></i>';
                }
                if (!empty($row->woocommerce_order_id)) {
                    $invoice_no .= ' <i class="fab fa-wordpress text-primary no-print" title="' . __('lang_v1.synced_from_woocommerce') . '"></i>';
                }
                if (!empty($row->shopify_order_id)) {
                    $invoice_no .= ' <i style=" color: #95BF47 !important;" <span  class="fa fa-shopping-bag text-primary no-print" title="' . __('lang_v1.synced_from_woocommerce') . '"></i>';
                }
                if (!empty($row->exchange_exists) && $row->exchange_exists > 0) {
                    $invoice_no .= ' &nbsp;<small class="label bg-orange label-round no-print" title="' . __('messages.exchange') . '"><i class="fas fa-exchange-alt"></i></small>';
                }
                if (!empty($row->return_exists) && $row->exchange_exists == 0) {
                    $invoice_no .= ' &nbsp;<small class="label bg-red label-round no-print" title="' . __('lang_v1.some_qty_returned_from_sell') . '"><i class="fas fa-undo"></i></small>';
                }
                if (!empty($row->is_recurring)) {
                    $invoice_no .= ' &nbsp;<small class="label bg-red label-round no-print" title="' . __('lang_v1.subscribed_invoice') . '"><i class="fas fa-recycle"></i></small>';
                }

                if (!empty($row->is_recurring_reminder)) {
                    $invoice_no .= ' &nbsp;<small class="label bg-green label-round no-print" title="' . __('lang_v1.reminded_invoice') . '"><i class="fas fa-bell"></i></small>';
                }

                if (!empty($row->is_suspend)) {
                    $invoice_no .= ' &nbsp;<small class="label bg-orange label-round no-print" title="' . __('lang_v1.suspended_sales') . '"><i class="fas fa-pause-circle"></i></small>';
                }

                if (!empty($row->recur_parent_id)) {
                    $invoice_no .= ' &nbsp;<small class="label bg-info label-round no-print" title="' . __('lang_v1.subscription_invoice') . '"><i class="fas fa-recycle"></i></small>';
                }
                if ($row->ogf_is_sync) {
                    $invoice_no .= ' <i style=" color: #00ec1f !important;" <span  class="fa fa-life-ring text-primary no-print" title="' . __('lang_v1.synced_to_ogf') . '"></i>';
                }

                return $invoice_no;
            })
            ->editColumn('shipping_status', function ($row) use ($shipping_statuses) {
                $status_color = !empty($this->shipping_status_colors[$row->shipping_status]) ? $this->shipping_status_colors[$row->shipping_status] : 'bg-gray';
                $status = !empty($row->shipping_status) ? '<a href="#" class="btn-modal" data-href="' . remote_action('SellController@editShipping',
                        [$row->id]) . '" data-container=".view_modal"><span class="label ' . $status_color . '">' . $shipping_statuses[$row->shipping_status] . '</span></a>' : '';

                return $status;
            })
            ->addColumn('payment_methods', function ($row) use ($payment_types) {
                $methods = array_unique($row->payment_lines->pluck('method')->toArray());
                $count = count($methods);
                $payment_method = '';
                if ($count == 1) {
                    $payment_method = $payment_types[$methods[0]];
                } elseif ($count > 1) {
                    $payment_method = __('lang_v1.checkout_multi_pay');
                }

                $html = !empty($payment_method) ? '<span class="payment-method" data-orig-value="' . $payment_method . '" data-status-name="' . $payment_method . '">' . $payment_method . '</span>' : '';

                return $html;
            })
            ->editColumn(
                'sale_type',
                '<span class="service-type-label" data-orig-value="{{$sale_type}}" data-status-name="{{$sale_type}}">{{$sale_type}}</span>'
            )
            ->addColumn('mass_delete', function ($row) {
                return $row->id;
            })
            ->addColumn('com_agent', function ($row) {
                return $row->com_agent;
            })
            ->editColumn('multi_sale_type', function ($row) {

                $msts = MultiSaleTypes::where('transaction_id', $row->id)->leftJoin('sale_type_multis as sts',
                    'multi_sale_types.sale_type', '=', 'sts.id')->pluck('sts.name');
                $multi_sale_type = null;
                foreach ($msts as $mst) {
                    $multi_sale_type .= '&nbsp;' . $mst . '</br>';
                }

                return $multi_sale_type;

            })
            ->setRowAttr([
                'data-href' => function ($row) {
                    if (auth()->user()->can('sell.view') || auth()->user()->can('view_own_sell_only')) {
                        return remote_action('SellController@show', [$row->id]);
                    } else {
                        return '';
                    }
                },
            ]);

        $rawColumns = [
            'final_total', 'Business_name', 'address', 'action', 'total_paid', 'total_remaining', 'payment_status',
            'invoice_no', 'discount_amount', 'tax_amount', 'total_before_tax', 'shipping_status',
            'types_of_service_name', 'payment_methods', 'return_due', 'sale_type', 'multi_sale_type', 'com_agent',
        ];

        return $datatable->rawColumns($rawColumns)
            ->make(true);
    }

    public function getLotReport(Request $request): JsonResponse
    {
        if (!auth()->user()->can('reports.lot_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = auth()->user()->business_id;

        $query = Product::where('products.business_id', $business_id)
            ->leftjoin('units', 'products.unit_id', '=', 'units.id')
            ->join('variations as v', 'products.id', '=', 'v.product_id')
            ->leftjoin('purchase_lines as pl', function ($join) {
                $join->on('v.id', '=', 'pl.variation_id')
                    ->join('transactions as plt', 'plt.id', '=', 'pl.transaction_id')
                    ->whereNotIn('plt.status', ['pending', 'cancelled', 'in_transit'])
                    ->groupBy('plt.location_id');
            })
            ->leftjoin('transaction_sell_lines_purchase_lines AS tspl', function ($join) {
                $join->on('pl.id', '=', 'tspl.purchase_line_id')
                    ->leftjoin('transaction_sell_lines as tsltt', 'tsltt.id', '=', 'tspl.sell_line_id')
                    ->leftjoin('transactions as tslt', 'tslt.id', '=', 'tsltt.transaction_id')
                    ->whereNotIn('tslt.status', ['pending', 'cancelled'])
                    ->groupBy('tslt.location_id');
            })
            ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
            ->join('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->whereNotIn('t.status', ['pending', 'cancelled'])
            ->where('products.is_inactive', 0);


        $permitted_locations = auth()->user()->permitted_locations();
        $location_filter = 'WHERE ';

        if ($permitted_locations != 'all') {
            $query->whereIn('t.location_id', $permitted_locations);

            $locations_imploded = implode(', ', $permitted_locations);
            $location_filter = " LEFT JOIN transactions as t2 on pls.transaction_id=t2.id WHERE t2.location_id IN ($locations_imploded) AND t2.status NOT IN ('pending','cancelled') AND";
        }

        if (!empty($request->input('location_id'))) {
            $location_id = $request->input('location_id');
            $query->where('t.location_id', $location_id)
                //If filter by location then hide products not available in that location
                ->ForLocation($location_id);

        }

        if (!empty($request->input('category_id'))) {
            $query->where('products.category_id', $request->input('category_id'));
        }

        if (!empty($request->input('sub_category_id'))) {
            $query->where('products.sub_category_id', $request->input('sub_category_id'));
        }

        if (!empty($request->input('brand_id'))) {
            $query->where('products.brand_id', $request->input('brand_id'));
        }

        if (!empty($request->input('unit_id'))) {
            $query->where('products.unit_id', $request->input('unit_id'));
        }

        $only_mfg_products = request()->get('only_mfg_products', 0);
        if (!empty($only_mfg_products)) {
            $query->where('t.type', 'production_purchase');
        }


        $products = $query->select(
            'products.name as product',
            'v.name as variation_name',
            'sub_sku',
            'pl.lot_number',
            'pl.exp_date as exp_date',
            'bl.name as location',
            DB::raw("(COALESCE((SELECT SUM(quantity-quantity_returned) FROM purchase_lines
                   INNER JOIN transactions ON transactions.id = purchase_lines.transaction_id WHERE transactions.type NOT IN ('purchase_transfer')
            AND purchase_lines.variation_id=v.id
            AND pl.lot_number = purchase_lines.lot_number
            AND t.location_id = transactions.location_id
            AND status = 'received'),0)) AS purchase_qty"),
            DB::raw("(COALESCE((SELECT SUM(quantity) FROM transaction_sell_lines AS sell
                   INNER JOIN transactions ON transactions.id = sell.transaction_id WHERE transactions.type ='sell_transfer'
            AND sell.variation_id=v.id
            AND pl.id = sell.lot_no_line_id
            AND t.location_id = transactions.location_id
            AND status IN ('received','final','in_transit')),0)) as st_out_qty"),
            DB::raw("(COALESCE((SELECT SUM(quantity) FROM purchase_lines
                   INNER JOIN transactions ON transactions.id = purchase_lines.transaction_id WHERE transactions.type ='purchase_transfer'
            AND purchase_lines.variation_id=v.id
            AND pl.lot_number = purchase_lines.lot_number
            AND t.location_id = transactions.location_id
            AND status = 'received'),0)) as st_in_qty"),
            DB::raw("(COALESCE((SELECT SUM(tspl.quantity-tspl.qty_returned) FROM transaction_sell_lines as sell
                   INNER JOIN transactions ON transactions.id = sell.transaction_id
                   INNER JOIN purchase_lines as plsl ON tspl.purchase_line_id = plsl.id
                   WHERE transactions.type NOT IN ('sell_transfer')
            AND sell.variation_id=v.id
            AND t.location_id = transactions.location_id
            AND tspl.sell_line_id = sell.id
            AND plsl.lot_number IS NOT NUll
            AND status = 'final'),0)) as total_sold"),
            DB::raw("(COALESCE((SELECT SUM(quantity - (quantity_sold + quantity_returned + quantity_adjusted + mfg_quantity_used)) FROM purchase_lines
                   INNER JOIN transactions ON transactions.id = purchase_lines.transaction_id
                   WHERE status = 'received'
            AND purchase_lines.variation_id=v.id
            AND t.location_id = transactions.location_id
            AND pl.lot_number = purchase_lines.lot_number
                   ))) AS stock"),
            DB::raw("(COALESCE((SELECT SUM(quantity_adjusted) FROM purchase_lines
                   INNER JOIN transactions ON transactions.id = purchase_lines.transaction_id
                   WHERE status = 'received'
            AND purchase_lines.variation_id=v.id
            AND t.location_id = transactions.location_id
            AND pl.lot_number = purchase_lines.lot_number
                   ))) AS total_adjusted"),
            'products.type',
            'units.short_name as unit'
        )
            ->whereNotNull('pl.lot_number')
            ->groupBy('v.id')
            ->groupBy('t.location_id')
            ->groupBy('pl.lot_number');


        return Datatables::of($products)
            ->editColumn('stock', function ($row) {
                $stock = $row->stock;
                return '<span data-is_quantity="true" class="display_currency total_stock" data-currency_symbol=false data-orig-value="' . (float)$stock . '" data-unit="' . $row->unit . '" >' . (float)$stock . '</span> ' . $row->unit;
            })
            ->editColumn('st_in_qty', function ($row) {
                $st_in_qty = $row->st_in_qty;
                return '<span  data-is_quantity="true" class="display_currency total_stock_transfer_in" data-currency_symbol=false data-orig-value="' . (float)$st_in_qty . '" data-unit="' . $row->unit . '" >' . (float)$st_in_qty . '</span> ' . $row->unit;
            })
            ->editColumn('st_out_qty', function ($row) {
                $st_out_qty = $row->st_out_qty;
                return '<span  data-is_quantity="true" class="display_currency total_stock_transfer_out" data-currency_symbol=false data-orig-value="' . (float)$st_out_qty . '" data-unit="' . $row->unit . '" >' . (float)$st_out_qty . '</span> ' . $row->unit;
            })
            ->editColumn('purchase_qty', function ($row) {
                $purchase_qty = $row->purchase_qty;
                return '<span  data-is_quantity="true" class="display_currency total_purchase" data-currency_symbol=false data-orig-value="' . (float)$purchase_qty . '" data-unit="' . $row->unit . '" >' . (float)$purchase_qty . '</span> ' . $row->unit;
            })
            ->editColumn('product', function ($row) {
                if ($row->variation_name != 'DUMMY') {
                    return $row->product . ' (' . $row->variation_name . ')';
                } else {
                    return $row->product;
                }
            })
            ->editColumn('total_sold', function ($row) {
                if ($row->total_sold) {
                    return '<span data-is_quantity="true" class="display_currency total_sold" data-currency_symbol=false data-orig-value="' . (float)$row->total_sold . '" data-unit="' . $row->unit . '" >' . (float)$row->total_sold . '</span> ' . $row->unit;
                } else {
                    return '0' . ' ' . $row->unit;
                }
            })
            ->editColumn('total_adjusted', function ($row) {
                if ($row->total_adjusted) {
                    return '<span data-is_quantity="true" class="display_currency total_adjusted" data-currency_symbol=false data-orig-value="' . (float)$row->total_adjusted . '" data-unit="' . $row->unit . '" >' . (float)$row->total_adjusted . '</span> ' . $row->unit;
                } else {
                    return '0' . ' ' . $row->unit;
                }
            })
            ->editColumn('exp_date', function ($row) {
                if (!empty($row->exp_date)) {
                    $carbon_exp = Carbon::createFromFormat('Y-m-d', $row->exp_date);
                    $carbon_now = Carbon::now();
                    if ($carbon_now->diffInDays($carbon_exp, false) >= 0) {
                        return $this->productUtil->format_date($row->exp_date) . '<br><small>( <span class="time-to-now">' . $row->exp_date . '</span> )</small>';
                    } else {
                        return $this->productUtil->format_date($row->exp_date) . ' &nbsp; <span class="label label-danger no-print">' . __('report.expired') . '</span><span class="print_section">' . __('report.expired') . '</span><br><small>( <span class="time-from-now">' . $row->exp_date . '</span> )</small>';
                    }
                } else {
                    return '--';
                }
            })
            ->removeColumn('unit')
            ->removeColumn('id')
            ->removeColumn('variation_name')
            ->rawColumns([
                'exp_date', 'stock', 'total_sold', 'total_adjusted', 'st_in_qty', 'st_out_qty', 'purchase_qty'
            ])
            ->make(true);
    }

    public function product_datatable()
    {
        try {
            $business_id = auth()->user()->business_id;
            $selling_price_group_count = SellingPriceGroup::countSellingPriceGroups($business_id);
            $blade_name = $this->checkWhichProductReport($business_id);

            $variation_id = request()->get('variation_id', null);

            $query = Product::leftJoin('brands', 'products.brand_id', '=', 'brands.id')
                ->join('units', 'products.unit_id', '=', 'units.id')
                ->leftJoin('categories as c1', 'products.category_id', '=', 'c1.id')
                ->leftJoin('categories as c2', 'products.sub_category_id', '=', 'c2.id')
                ->leftJoin('tax_rates', 'products.tax', '=', 'tax_rates.id')
                ->join('variations as v', 'v.product_id', '=', 'products.id')
                ->leftJoin('variation_location_details as vld', 'vld.variation_id', '=', 'v.id')
                ->where('products.business_id', $business_id)
                ->where('products.type', '!=', 'modifier');

            //Filter by location
            $location_id = request()->get('location_id', null);
            $permitted_locations = auth()->user()->permitted_locations();

            if (!empty($location_id) && $location_id != 'none') {
                if ($permitted_locations == 'all' || in_array($location_id, $permitted_locations)) {
                    $query->whereHas('product_locations', function ($query) use ($location_id) {
                        $query->where('product_locations.location_id', '=', $location_id);
                    });
                }
            } elseif ($location_id == 'none') {
                $query->doesntHave('product_locations');
            } else {
                if ($permitted_locations != 'all') {
                    $query->whereHas('product_locations', function ($query) use ($permitted_locations) {
                        $query->whereIn('product_locations.location_id', $permitted_locations);
                    });
                } else {
                    $query->with('product_locations');
                }
            }

            $products = $query->select(
                'products.id',
                'products.name as product_name',
                'products.type',
                'c1.name as category',
                'c2.name as sub_category',
                'units.actual_name as unit',
                'brands.name as brand',
                'tax_rates.name as tax',
                'products.sku',
                'products.image',
                'products.enable_stock',
                'products.is_inactive',
                'products.woocommerce_disable_sync',
                'products.not_for_selling',
                'products.product_custom_field1',
                'products.product_custom_field2',
                'products.product_custom_field3',
                'products.product_custom_field4',
                'products.created_at',
                'products.updated_at',
                DB::raw('SUM(vld.qty_available) as current_stock'),
                DB::raw('MAX(v.sell_price_inc_tax) as max_price'),
                DB::raw('MIN(v.sell_price_inc_tax) as min_price'),
                DB::raw('MAX(v.dpp_inc_tax) as max_purchase_price'),
                DB::raw('MIN(v.dpp_inc_tax) as min_purchase_price'),
                'products.weight as weight'

            )->groupBy('products.id');

            if (!empty($variation_id)) {
                $products->where('v.id', $variation_id);
            }

            $type = request()->get('type', null);
            if (!empty($type)) {
                $products->where('products.type', $type);
            }

            $category_id = request()->get('category_id', null);
            if (!empty($category_id)) {
                $products->where('products.category_id', $category_id);
            }

            $brand_id = request()->get('brand_id', null);
            if (!empty($brand_id)) {
                $products->where('products.brand_id', $brand_id);
            }

            $unit_id = request()->get('unit_id', null);
            if (!empty($unit_id)) {
                $products->where('products.unit_id', $unit_id);
            }

            $tax_id = request()->get('tax_id', null);
            if (!empty($tax_id)) {
                $products->where('products.tax', $tax_id);
            }

            $active_state = request()->get('active_state', null);
            if ($active_state == 'active') {
                $products->Active();
            }
            if ($active_state == 'inactive') {
                $products->Inactive();
            }
            $not_for_selling = request()->get('not_for_selling', null);
            if ($not_for_selling == 'true') {
                $products->ProductNotForSales();
            }


            $enable_stock = request()->get('product_service_filter');
            if ($enable_stock == "1") {
                $products->where('products.enable_stock', 1);
            }
            if ($enable_stock == "0") {
                $products->where('products.enable_stock', 0);
            }

            $woocommerce_enabled = request()->get('woocommerce_enabled', 0);
            if ($woocommerce_enabled == 1) {
                $products->where('products.woocommerce_disable_sync', 0);
            }

            if (!empty(request()->get('repair_model_id'))) {
                $products->where('products.repair_model_id', request()->get('repair_model_id'));
            }

            return Datatables::of($products)
                ->addColumn(
                    'product_locations',
                    function ($row) {
                        return $row->product_locations->implode('name', ', ');
                    }
                )
                ->editColumn('category', '{{$category}} @if(!empty($sub_category))<br/> -- {{$sub_category}}@endif')
                ->addColumn(
                    'action',
                    function ($row) use ($selling_price_group_count) {
                        $html =
                            '<div class="btn-group"><button type="button" class="btn btn-info dropdown-toggle btn-xs" data-toggle="dropdown" aria-expanded="false">' . __("messages.actions") . '<span class="caret"></span><span class="sr-only">Toggle Dropdown</span></button><ul class="dropdown-menu dropdown-menu-left" role="menu"><li><a href="' . remote_action('LabelsController@show') . '?product_id=' . $row->id . '" data-toggle="tooltip" title="' . __('lang_v1.label_help') . '"><i class="fa fa-barcode"></i> ' . __('barcode.labels') . '</a></li>';

                        if (auth()->user()->can('product.view')) {
                            $html .=
                                '<li><a href="' . remote_action('ProductController@view',
                                    [$row->id]) . '" class="view-product"><i class="fa fa-eye"></i> ' . __("messages.view") . '</a></li>';
                        }

                        if (auth()->user()->can('product.update')) {
                            $html .=
                                '<li><a href="' . remote_action('ProductController@edit',
                                    [$row->id]) . '"><i class="glyphicon glyphicon-edit"></i> ' . __("messages.edit") . '</a></li>';
                        }

                        if (auth()->user()->can('product.delete')) {
                            $html .=
                                '<li><a href="' . $this->remote_action('ProductController@destroy',
                                    [$row->id]) . '" class="delete-product"><i class="fa fa-trash"></i> ' . __("messages.delete") . '</a></li>';
                        }

                        if ($row->is_inactive == 1) {
                            $html .=
                                '<li><a href="' . remote_action('ProductController@activate',
                                    [$row->id]) . '" class="activate-product"><i class="fas fa-check-circle"></i> ' . __("lang_v1.reactivate") . '</a></li>';
                        }

                        $html .= '<li class="divider"></li>';

                        if ($row->enable_stock == 1 && auth()->user()->can('product.opening_stock')) {
                            $html .=
                                '<li><a href="#" data-href="' . remote_action('OpeningStockController@add',
                                    ['product_id' => $row->id]) . '" class="add-opening-stock"><i class="fa fa-database"></i> ' . __("lang_v1.add_edit_opening_stock") . '</a></li>';
                        }

                        if (auth()->user()->can('product.view')) {
                            $html .=
                                '<li><a href="' . remote_action('ProductController@productStockHistory',
                                    [$row->id]) . '"><i class="fas fa-history"></i> ' . __("lang_v1.product_stock_history") . '</a></li>';
                        }

                        if (auth()->user()->can('product.create')) {

                            if ($selling_price_group_count > 0) {
                                $html .=
                                    '<li><a href="' . remote_action('ProductController@addSellingPrices',
                                        [$row->id]) . '"><i class="fas fa-money-bill-alt"></i> ' . __("lang_v1.add_selling_price_group_prices") . '</a></li>';
                            }

                            $html .=
                                '<li><a href="' . remote_action('ProductController@create',
                                    ["d" => $row->id]) . '"><i class="fa fa-copy"></i> ' . __("lang_v1.duplicate_product") . '</a></li>';
                        }

                        $html .= '</ul></div>';

                        return $html;
                    }
                )
                ->editColumn('product', function ($row) {
                    $html = "<div> " . $row->product_name . '</br>';
                    if ($row->is_inactive == 1) {
                        $html .= ' <span class="label bg-gray">' . __("lang_v1.inactive") . '</span>';
                    }
                    if ($row->not_for_selling == 1) {
                        $html .= ' <span class="label bg-gray">' . __("lang_v1.not_for_selling") . '</span>';
                    }
                    $html .= '</div>';
                    return $html;
                })
                ->editColumn('image', function ($row) {
                    return '<div style="display: flex;"><img src="' . $row->image_url . '" alt="Product image" class="product-thumbnail-small"></div>';
                })
                ->editColumn('type', '@lang("lang_v1." . $type)')
                ->addColumn('mass_delete', function ($row) {
                    return '<input type="checkbox" class="row-select" value="' . $row->id . '">';
                })
                ->editColumn('current_stock', function ($row) use ($blade_name) {
                    if ($blade_name == 'new_list') {
                        $current_stock = $this->productUtil->num_uf($row->current_stock);
                    } else {
                        $current_stock = $this->productUtil->num_uf($row->current_stock) . " " . $row->unit;
                    }
                    return $current_stock;
                })
                ->addColumn('purchase_price', function ($row) {

                    $min_purchase_price = $this->productUtil->num_f($row->min_purchase_price);
                    if ($row->max_purchase_price != $row->min_purchase_price && $row->type == "variable") {
                        return '<div style="white-space: nowrap;"><span class="display_currency" data-currency_symbol="true">' . $min_purchase_price . '</span> - <span class="display_currency" data-currency_symbol="true">' . $this->productUtil->num_f($row->max_purchase_price) . '</span></div>';
                    } else {
                        return '<span class="display_currency" data-currency_symbol="true">' . $min_purchase_price . '</span>';
                    }
                })
                ->editColumn('selling_price', function ($row) {
                    $min_price = $this->productUtil->num_f($row->min_price);
                    if ($row->max_price != $row->min_price && $row->type == "variable") {
                        return '<div style="white-space: nowrap;"><span class="display_currency" data-currency_symbol="true">' . $min_price . '</span> - <span class="display_currency" data-currency_symbol="true">' . $this->productUtil->num_f($row->max_price) . '</span></div>';
                    } else {
                        return '<span class="display_currency" data-currency_symbol="true">' . $min_price . '</span>';
                    }
                })
                ->editColumn('created_at', '{{@format_datetime($created_at)}}')
                ->editColumn('updated_at', '{{@format_datetime($updated_at)}}')
                ->filterColumn('products.sku', function ($query, $keyword) {
                    $query->whereHas('variations', function ($q) use ($keyword) {
                        $q->where('sub_sku', 'like', "%{$keyword}%");
                    })
                        ->orWhere('products.sku', 'like', "%{$keyword}%");
                })
                ->setRowAttr([
                    'data-href' => function ($row) {
                        if (auth()->user()->can("product.view")) {
                            return remote_action('ProductController@view', [$row->id]);
                        } else {
                            return '';
                        }
                    }
                ])
                ->rawColumns([
                    'action', 'image', 'mass_delete', 'product', 'selling_price', 'purchase_price', 'category',
                    'created_at', 'updated_at'
                ])
                ->make(true);
        } catch (Exception $e) {
            if (request()->input('test_purposes')) {
                dd($e);
            }
        }
    }

    /**
     * @param $business_id
     * @return string value like new_list or old_list
     */
    private function checkWhichProductReport($business_id): string
    {
        $new_product_list_list = config('constants.business_ids_for_new_product_list_report');
        if (in_array($business_id, explode(',', $new_product_list_list))) {
            $blade_name = 'new_list';
        } else {
            $blade_name = 'old_list';
        }
        return $blade_name;
    }

    public function getStockPosReport(Request $request): JsonResponse
    {
        $business_id = auth()->user()->business_id;

        if ($this->moduleUtil->isModuleInstalled('Manufacturing')
            && (auth()->user()->can('superadmin')
                || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'manufacturing_module'))) {
            $show_manufacturing_data = 1;
        } else {
            $show_manufacturing_data = 0;
        }

        $filters = request()->only([
            'location_id', 'category_id', 'sub_category_id', 'brand_id', 'unit_id', 'tax_id', 'type',
            'only_mfg_products', 'active_state', 'not_for_selling', 'repair_model_id', 'product_id', 'active_state'
        ]);

        $filters['not_for_selling'] = isset($filters['not_for_selling']) && $filters['not_for_selling'] == 'true' ? 1 : 0;

        $filters['show_manufacturing_data'] = $show_manufacturing_data;

        $products = $this->productUtil->getProductStockDetailsPos($business_id, $filters, 'datatables');

        $datatable = Datatables::of($products)
            ->editColumn('stock', function ($row) {
                if ($row->enable_stock) {
                    $stock = $row->stock ? $row->stock : 0;
                    return '<span data-is_quantity="true" class="current_stock display_currency" data-orig-value="' . (float)$stock . '" data-unit="' . $row->unit . '" data-currency_symbol=false > ' . (float)$stock . '</span>' . ' ' . $row->unit;
                } else {
                    return 'N/A';
                }
            })
            ->editColumn('product', function ($row) {
                $name = $row->product;
                if ($row->type == 'variable') {
                    $name .= ' - ' . $row->product_variation . '-' . $row->variation_name;
                }
                return $name;
            })
            ->editColumn('unit_price', function ($row) {
                return '<span class="display_currency" data-currency_symbol=true >' . $row->unit_price . '</span>';
            })
            ->removeColumn('enable_stock')
            ->removeColumn('unit')
            ->removeColumn('id');

        $raw_columns = ['unit_price', 'stock'];

        return $datatable->rawColumns($raw_columns)->make(true);

    }

    public function return_sales_popup_datatable(): JsonResponse
    {
        $business_id = auth()->user()->business_id;
        $user_id = auth()->user()->id;
        $payment_types = $this->transactionUtil->payment_types(null, true, $business_id);
        $with = [];
        $shipping_statuses = $this->transactionUtil->shipping_statuses();
        $sells = $this->transactionUtil->getListSells($business_id);

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $sells->whereIn('transactions.location_id', $permitted_locations);
        }

        if (!auth()->user()->can('direct_sell.access') && auth()->user()->can('view_own_sell_only')) {
            $sells->where('transactions.created_by', $user_id);
        }

        $sells->orderBy('transactions.transaction_date', 'DESC');

        $only_shipments = request()->only_shipments == 'true' ? true : false;
        if ($only_shipments && auth()->user()->can('access_shipping')) {
            $sells->whereNotNull('transactions.shipping_status');
        }

        $sells->groupBy('transactions.id');

        if ($this->businessUtil->isModuleEnabled('subscription')) {
            $sells->addSelect('transactions.is_recurring', 'transactions.recur_parent_id');
        }
        if ($this->businessUtil->isModuleEnabled('reminder')) {
            $sells->addSelect('transactions.is_recurring_reminder');
        }

        $datatable = Datatables::of($sells)
            ->addColumn(
                'action',
                function ($row) use ($only_shipments) {
                    $html = '<div class="btn-group no-print">
                                <button type="button" class="btn btn-warning btn-xs"
                                    data-toggle="dropdown" aria-expanded="false">' .
                        '<span style="bacground:red" href="#" data-href="' . remote_action("SellController@return_sale",
                            [$row->id]) . '" class="btn-modal" data-container=".view_modal"><i class="fas fa-undo" aria-hidden="true"></i> ' . __("lang_v1.sell_return") . '</sapan>
                                </button>';

                    $html .= '</div>';

                    return $html;
                }
            )
            ->removeColumn('id')
            ->editColumn(
                'final_total',
                '<span class="display_currency final-total" data-currency_symbol="true" data-orig-value="{{$final_total}}">{{$final_total}}</span>'
            )
            ->editColumn(
                'tax_amount',
                '<span class="display_currency total-tax" data-currency_symbol="true" data-orig-value="{{$tax_amount}}">{{$tax_amount}}</span>'
            )
            ->editColumn(
                'total_paid',
                '<span class="display_currency total-paid" data-currency_symbol="true" data-orig-value="{{$total_paid}}">{{$total_paid}}</span>'
            )
            ->editColumn(
                'total_before_tax',
                '<span class="display_currency total_before_tax" data-currency_symbol="true" data-orig-value="{{$total_before_tax}}">{{$total_before_tax}}</span>'
            )
            ->editColumn(
                'discount_amount',
                function ($row) {
                    $discount = !empty($row->discount_amount) ? $row->discount_amount : 0;

                    if (!empty($discount) && $row->discount_type == 'percentage') {
                        $discount = $row->total_before_tax * ($discount / 100);
                    }

                    return '<span class="display_currency total-discount" data-currency_symbol="true" data-orig-value="' . $discount . '">' . $discount . '</span>';
                }
            )
            ->editColumn('transaction_date',
                function ($row) {
                    $carbonDate = Carbon::createFromFormat('Y-m-d H:i:s', $row->transaction_date);
                    $date_format = auth()->user()->business->date_format;
                    $time_format = (auth()->user()->business->time_format = '24') ? 'H:i' : 'h:i A';
                    $datetime_format = $date_format . ' ' . $time_format;
                    return $carbonDate->format($datetime_format);
                })
            ->editColumn(
                'payment_status',
                function ($row) {
                    $payment_status = Transaction::getPaymentStatus($row);
                    return (string)view('sell.partials.payment_status',
                        ['payment_status' => $payment_status, 'id' => $row->id]);
                }
            )
            ->editColumn(
                'types_of_service_name',
                '<span class="service-type-label" data-orig-value="{{$types_of_service_name}}" data-status-name="{{$types_of_service_name}}">{{$types_of_service_name}}</span>'
            )
            ->addColumn('total_remaining', function ($row) {
                $total_remaining = $row->final_total - $row->total_paid;
                $total_remaining_html = '<span class="display_currency payment_due" data-currency_symbol="true" data-orig-value="' . $total_remaining . '">' . $total_remaining . '</span>';


                return $total_remaining_html;
            })
            ->addColumn('return_due', function ($row) {
                $return_due_html = '';
                if (!empty($row->return_exists)) {
                    $return_due = $row->amount_return - $row->return_paid;
                    $return_due_html .= '<a href="' . remote_action("TransactionPaymentController@show",
                            [$row->return_transaction_id]) . '" class="view_purchase_return_payment_modal"><span class="display_currency sell_return_due" data-currency_symbol="true" data-orig-value="' . $return_due . '">' . $return_due . '</span></a>';
                }

                return $return_due_html;
            })
            ->addColumn('Business_name', function ($row) {
                return $row->supplier_business_name;
            })
            ->addColumn('contact_no', function ($row) {
                return $row->customer_mobile_number;
            })
            ->addColumn('address', function ($row) {
                $address_line_1 = '';
                $address_line_2 = '';
                $city = '';
                $state = '';
                $country = '';
                $zip_code = '';
                if (!empty($row->address_line_1)) {
                    $address_line_1 = $row->address_line_1 . ',';
                }
                if (!empty($row->address_line_2)) {
                    $address_line_2 = $row->address_line_2 . ',';
                }
                if (!empty($row->city)) {
                    $city = $row->city . ',';
                }
                if (!empty($row->state)) {
                    $state = $row->state . ',';
                }
                if (!empty($row->country)) {
                    $country = $row->country . ',';
                }
                if (!empty($row->zip_code)) {
                    $zip_code = $row->zip_code . '';
                }
                $address = '<div>' . $address_line_1 . '' . $address_line_2 . '' . $city . '' . $state . '' . $country . '' . $zip_code . '</div>';
                return $address;
            })
            ->editColumn('invoice_no', function ($row) {
                $invoice_no = $row->invoice_no;
                if (!empty($row->woocommerce_order_id)) {
                    $invoice_no .= ' <i class="fab fa-wordpress text-primary no-print" title="' . __('lang_v1.synced_from_woocommerce') . '"></i>';
                }
                if (!empty($row->return_exists)) {
                    $invoice_no .= ' &nbsp;<small class="label bg-red label-round no-print" title="' . __('lang_v1.some_qty_returned_from_sell') . '"><i class="fas fa-undo"></i></small>';
                }
                if (!empty($row->is_recurring)) {
                    $invoice_no .= ' &nbsp;<small class="label bg-red label-round no-print" title="' . __('lang_v1.subscribed_invoice') . '"><i class="fas fa-recycle"></i></small>';
                }

                if (!empty($row->is_recurring_reminder)) {
                    $invoice_no .= ' &nbsp;<small class="label bg-green label-round no-print" title="' . __('lang_v1.reminded_invoice') . '"><i class="fas fa-bell"></i></small>';
                }

                if (!empty($row->recur_parent_id)) {
                    $invoice_no .= ' &nbsp;<small class="label bg-info label-round no-print" title="' . __('lang_v1.subscription_invoice') . '"><i class="fas fa-recycle"></i></small>';
                }

                return $invoice_no;
            })
            ->editColumn('shipping_status', function ($row) use ($shipping_statuses) {
                $status_color = !empty($this->shipping_status_colors[$row->shipping_status]) ? $this->shipping_status_colors[$row->shipping_status] : 'bg-gray';
                $status = !empty($row->shipping_status) ? '<a href="#" class="btn-modal" data-href="' . remote_action('SellController@editShipping',
                        [$row->id]) . '" data-container=".view_modal"><span class="label ' . $status_color . '">' . $shipping_statuses[$row->shipping_status] . '</span></a>' : '';

                return $status;
            })
            ->addColumn('payment_methods', function ($row) use ($payment_types) {
                $methods = array_unique($row->payment_lines->pluck('method')->toArray());
                $count = count($methods);
                $payment_method = '';
                if ($count == 1) {
                    $payment_method = $payment_types[$methods[0]];
                } elseif ($count > 1) {
                    $payment_method = __('lang_v1.checkout_multi_pay');
                }

                $html = !empty($payment_method) ? '<span class="payment-method" data-orig-value="' . $payment_method . '" data-status-name="' . $payment_method . '">' . $payment_method . '</span>' : '';

                return $html;
            })
            ->editColumn(
                'sale_type',
                '<span class="service-type-label" data-orig-value="{{$sale_type}}" data-status-name="{{$sale_type}}">{{$sale_type}}</span>'
            )
            ->editColumn('multi_sale_type', function ($row) {

                $msts = MultiSaleTypes::where('transaction_id', $row->id)->leftJoin('sale_type_multis as sts',
                    'multi_sale_types.sale_type', '=', 'sts.id')->pluck('sts.name');
                $multi_sale_type = null;
                foreach ($msts as $mst) {
                    $multi_sale_type .= '&nbsp;' . $mst . '</br>';
                }
                return $multi_sale_type;

            })
            ->setRowAttr([
                'data-href' => function ($row) {
                    if (auth()->user()->can("sell.view") || auth()->user()->can("view_own_sell_only")) {
                        return remote_action('SellController@show', [$row->id]);
                    } else {
                        return '';
                    }
                }
            ]);

        $rawColumns = [
            'final_total', 'Business_name', 'address', 'action', 'total_paid', 'total_remaining', 'contact_no',
            'payment_status', 'invoice_no', 'discount_amount', 'tax_amount', 'total_before_tax', 'shipping_status',
            'types_of_service_name', 'payment_methods', 'return_due', 'sale_type', 'multi_sale_type'
        ];
        return $datatable->rawColumns($rawColumns)
            ->make(true);
    }

    public function lotSummaryReport(Request $request): JsonResponse
    {
        $report_id = $request->input('report_id');
        $product = $this->reportQueryUtil->queueReportData('lot_summary_report', $report_id);

        return Datatables::of($product)
            ->make(true);

    }
}
