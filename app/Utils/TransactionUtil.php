<?php

namespace App\Utils;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\Courier_company;
use App\Models\Currency;
use App\Models\InvoiceReprintCounts;
use App\Models\InvoiceScheme;
use App\Models\payment_fee_rates;
use App\Models\PurchaseLine;
use App\Models\Restaurant\ResTable;
use App\Models\SaleType;
use App\Models\SellingPriceGroup;
use App\Models\TaxRate;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\TransactionSellLine;
use App\Models\TransactionSellLinesPurchaseLines;
use App\Models\TypesOfService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GiftCard\Entities\GiftCard;
use Modules\PromoCard\Entities\PromoCardRedeemReport;

//use App\Models\Events\TransactionPaymentAdded;
//use App\Models\Events\TransactionPaymentDeleted;
//use App\Models\Events\TransactionPaymentUpdated;
//use App\Models\Events\UpdateContactExchangeAmount;
//use App\Models\Exceptions\PurchaseSellMismatch;

class TransactionUtil extends Util
{
    protected $productUtil;

    public function __construct()
    {
        $this->productUtil = new ProductUtil();
    }

    /**
     * Get payment line for a transaction
     *
     * @param  int  $transaction_id
     * @return bool
     */
    public function getPaymentDetails($transaction_id)
    {
        $payment_lines = TransactionPayment::where('transaction_id', $transaction_id)
            ->get()->toArray();

        return $payment_lines;
    }

    /**
     * Gives the receipt details in proper format.
     *
     * @param  int  $transaction_id
     * @param  int  $location_id
     * @param  object  $invoice_layout
     * @param  array  $business_details
     * @param  array  $receipt_details
     * @param  string  $receipt_printer_type
     * @return array
     */
    public function getReceiptDetails($transaction_id, $location_id, $invoice_layout, $business_details, $location_details, $receipt_printer_type, $total_line_discount = null)
    {
        $il = $invoice_layout;
        $transaction = Transaction::find($transaction_id);
        $transaction_type = $transaction->type;
        $business_id = $business_details->id;
        $unit_d = DB::select('select * from units where business_id = ?', [$business_id]);
        $output = [
            'header_text' => isset($il->header_text) ? $il->header_text : '',
            'business_name' => ($il->show_business_name == 1) ? $business_details->name : '',
            'location_name' => ($il->show_location_name == 1) ? $location_details->name : '',
            'sub_heading_line1' => trim($il->sub_heading_line1),
            'sub_heading_line2' => trim($il->sub_heading_line2),
            'sub_heading_line3' => trim($il->sub_heading_line3),
            'sub_heading_line4' => trim($il->sub_heading_line4),
            'sub_heading_line5' => trim($il->sub_heading_line5),
            'table_product_label' => $il->table_product_label,
            'table_qty_label' => $il->table_qty_label,
            'table_unit_price_label' => $il->table_unit_price_label,
            'table_subtotal_label' => $il->table_subtotal_label,
            'lot_number_label' => $il->lot_number_label,
        ];

        //Sale Type
        $output['sale_type'] = $this->getSaleType($transaction->sale_type_id);

        //Waybill Number for Shipment
        $output['waybill_number_shipment'] = ! empty($transaction->waybill_no) ? $transaction->waybill_no : null;
        $courier_companies = Courier_company::find($transaction->courier_company_id);
        $output['courier_name_shipment'] = ! empty($transaction->courier_company_id) ? $courier_companies->Name : null;

        //Display name
        $output['display_name'] = $output['business_name'];
        if (! empty($output['location_name'])) {
            if (! empty($output['display_name'])) {
                $output['display_name'] .= ', ';
            }
            $output['display_name'] .= $output['location_name'];
        }

        //Logo
        $output['logo'] = $il->show_logo != 0 && ! empty($il->logo) && file_exists(public_path('uploads_new/invoice_logos/'.$il->logo)) ? asset('uploads_new/invoice_logos/'.$il->logo) : false;

        //Address
        $output['address'] = '';
        $output['address_type2'] = '';
        $temp = [];
        $temp_2 = [];
        if ($il->show_landmark == 1) {
            $output['address'] .= $location_details->landmark."\n";
            $temp_2[] = $location_details->landmark;
        }
        if ($il->show_city == 1 && ! empty($location_details->city)) {
            $temp[] = $location_details->city;
            $temp_2[] = $location_details->city;
        }
        if ($il->show_state == 1 && ! empty($location_details->state)) {
            $temp[] = $location_details->state;
            $temp_2[] = $location_details->state;
        }
        if ($il->show_zip_code == 1 && ! empty($location_details->zip_code)) {
            $temp[] = $location_details->zip_code;
            $temp_2[] = $location_details->zip_code;
        }
        if ($il->show_country == 1 && ! empty($location_details->country)) {
            $temp[] = $location_details->country;
            $temp_2[] = str_replace(' ', '', $location_details->country);
            //            $temp_2[] = "";
        }
        if (! empty($temp)) {
            $output['address'] .= implode(',', $temp);
            $output['address_type2'] .= implode("\n<br>", $temp_2);
        }

        $output['website'] = $location_details->website;
        $output['location_custom_fields'] = '';
        $temp = [];
        $location_custom_field_settings = ! empty($il->location_custom_fields) ? $il->location_custom_fields : [];
        if (! empty($location_details->custom_field1) && in_array('custom_field1', $location_custom_field_settings)) {
            $temp[] = $location_details->custom_field1;
        }
        if (! empty($location_details->custom_field2) && in_array('custom_field2', $location_custom_field_settings)) {
            $temp[] = $location_details->custom_field2;
        }
        if (! empty($location_details->custom_field3) && in_array('custom_field3', $location_custom_field_settings)) {
            $temp[] = $location_details->custom_field3;
        }
        if (! empty($location_details->custom_field4) && in_array('custom_field4', $location_custom_field_settings)) {
            $temp[] = $location_details->custom_field4;
        }
        if (! empty($temp)) {
            $output['location_custom_fields'] .= implode(', ', $temp);
        }

        //Tax Info
        if ($il->show_tax_1 == 1 && ! empty($business_details->tax_number_1)) {
            $output['tax_label1'] = ! empty($business_details->tax_label_1) ? $business_details->tax_label_1.': ' : '';

            $output['tax_info1'] = $business_details->tax_number_1;
        }
        if ($il->show_tax_2 == 1 && ! empty($business_details->tax_number_2)) {
            if (! empty($output['tax_info1'])) {
                $output['tax_info1'] .= ', ';
            }

            $output['tax_label2'] = ! empty($business_details->tax_label_2) ? $business_details->tax_label_2.': ' : '';

            $output['tax_info2'] = $business_details->tax_number_2;
        }

        //Shop Contact Info
        $output['contact'] = '';
        $output['mobile'] = '';
        $output['email'] = '';
        if ($il->show_mobile_number == 1 && ! empty($location_details->mobile)) {
            $output['contact'] .= __('contact.mobile').': '.$location_details->mobile;
            $output['mobile'] .= $location_details->mobile;
        }
        if ($il->show_alternate_number == 1 && ! empty($location_details->alternate_number)) {
            if (empty($output['contact'])) {
                $output['contact'] .= __('contact.mobile').': '.$location_details->alternate_number;
                $output['mobile'] .= $location_details->alternate_number;
            } else {
                $output['contact'] .= ', '.$location_details->alternate_number;
                $output['mobile'] .= ', '.$location_details->alternate_number;
            }
        }
        if ($il->show_email == 1 && ! empty($location_details->email)) {
            if (! empty($output['contact'])) {
                $output['contact'] .= "\n";

            }
            $output['contact'] .= __('business.email').': '.$location_details->email;
            $output['email'] .= $location_details->email;
        }

        //Customer show_customer
        $customer = Contact::find($transaction->contact_id);
        $output['customer_info'] = '';
        $output['customer_supplier_business_name'] = '';
        $output['customer_tax_number'] = '';
        $output['customer_tax_label'] = '';
        $output['customer_custom_fields'] = '';
        if ($il->show_customer == 1) {
            $output['customer_label'] = ! empty($il->customer_label) ? $il->customer_label : '';
            $output['customer_name'] = ! empty($customer->name) ? $customer->name : '';
            $output['pay_term_number'] = ! empty($customer->pay_term_number) ? $customer->pay_term_number : '';
            $output['pay_term_type'] = ! empty($customer->pay_term_type) ? $customer->pay_term_type : '';
            $output['pay_term'] = '';
            if (! empty($customer->pay_term_number) && ! empty($customer->pay_term_type)) {
                $output['pay_term'] = $output['pay_term_number'].''.$output['pay_term_type'];
            }
            $output['customer_mobile'] = $customer->mobile;
            $output['customer_address'] = ! empty($customer->contact_address) ? $customer->contact_address : '';
            $output['customer_address_type2'] = ! empty($customer->address_line_1) ? $customer->address_line_1.', ' : '';
            $output['customer_address_type2'] .= ! empty($customer->address_line_2) ? $customer->address_line_2.', ' : '';
            $output['customer_address_type2'] .= '<br>';
            $output['customer_address_type2'] .= ! empty($customer->city) ? $customer->city.',' : '';
            $output['customer_address_type2'] .= '<br>';
            $output['customer_address_type2'] .= ! empty($customer->state) ? $customer->state.',' : '';
            $output['customer_address_type2'] .= '<br>';
            $output['customer_address_type2'] .= ! empty($customer->country) ? $customer->country : '';
            $output['customer_address_type2'] .= '<br>';

            if (! empty($output['customer_name']) && $receipt_printer_type != 'printer') {
                $output['customer_info'] .= $customer->contact_address;
                if (! empty($customer->contact_address)) {
                    $output['customer_info'] .= '<br>';
                }
                $output['customer_info'] .= $customer->mobile;
                if (! empty($customer->landline)) {
                    $output['customer_info'] .= ', '.$customer->landline;
                }
                if (! empty($customer->supplier_business_name)) {
                    $output['customer_supplier_business_name'] = $customer->supplier_business_name;
                }
            }

            $output['customer_tax_number'] = $customer->tax_number;
            $output['customer_tax_label'] = ! empty($il->client_tax_label) ? $il->client_tax_label : '';

            $temp = [];
            $customer_custom_fields_settings = ! empty($il->contact_custom_fields) ? $il->contact_custom_fields : [];
            if (! empty($customer->custom_field1) && in_array('custom_field1', $customer_custom_fields_settings)) {
                $temp[] = $customer->custom_field1;
            }
            if (! empty($customer->custom_field2) && in_array('custom_field2', $customer_custom_fields_settings)) {
                $temp[] = $customer->custom_field2;
            }
            if (! empty($customer->custom_field3) && in_array('custom_field3', $customer_custom_fields_settings)) {
                $temp[] = $customer->custom_field3;
            }
            if (! empty($customer->custom_field4) && in_array('custom_field4', $customer_custom_fields_settings)) {
                $temp[] = $customer->custom_field4;
            }
            if (! empty($temp)) {
                $output['customer_custom_fields'] .= implode('<br>', $temp);
            }
        }

        if ($il->show_reward_point == 1) {
            $output['customer_rp_label'] = $business_details->rp_name;
            $output['customer_total_rp'] = $customer->total_rp;
        }

        $output['client_id'] = '';
        $output['client_id_label'] = '';
        if ($il->show_client_id == 1) {
            $output['client_id_label'] = ! empty($il->client_id_label) ? $il->client_id_label : '';
            $output['client_id'] = ! empty($customer->contact_id) ? $customer->contact_id : '';
        }
        //Sales person info
        $output['sales_person'] = '';
        $output['sales_person_first_name'] = '';
        $output['sales_person_label'] = '';
        if ($il->show_sales_person == 1) {
            $output['sales_person_label'] = ! empty($il->sales_person_label) ? $il->sales_person_label : '';
            $output['sales_person'] = ! empty($transaction->sales_person->user_full_name) ? $transaction->sales_person->user_full_name : '';
            $output['sales_person_name'] = ! empty($transaction->sales_person->surname) ? $transaction->sales_person->surname.' ' : ' ';
            $output['sales_person_name'] .= ! empty($transaction->sales_person->first_name) ? $transaction->sales_person->first_name : '';
            $output['contact_number'] = ! empty($transaction->sales_person->contact_number) ? $transaction->sales_person->contact_number : '';
            $output['sales_person_first_name'] .= ! empty($transaction->sales_person->first_name) ? $transaction->sales_person->first_name : '';

        }

        $total = 0;
        $test = TransactionPayment::where('transaction_id', $transaction_id)->get()->toArray();
        if (! empty($test[0]['method'])) {
            $output['payment_method'] = $test[0]['method'];
        }

        if ($il->show_sales_commission_agent == 1) {
            $output['sale_person_or_agent'] = '';
            $output['sale_person_or_agent_contact_no'] = '';

            if (! empty($transaction->commission_agent)) {
                $output['sales_commission_agent_label'] = ! empty($il->sales_commission_agent_label) ? $il->sales_commission_agent_label : '';
                $user = User::findOrFail($transaction->commission_agent);
                $surname = ! empty($user['surname']) ? $user['surname'] : '';
                $first_name = ! empty($user['first_name']) ? $user['first_name'] : '';
                $last_name = ! empty($user['last_name']) ? $user['last_name'] : '';
                $output['sale_person_or_agent'] = $surname.' '.$first_name.' '.$last_name;
                $output['sale_person_or_agent_contact_no'] = ! empty($user['contact_number']) ? $user['contact_number'] : '';
            } else {
                $output['sale_person_or_agent'] = ! empty($transaction->sales_person->user_full_name) ? $transaction->sales_person->user_full_name : '';
                $output['sale_person_or_agent_contact_no'] = ! empty($transaction->sales_person->contact_number) ? $transaction->sales_person->contact_number : '';
            }
            // dd($transaction->commission_agent,$output['sale_person_or_agent']);
        }
        //payment fee
        if (! empty($transaction->payment_fee_id)) {
            $payment_fee = payment_fee_rates::where('business_id', $business_id)
                ->where('id', $transaction->payment_fee_id)->first();
            $output['payment_fee_amount'] = ($transaction->payment_fee_amount == 0) ? 0 : $this->num_f(((float) $transaction->payment_fee_amount), true, $business_details);
            $output['payment_fee_label'] = 'Payment Fee ('.$payment_fee->name.')';
        }

        //multi payment fee
        if (empty($transaction->payment_fee_id) && $transaction->payment_fee_amount > 0) {
            $output['payment_fee_amount'] = $this->num_f($transaction->payment_fee_amount, true);
            $output['payment_fee_label'] = 'Payment Fee';

        }

        //Invoice info
        $output['invoice_no'] = $transaction->invoice_no;

        $output['shipping_address'] = ! empty($transaction->shipping_address()) ? $transaction->shipping_address() : $transaction->shipping_address;
        $output['shipping_address2'] = ! empty($transaction->shipping_address) ? $transaction->shipping_address : '';
        //Heading & invoice label, when quotation use the quotation heading.

        if ($transaction_type == 'sell_return') {
            $output['invoice_heading'] = $il->cn_heading;
            $output['invoice_no_prefix'] = $il->cn_no_label;
        } elseif ($transaction->status == 'draft' && $transaction->is_quotation == 1) {
            $output['invoice_heading'] = $il->quotation_heading;
            $output['invoice_no_prefix'] = $il->quotation_no_prefix;
        } else {
            $output['invoice_no_prefix'] = $il->invoice_no_prefix;
            $output['invoice_heading'] = $il->invoice_heading;
            if ($transaction->payment_status == 'paid' && ! empty($il->invoice_heading_paid)) {
                $output['invoice_heading'] .= ' '.$il->invoice_heading_paid;
            } elseif (in_array($transaction->payment_status, ['due', 'partial', 'setoff', 'partial-setoff']) && ! empty($il->invoice_heading_not_paid)) {
                $output['invoice_heading'] .= ' '.$il->invoice_heading_not_paid;
            }
        }

        $output['date_label'] = $il->date_label;

        //Create a  date format without time
        if ($il->show_time == 0) {
            $il->date_time_format = Carbon::parse($transaction->transaction_date)->format('d/m/Y');
        }

        if (blank($il->date_time_format)) {
            $output['invoice_date'] = $this->format_date($transaction->transaction_date, true, $business_details);
        } else {
            $output['invoice_date'] = Carbon::createFromFormat('Y-m-d H:i:s', $transaction->transaction_date)->format($il->date_time_format);
        }

        $output['hide_price'] = ! empty($il->common_settings['hide_price']) ? true : false;

        if (! empty($il->common_settings['show_due_date']) && $transaction->payment_status != 'paid') {
            $output['due_date_label'] = ! empty($il->common_settings['due_date_label']) ? $il->common_settings['due_date_label'] : '';
            $due_date = $transaction->due_date;
            if (! empty($due_date)) {
                if (blank($il->date_time_format)) {
                    $output['due_date'] = $this->format_date($due_date->toDateTimeString(), true, $business_details);
                } else {
                    $output['due_date'] = Carbon::createFromFormat('Y-m-d H:i:s', $due_date->toDateTimeString())->format($il->date_time_format);
                }
            }
        }

        $show_currency = true;
        if ($receipt_printer_type == 'printer' && trim($business_details->currency_symbol) != '$') {
            $show_currency = false;
        }

        //Invoice product lines
        $is_lot_number_enabled = $business_details->enable_lot_number;
        $is_product_expiry_enabled = $business_details->enable_product_expiry;

        $output['lines'] = [];
        $total_exempt = 0;
        if ($transaction_type == 'sell') {
            $sell_line_relations = ['modifiers', 'sub_unit', 'warranties'];

            if ($is_lot_number_enabled == 1) {
                $sell_line_relations[] = 'lot_details';
            }

            $lines = $transaction->sell_lines()->whereNull('parent_sell_line_id')->with($sell_line_relations)->get();

            foreach ($lines as $key => $value) {

                if (! empty($value->sub_unit_id)) {
                    $formated_sell_line = $this->recalculateSellLineTotals($business_details->id, $value);

                    $lines[$key] = $formated_sell_line;

                }
            }
            $details = $this->_receiptDetailsSellLines($lines, $il, $business_details);
            //format quantity
            $details = $this->quatityAmountFormat($details, $unit_d);
            $output['lines'] = $details['lines'];

            // $output['totalsavings'] =$details['totalsavings'];
            $output['taxes'] = [];

            $total_quantity = 0;
            foreach ($details['lines'] as $line) {
                if (! empty($line['group_tax_details'])) {
                    foreach ($line['group_tax_details'] as $tax_group_detail) {
                        if (! isset($output['taxes'][$tax_group_detail['name']])) {
                            $output['taxes'][$tax_group_detail['name']] = 0;
                        }
                        $output['taxes'][$tax_group_detail['name']] += $tax_group_detail['calculated_tax'];
                    }
                } elseif (! empty($line['tax_id'])) {
                    if (! isset($output['taxes'][$line['tax_name']])) {
                        $output['taxes'][$line['tax_name']] = 0;
                    }

                    $output['taxes'][$line['tax_name']] += ($line['tax_unformatted'] * $line['quantity_uf']);
                }

                if (! empty($line['tax_id']) && $line['tax_percent'] == 0) {
                    $total_exempt += $line['line_total_uf'];
                }
                if (! empty($line['line_discount'])) {
                    $total += ((floatval(preg_replace("/[^-0-9\.]/", '', $line['line_discount']))) * $line['quantity_uf']);
                }
                $total_quantity += $line['quantity_uf'];
            }

            if (! empty($il->common_settings['total_quantity_label'])) {
                $output['total_quantity_label'] = $il->common_settings['total_quantity_label'];
                $output['total_quantity'] = $this->num_f($total_quantity, false, $business_details, true);
            }
        } elseif ($transaction_type == 'sell_return') {
            $parent_sell = Transaction::find($transaction->return_parent_id);
            $lines = $parent_sell->sell_lines;

            foreach ($lines as $key => $value) {
                if (! empty($value->sub_unit_id)) {
                    $formated_sell_line = $this->recalculateSellLineTotals($business_details->id, $value);

                    $lines[$key] = $formated_sell_line;
                }
            }

            $details = $this->_receiptDetailsSellReturnLines($lines, $il, $business_details);
            $output['totalsavings'] = $details['totalsavings'];
            $details = $this->quatityAmountFormat($details, $unit_d);
            $output['lines'] = $details['lines'];
            $output['taxes'] = [];
            foreach ($details['lines'] as $line) {
                if (! empty($line['group_tax_details'])) {
                    foreach ($line['group_tax_details'] as $tax_group_detail) {
                        if (! isset($output['taxes'][$tax_group_detail['name']])) {
                            $output['taxes'][$tax_group_detail['name']] = 0;
                        }
                        $output['taxes'][$tax_group_detail['name']] += $tax_group_detail['calculated_tax'];
                    }
                }
            }
        }

        //show cat code
        $output['show_cat_code'] = $il->show_cat_code;
        $output['cat_code_label'] = $il->cat_code_label;

        //Lot Number
        $output['lot_number_label'] = $il->lot_number_label.':';
        $output['margin'] = [
            'top' => $il->margin_top * 96,
            'bottom' => $il->margin_bottom * 96,
            'left' => $il->margin_left * 96,
            'right' => $il->margin_right * 96,
        ];

        //Subtotal
        $output['subtotal_label'] = $il->sub_total_label.':';
        $output['total_savings_label'] = $il->total_savings_label.':';
        $output['margin'] = [
            'top' => $il->margin_top * 96,
            'bottom' => $il->margin_bottom * 96,
            'left' => $il->margin_left * 96,
            'right' => $il->margin_right * 96,
        ];
        //Check is woocommerce and adjust subtotal
        $subtotal_amount_with_out_line_dis = 0;
        if (is_null($transaction->woocommerce_order_id)) {
            $output['subtotal'] = ($transaction->total_before_tax != 0) ? $this->num_f(((float) $transaction->total_before_tax), $show_currency, $business_details) : 0;
            $subtotal_amount_with_out_line_dis = ($transaction->total_before_tax != 0) ? (floatval(preg_replace("/[^-0-9\.]/", '', $transaction->total_before_tax))) + (floatval(preg_replace("/[^-0-9\.]/", '', $total))) : 0;
        } else {
            $output['subtotal'] = ($transaction->total_before_tax != 0) ? $this->num_f(((float) $transaction->total_before_tax), $show_currency, $business_details) : 0;
            $subtotal_amount_with_out_line_dis = ($transaction->total_before_tax != 0) ? (floatval(preg_replace("/[^-0-9\.]/", '', $transaction->total_before_tax))) + (floatval(preg_replace("/[^-0-9\.]/", '', $total))) : 0;
        }
        if (is_null($transaction->woocommerce_order_id)) {
            $output['subtotal_unformatted'] = ($transaction->total_before_tax != 0) ? $transaction->total_before_tax : 0;
        } else {
            $output['subtotal_unformatted'] = ($transaction->total_before_tax != 0) ? $transaction->total_before_tax : 0;
        }

        $output['subtotal_amount_with_out_line_dis'] = $this->num_f($subtotal_amount_with_out_line_dis, $show_currency, $business_details);

        //round off
        $output['round_off_label'] = ! empty($il->round_off_label) ? $il->round_off_label.':' : __('lang_v1.round_off').':';
        $output['round_off'] = $this->num_f($transaction->round_off_amount, $show_currency, $business_details);
        $output['round_off_amount'] = $transaction->round_off_amount;
        $output['total_exempt'] = $this->num_f($total_exempt, $show_currency, $business_details);
        $output['total_exempt_uf'] = $total_exempt;

        $taxed_subtotal = $output['subtotal_unformatted'] - $total_exempt;
        $output['taxed_subtotal'] = $this->num_f($taxed_subtotal, $show_currency, $business_details);

        //Discount
        $discount_amount = $this->num_f($transaction->discount_amount, '', $business_details);
        $output['line_discount_label'] = $invoice_layout->discount_label;
        $output['discount_label'] = $invoice_layout->discount_label;
        $output['discount_label'] .= ($transaction->discount_type == 'percentage') ? ' <small>('.$discount_amount.'%)</small> :' : '';

        if ($transaction->discount_type == 'percentage') {
            $discount = ($transaction->discount_amount / 100) * $transaction->total_before_tax;
        } else {
            $discount = $transaction->discount_amount;
        }
        $output['discount'] = ($discount != 0) ? $this->num_f($discount, $show_currency, $business_details) : 0;
        $output['discount_without_symbol'] = ($discount != 0) ? $discount : 0;

        $promoredeem = $this->getPromoRedeem($transaction->id);
        $output['promo_redeem'] = ($promoredeem != 0) ? $this->num_f($promoredeem, $show_currency, $business_details) : 0;
        $output['promo_redeem_lable'] = 'Promo Card Redeem('.$this->getPromoRedeemID($transaction->id).')';

        $giftcardredeem = $this->getGiftCardValue($transaction->id);
        if (! empty($giftcardredeem)) {
            $output['gift_card_discount'] = ($giftcardredeem != 0) ? $this->num_f($giftcardredeem['discount'], $show_currency, $business_details) : 0;
            $output['gift_redeem_lable'] = 'Gift Voucher('.$giftcardredeem['name'].' '.$giftcardredeem['serial_no'].')';
        }

        $totalsavings = $details['totalsavings'];
        $Orderdiscount = ($discount != 0) ? $discount : 0;

        $finalamountwithsavings = $totalsavings + $Orderdiscount;

        $total_discount_with_line = $total + $discount;
        $output['total_line_doscount_with_discount'] = ($total_discount_with_line != 0) ? $this->num_f($total_discount_with_line, $show_currency, $business_details) : 0;
        // $output['totalsavings'] =$totalsavings + $Orderdiscount;
        $output['discountforsavings'] = ($discount != 0) ? $discount : 0;
        $output['total_line_discount'] = ($total != 0) ? $this->num_f($total, $show_currency, $business_details) : 0;
        $output['total_line_discount_amount'] = ($total != 0) ? $total : 0;

        $output['shipping_cost_label'] = $invoice_layout->shipping_cost_label;
        $output['shipping_cost'] = $transaction->shipping_charges;
        //reward points
        if ($business_details->enable_rp == 1 && ! empty($transaction->rp_redeemed)) {
            $reward_point_amount = $this->num_f($transaction->rp_redeemed);
            $output['reward_point_label'] = $business_details->rp_name.'('.$reward_point_amount.')';
            $reward_point_value = session()->get('business.redeem_amount_per_unit_rp');
            $reward_point_total = (floatval(preg_replace("/[^-0-9\.]/", '', $reward_point_amount))) * $reward_point_value;
            $output['reward_point_amount'] = $this->num_f($reward_point_total, $show_currency, $business_details);

        }

        //Format tax
        if (! empty($output['taxes'])) {
            foreach ($output['taxes'] as $key => $value) {
                $output['taxes'][$key] = $this->num_f($value, $show_currency, $business_details);
            }
        }

        //Order Tax
        $tax = $transaction->tax;
        $output['tax_label'] = $invoice_layout->tax_label;
        $output['line_tax_label'] = $invoice_layout->tax_label;
        if (! empty($tax) && ! empty($tax->name)) {
            $output['tax_label'] .= ' ('.$tax->name.')';
        }
        $output['tax_label'] .= ':';
        $output['tax'] = ($transaction->tax_amount != 0) ? $this->num_f($transaction->tax_amount, $show_currency, $business_details) : 0;

        if ($transaction->tax_amount != 0 && $tax->is_tax_group) {
            $transaction_group_tax_details = $this->groupTaxDetails($tax, $transaction->tax_amount);

            $output['group_tax_details'] = [];
            foreach ($transaction_group_tax_details as $value) {
                $output['group_tax_details'][$value['name']] = $this->num_f($value['calculated_tax'], $show_currency, $business_details);
            }
        }

        //Shipping charges
        $output['shipping_charges'] = ($transaction->shipping_charges != 0) ? $this->num_f($transaction->shipping_charges, $show_currency, $business_details) : 0;
        $output['shipping_charges_label'] = trans('sale.shipping_charges');
        //Shipping details
        $output['shipping_details'] = $transaction->shipping_details;
        $output['shipping_details_label'] = trans('sale.shipping_details');
        $packing_charge = 0;
        if ($transaction->packing_charge_type == 'percent') {
            $packing_charge = ($transaction->packing_charge / 100) * ($transaction->total_before_tax);
        } else {
            $packing_charge = $transaction->packing_charge;
        }
        $service_type = TypesOfService::where('business_id', $business_id)
            ->where('id', $transaction->types_of_service_id)->select('name')->first();
        $service_type_name = trans('lang_v1.packing_charge');
        if (! empty($service_type)) {
            $service_type_name = $service_type->name;
        }

        $packing_label = ! empty($il->change_packing_label) ? $il->change_packing_label : $service_type_name;
        $output['packing_charge_label'] = ($transaction->packing_charge_type == 'percent') ? $packing_label.'<small>('.$this->num_f($transaction->packing_charge, false, $business_details, true).'%)</small> :' : $packing_label;
        $output['packing_charge'] = ($transaction->packing_charge != 0) ? $this->num_f($packing_charge, $show_currency, $business_details) : 0;

        //Total
        if ($transaction_type == 'sell_return') {
            $output['total_label'] = $invoice_layout->cn_amount_label.':';
            $output['total'] = $this->num_f($transaction->final_total, $show_currency, $business_details);
            $output['totalsavings'] = $this->num_f($finalamountwithsavings, $show_currency, $business_details);
        } else {
            $output['total_label'] = $invoice_layout->total_label.':';
            $output['total'] = $this->num_f($transaction->final_total, $show_currency, $business_details);
            $output['totalsavings'] = $this->num_f($finalamountwithsavings, $show_currency, $business_details);
        }
        if (! empty($il->common_settings['show_total_in_words'])) {
            $output['total_in_words'] = $this->numToWord($transaction->final_total);
        }

        //Paid & Amount due, only if final
        if ($transaction_type == 'sell' && $transaction->status == 'final') {
            $paid_amount = $this->getTotalPaid($transaction->id);
            $due = $transaction->final_total - $paid_amount;

            $output['total_paid'] = ($paid_amount == 0) ? 0 : $this->num_f($paid_amount, $show_currency, $business_details);
            $output['total_paid_label'] = $il->paid_label.':';
            $output['total_due'] = ($due == 0) ? 0 : $this->num_f($due, $show_currency, $business_details);
            $output['total_due_label'] = $il->total_due_label;

            if ($il->show_previous_bal == 1) {
                $all_due = $this->getInvoiceContactDue($transaction->contact_id);
                $not_is_default_contact = Contact::where('id', $transaction->contact_id)->where('is_default', 0)->exists();
                if (! empty($all_due) && $not_is_default_contact) {
                    $output['all_bal_label'] = $il->prev_bal_label;
                    $output['all_due'] = $this->num_f($all_due, $show_currency, $business_details);
                }
            }

            //Get payment details
            $output['payments'] = [];
            if ($il->show_payments == 1) {
                $payments = $transaction->payment_lines->toArray();
                $payment_types = $this->payment_types($transaction->location_id, true);
                if (! empty($payments)) {
                    foreach ($payments as $value) {
                        $method = ! empty($payment_types[$value['method']]) ? $payment_types[$value['method']] : '';
                        if ($value['method'] == 'cash') {
                            if ($value['is_return'] == 1) {
                                $output['payments'][] =
                                [
                                    'method' => null.($value['is_return'] == 1 ? ' '.$il->change_return_label.' :' : ''),
                                    'value' => null,
                                    'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                    'date' => $this->format_date($value['paid_on'], false, $business_details),
                                ];
                            } else {
                                $output['payments'][] =
                                    [
                                        'method' => $method.($value['is_return'] == 1 ? ' '.$il->change_return_label.' ' : ''),
                                        'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                        'date' => $this->format_date($value['paid_on'], false, $business_details),
                                    ];
                            }

                        } elseif ($value['method'] == 'card') {
                            $output['payments'][] =
                                ['method' => $method.(! empty($value['card_transaction_number']) ? (', Transaction Number:'.$value['card_transaction_number']) : ''),
                                    'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                    'date' => $this->format_date($value['paid_on'], false, $business_details),
                                ];
                        } elseif ($value['method'] == 'cheque') {
                            $output['payments'][] =
                                ['method' => $method.(! empty($value['cheque_number']) ? (','.$value['cheque_number']).':' : ''),
                                    'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                    'date' => $this->format_date($value['paid_on'], false, $business_details),
                                ];
                        } elseif ($value['method'] == 'bank_transfer') {
                            $output['payments'][] =
                                ['method' => $method,
                                    'value' => (! empty($value['bank_account_number']) ? (''.$value['bank_account_number']).':' : ''),
                                    'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                    'date' => $this->format_date($value['paid_on'], false, $business_details),
                                ];
                        } elseif ($value['method'] == 'advance') {
                            $output['payments'][] =
                                ['method' => $method,
                                    'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                    'date' => $this->format_date($value['paid_on'], false, $business_details),
                                ];
                        } elseif ($value['method'] == 'exchange') {
                            $output['payments'][] =
                                ['method' => $method,
                                    'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                    'date' => $this->format_date($value['paid_on'], false, $business_details),
                                ];
                        } elseif ($value['method'] == 'other') {
                            $output['payments'][] =
                                ['method' => $method,
                                    'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                    'date' => $this->format_date($value['paid_on'], false, $business_details),
                                ];
                        } elseif ($value['method'] == 'custom_pay_1') {
                            $output['payments'][] =
                                ['method' => $method.(! empty($value['transaction_no']) ? (', '.trans('lang_v1.transaction_no').':'.$value['transaction_no']) : ''),
                                    'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                    'date' => $this->format_date($value['paid_on'], false, $business_details),
                                ];
                        } elseif ($value['method'] == 'custom_pay_2') {
                            $output['payments'][] =
                                ['method' => $method.(! empty($value['transaction_no']) ? (', '.trans('lang_v1.transaction_no').':'.$value['transaction_no']) : ''),
                                    'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                    'date' => $this->format_date($value['paid_on'], false, $business_details),
                                ];
                        } elseif ($value['method'] == 'custom_pay_3') {
                            $output['payments'][] =
                                ['method' => $method.(! empty($value['transaction_no']) ? (', '.trans('lang_v1.transaction_no').':'.$value['transaction_no']) : ''),
                                    'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                    'date' => $this->format_date($value['paid_on'], false, $business_details),
                                ];
                        } elseif ($value['method'] == 'custom_pay_4') {
                            $output['payments'][] =
                                ['method' => $method.(! empty($value['transaction_no']) ? (', '.trans('lang_v1.transaction_no').':'.$value['transaction_no']) : ''),
                                    'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                    'date' => $this->format_date($value['paid_on'], false, $business_details),
                                ];
                        } elseif ($value['method'] == 'custom_pay_5') {
                            $output['payments'][] =
                                ['method' => $method.(! empty($value['transaction_no']) ? (', '.trans('lang_v1.transaction_no').':'.$value['transaction_no']) : ''),
                                    'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
                                    'date' => $this->format_date($value['paid_on'], false, $business_details),
                                ];
                        }
                    }
                }
            }
        }

        //Check for barcode
        $output['barcode'] = ($il->show_barcode == 1) ? $transaction->invoice_no : false;

        //Additional notes
        $output['additional_notes'] = $transaction->additional_notes;
        $output['is_quotation'] = 0;
        if ($transaction->status == 'draft' && $transaction->is_quotation == 1) {
            $output['is_quotation'] = 1;
            if (isset($invoice_layout->footer_text_quotation)) {
                $output['footer_text'] = $invoice_layout->footer_text_quotation;

            } else {
                $output['footer_text'] = '';
            }
        } else {
            $output['footer_text'] = $invoice_layout->footer_text;

        }

        //Barcode related information.
        $output['show_barcode'] = ! empty($il->show_barcode) ? true : false;

        //Module related information.
        $il->module_info = ! empty($il->module_info) ? json_decode($il->module_info, true) : [];
        if (! empty($il->module_info['tables']) && $this->isModuleEnabled('tables')) {
            //Table label & info
            $output['table_label'] = null;
            $output['table'] = null;
            if (isset($il->module_info['tables']['show_table'])) {
                $output['table_label'] = ! empty($il->module_info['tables']['table_label']) ? $il->module_info['tables']['table_label'] : '';
                if (! empty($transaction->res_table_id)) {
                    $table = ResTable::find($transaction->res_table_id);
                }

                //res_table_id
                $output['table'] = ! empty($table->name) ? $table->name : '';
            }
        }

        if (! empty($il->module_info['types_of_service']) && $this->isModuleEnabled('types_of_service') && ! empty($transaction->types_of_service_id)) {
            //Table label & info
            $output['types_of_service_label'] = null;
            $output['types_of_service'] = null;
            if (isset($il->module_info['types_of_service']['show_types_of_service'])) {
                $output['types_of_service_label'] = ! empty($il->module_info['types_of_service']['types_of_service_label']) ? $il->module_info['types_of_service']['types_of_service_label'] : '';
                $output['types_of_service'] = $transaction->types_of_service->name;
            }

            if (isset($il->module_info['types_of_service']['show_tos_custom_fields'])) {
                $output['types_of_service_custom_fields'] = [];
                if (! empty($transaction->service_custom_field_1)) {
                    $output['types_of_service_custom_fields'][__('lang_v1.service_custom_field_1')] = $transaction->service_custom_field_1;
                }
                if (! empty($transaction->service_custom_field_2)) {
                    $output['types_of_service_custom_fields'][__('lang_v1.service_custom_field_2')] = $transaction->service_custom_field_2;
                }
                if (! empty($transaction->service_custom_field_3)) {
                    $output['types_of_service_custom_fields'][__('lang_v1.service_custom_field_3')] = $transaction->service_custom_field_3;
                }
                if (! empty($transaction->service_custom_field_4)) {
                    $output['types_of_service_custom_fields'][__('lang_v1.service_custom_field_4')] = $transaction->service_custom_field_4;
                }
            }
        }

        if (! empty($il->module_info['service_staff']) && $this->isModuleEnabled('service_staff')) {
            //Waiter label & info
            $output['service_staff_label'] = null;
            $output['service_staff'] = null;
            if (isset($il->module_info['service_staff']['show_service_staff'])) {
                $output['service_staff_label'] = ! empty($il->module_info['service_staff']['service_staff_label']) ? $il->module_info['service_staff']['service_staff_label'] : '';
                if (! empty($transaction->res_waiter_id)) {
                    $waiter = \App\User::find($transaction->res_waiter_id);
                }

                //res_table_id
                $output['service_staff'] = ! empty($waiter->id) ? implode(' ', [$waiter->first_name, $waiter->last_name]) : '';
            }
        }

        //Repair module details
        if (! empty($il->module_info['repair']) && $transaction->sub_type == 'repair') {
            if (! empty($il->module_info['repair']['show_repair_status'])) {
                $output['repair_status_label'] = $il->module_info['repair']['repair_status_label'];
                $output['repair_status'] = '';
                if (! empty($transaction->repair_status_id)) {
                    $repair_status = \Modules\Repair\Entities\RepairStatus::find($transaction->repair_status_id);
                    $output['repair_status'] = $repair_status->name;
                }
            }

            if (! empty($il->module_info['repair']['show_repair_warranty'])) {
                $output['repair_warranty_label'] = $il->module_info['repair']['repair_warranty_label'];
                $output['repair_warranty'] = '';
                if (! empty($transaction->repair_warranty_id)) {
                    $repair_warranty = \App\Warranty::find($transaction->repair_warranty_id);
                    $output['repair_warranty'] = $repair_warranty->name;
                }
            }

            if (! empty($il->module_info['repair']['show_serial_no'])) {
                $output['serial_no_label'] = $il->module_info['repair']['serial_no_label'];
                $output['repair_serial_no'] = $transaction->repair_serial_no;
            }

            if (! empty($il->module_info['repair']['show_defects'])) {
                $output['defects_label'] = $il->module_info['repair']['defects_label'];
                $output['repair_defects'] = $transaction->repair_defects;
            }

            if (! empty($il->module_info['repair']['show_model'])) {
                $output['model_no_label'] = $il->module_info['repair']['model_no_label'];

                $output['repair_model_no'] = '';

                if (! empty($transaction->repair_model_id)) {
                    $device_model = \Modules\Repair\Entities\DeviceModel::find($transaction->repair_model_id);

                    if (! empty($device_model)) {
                        $output['repair_model_no'] = $device_model->name;
                    }
                }
            }

            if (! empty($il->module_info['repair']['show_repair_checklist'])) {
                $output['repair_checklist_label'] = $il->module_info['repair']['repair_checklist_label'];
                $output['checked_repair_checklist'] = $transaction->repair_checklist;

                $checklists = [];
                if (! empty($transaction->repair_model_id)) {
                    $model = \Modules\Repair\Entities\DeviceModel::find($transaction->repair_model_id);

                    if (! empty($model) && ! empty($model->repair_checklist)) {
                        $checklists = explode('|', $model->repair_checklist);
                    }
                }

                $output['repair_checklist'] = $checklists;
            }

            if (! empty($il->module_info['repair']['show_device'])) {
                $output['device_label'] = $il->module_info['repair']['device_label'];
                $device = \App\Category::find($transaction->repair_device_id);

                $output['repair_device'] = '';
                if (! empty($device)) {
                    $output['repair_device'] = $device->name;
                }
            }

            if (! empty($il->module_info['repair']['show_brand'])) {
                $output['brand_label'] = $il->module_info['repair']['brand_label'];
                $brand = \App\Brands::find($transaction->repair_brand_id);
                $output['repair_brand'] = '';
                if (! empty($brand)) {
                    $output['repair_brand'] = $brand->name;
                }
            }
        }
        //get invoice reprint counts
        $invoice_reprint_counts = InvoiceReprintCounts::where('business_id', $business_id)
            ->where('transaction_id', $transaction->id)
            ->count();

        if (! empty($invoice_reprint_counts) && $invoice_reprint_counts > 0 && $il->show_invoice_reprint_count == 1) {
            $output['invoice_reprint_counts'] = ! empty($il->invoice_reprint_count_label) ? $il->invoice_reprint_count_label.' : '.$invoice_reprint_counts : 'DUPLICATED ['.$invoice_reprint_counts.']';
        }

        $output['design'] = $il->design;
        $output['table_tax_headings'] = ! empty($il->table_tax_headings) ? array_filter(json_decode($il->table_tax_headings), 'strlen') : null;

        return (object) $output;
    }

    public function getReceiptDetailsforwaybill($transaction_id, $location_id, $waybill_layout, $business_details, $location_details, $receipt_printer_type, $total_line_discount = null)
    {
        $wl = $waybill_layout;
        $transaction = Transaction::find($transaction_id);
        $transaction_type = $transaction->type;
        $business_id = $business_details->id;
        $unit_d = DB::select('select * from units where business_id = ?', [$business_id]);
        $output = [
            'header_text' => isset($wl->header_text) ? $wl->header_text : '',
        ];

        // //Sale Type
        // $output['sale_type'] = $this->getSaleType($transaction->sale_type_id);

        // //Display name
        // $output['display_name'] = $output['business_name'];
        // if (!empty($output['location_name'])) {
        //     if (!empty($output['display_name'])) {
        //         $output['display_name'] .= ', ';
        //     }
        //     $output['display_name'] .= $output['location_name'];
        // }

        // //Logo
        // // $output['logo'] = $il->show_logo != 0 && !empty($il->logo) && file_exists(public_path('uploads_new/invoice_logos/' . $il->logo)) ? asset('uploads_new/invoice_logos/' . $il->logo) : false;

        // //Address
        // $output['address'] = '';
        // $temp = [];
        // if ($il->show_landmark == 1) {
        //     $output['address'] .= $location_details->landmark . "\n";
        // }
        // if ($il->show_city == 1 &&  !empty($location_details->city)) {
        //     $temp[] = $location_details->city;
        // }
        // if ($il->show_state == 1 && !empty($location_details->state)) {
        //     $temp[] = $location_details->state;
        // }
        // if ($il->show_zip_code == 1 &&  !empty($location_details->zip_code)) {
        //     $temp[] = $location_details->zip_code;
        // }
        // if ($il->show_country == 1 &&  !empty($location_details->country)) {
        //     $temp[] = $location_details->country;
        // }
        // if (!empty($temp)) {
        //     $output['address'] .= implode(',', $temp);
        // }

        // $output['website'] = $location_details->website;
        // $output['location_custom_fields'] = '';
        // $temp = [];
        // $location_custom_field_settings = !empty($il->location_custom_fields) ? $il->location_custom_fields : [];
        // if (!empty($location_details->custom_field1) && in_array('custom_field1', $location_custom_field_settings)) {
        //     $temp[] = $location_details->custom_field1;
        // }
        // if (!empty($location_details->custom_field2) && in_array('custom_field2', $location_custom_field_settings)) {
        //     $temp[] = $location_details->custom_field2;
        // }
        // if (!empty($location_details->custom_field3) && in_array('custom_field3', $location_custom_field_settings)) {
        //     $temp[] = $location_details->custom_field3;
        // }
        // if (!empty($location_details->custom_field4) && in_array('custom_field4', $location_custom_field_settings)) {
        //     $temp[] = $location_details->custom_field4;
        // }
        // if (!empty($temp)) {
        //     $output['location_custom_fields'] .= implode(', ', $temp);
        // }

        // //Tax Info
        // if ($il->show_tax_1 == 1 && !empty($business_details->tax_number_1)) {
        //     $output['tax_label1'] = !empty($business_details->tax_label_1) ? $business_details->tax_label_1 . ': ' : '';

        //     $output['tax_info1'] = $business_details->tax_number_1;
        // }
        // if ($il->show_tax_2 == 1 && !empty($business_details->tax_number_2)) {
        //     if (!empty($output['tax_info1'])) {
        //         $output['tax_info1'] .= ', ';
        //     }

        //     $output['tax_label2'] = !empty($business_details->tax_label_2) ? $business_details->tax_label_2 . ': ' : '';

        //     $output['tax_info2'] = $business_details->tax_number_2;
        // }

        // //Shop Contact Info
        // $output['contact'] = '';
        // if ($il->show_mobile_number == 1 && !empty($location_details->mobile)) {
        //     $output['contact'] .= __('contact.mobile') . ': ' . $location_details->mobile;
        // }
        // if ($il->show_alternate_number == 1 && !empty($location_details->alternate_number)) {
        //     if (empty($output['contact'])) {
        //         $output['contact'] .= __('contact.mobile') . ': ' . $location_details->alternate_number;
        //     } else {
        //         $output['contact'] .= ', ' . $location_details->alternate_number;
        //     }
        // }
        // if ($il->show_email == 1 && !empty($location_details->email)) {
        //     if (!empty($output['contact'])) {
        //         $output['contact'] .= "\n";
        //     }
        //     $output['contact'] .= __('business.email') . ': ' . $location_details->email;
        // }

        //Customer show_customer
        $customer = Contact::find($transaction->contact_id);

        $output['customer_info'] = '';
        $output['customer_tax_number'] = '';
        if ($wl->show_customer == 1) {
            $output['customer_name'] = ! empty($customer->name) ? $customer->name : '';
            $output['customer_mobile'] = $customer->mobile;

            if (! empty($output['customer_name']) && $receipt_printer_type != 'printer') {
                $output['customer_info'] .= $customer->contact_address;
                if (! empty($customer->contact_address)) {
                    $output['customer_info'] .= '<br>';
                }
                $output['customer_info'] .= $customer->mobile;
                if (! empty($customer->landline)) {
                    $output['customer_info'] .= ', '.$customer->landline;
                }
            }

            $output['customer_tax_number'] = $customer->tax_number;

        }

        // if ($il->show_reward_point == 1) {
        //     $output['customer_rp_label'] = $business_details->rp_name;
        //     $output['customer_total_rp'] = $customer->total_rp;
        // }

        // $output['client_id'] = '';
        // $output['client_id_label'] = '';
        // if ($il->show_client_id == 1) {
        //     $output['client_id_label'] = !empty($il->client_id_label) ? $il->client_id_label : '';
        //     $output['client_id'] = !empty($customer->contact_id) ? $customer->contact_id : '';
        // }

        // //Sales person info
        // $output['sales_person'] = '';
        // $output['sales_person_label'] = '';
        // if ($il->show_sales_person == 1) {
        //     $output['sales_person_label'] = !empty($il->sales_person_label) ? $il->sales_person_label : '';
        //     $output['sales_person'] = !empty($transaction->sales_person->user_full_name) ? $transaction->sales_person->user_full_name : '';
        //     $output['contact_number'] = !empty($transaction->sales_person->contact_number) ? $transaction->sales_person->contact_number : '';
        // }

        // //Invoice info
        // $output['invoice_no'] = $transaction->invoice_no;

        // $output['shipping_address'] = !empty($transaction->shipping_address()) ? $transaction->shipping_address() : $transaction->shipping_address;

        // //Heading & invoice label, when quotation use the quotation heading.
        // if ($transaction_type == 'sell_return') {
        //     $output['invoice_heading'] = $il->cn_heading;
        //     $output['invoice_no_prefix'] = $il->cn_no_label;
        // } elseif ($transaction->status == 'draft' && $transaction->is_quotation == 1) {
        //     $output['invoice_heading'] = $il->quotation_heading;
        //     $output['invoice_no_prefix'] = $il->quotation_no_prefix;
        // } else {
        //     $output['invoice_no_prefix'] = $il->invoice_no_prefix;
        //     $output['invoice_heading'] = $il->invoice_heading;
        //     if ($transaction->payment_status == 'paid' && !empty($il->invoice_heading_paid)) {
        //         $output['invoice_heading'] .= ' ' . $il->invoice_heading_paid;
        //     } elseif (in_array($transaction->payment_status, ['due', 'partial', 'setoff', 'partial-setoff']) && !empty($il->invoice_heading_not_paid)) {
        //         $output['invoice_heading'] .= ' ' . $il->invoice_heading_not_paid;
        //     }
        // }

        // $output['date_label'] = $il->date_label;

        // //Create a  date format without time
        // if($il->show_time == 0){
        //     $il->date_time_format = Carbon::parse($transaction->transaction_date)->format('d/m/Y');
        // }

        // if (blank($il->date_time_format)) {
        //     $output['invoice_date'] = $this->format_date($transaction->transaction_date, true, $business_details);
        // } else {
        //     $output['invoice_date'] = Carbon::createFromFormat('Y-m-d H:i:s', $transaction->transaction_date)->format($il->date_time_format);
        // }

        // $output['hide_price'] = !empty($il->common_settings['hide_price']) ? true : false;

        // if (!empty($il->common_settings['show_due_date']) && $transaction->payment_status != 'paid') {
        //     $output['due_date_label'] = !empty($il->common_settings['due_date_label']) ? $il->common_settings['due_date_label'] : '';
        //     $due_date = $transaction->due_date;
        //     if (!empty($due_date)) {
        //         if (blank($il->date_time_format)) {
        //             $output['due_date'] = $this->format_date($due_date->toDateTimeString(), true, $business_details);
        //         } else {
        //             $output['due_date'] = Carbon::createFromFormat('Y-m-d H:i:s', $due_date->toDateTimeString())->format($il->date_time_format);
        //         }
        //     }
        // }

        // $show_currency = true;
        // if ($receipt_printer_type == 'printer' && trim($business_details->currency_symbol) != '$') {
        //     $show_currency = false;
        // }

        // //Invoice product lines
        // $is_lot_number_enabled = $business_details->enable_lot_number;
        // $is_product_expiry_enabled = $business_details->enable_product_expiry;

        // $output['lines'] = [];
        // $total_exempt = 0;
        // if ($transaction_type == 'sell') {
        //     $sell_line_relations = ['modifiers', 'sub_unit', 'warranties'];

        //     if ($is_lot_number_enabled == 1) {
        //         $sell_line_relations[] = 'lot_details';
        //     }

        //     $lines = $transaction->sell_lines()->whereNull('parent_sell_line_id')->with($sell_line_relations)->get();
        //     foreach ($lines as $key => $value) {
        //         if (!empty($value->sub_unit_id)) {
        //             $formated_sell_line = $this->recalculateSellLineTotals($business_details->id, $value);

        //             $lines[$key] = $formated_sell_line;
        //         }
        //     }

        //     $details = $this->_receiptDetailsSellLines($lines, $il, $business_details);
        //     //format quantity
        //     $details = $this->quatityAmountFormat($details, $unit_d);
        //     $output['lines'] =$details['lines'];
        //     $output['taxes'] = [];
        //     $total_quantity = 0;
        //     foreach ($details['lines'] as $line) {
        //         if (!empty($line['group_tax_details'])) {
        //             foreach ($line['group_tax_details'] as $tax_group_detail) {
        //                 if (!isset($output['taxes'][$tax_group_detail['name']])) {
        //                     $output['taxes'][$tax_group_detail['name']] = 0;
        //                 }
        //                 $output['taxes'][$tax_group_detail['name']] += $tax_group_detail['calculated_tax'];
        //             }
        //         } elseif (!empty($line['tax_id'])) {
        //             if (!isset($output['taxes'][$line['tax_name']])) {
        //                 $output['taxes'][$line['tax_name']] = 0;
        //             }

        //             $output['taxes'][$line['tax_name']] += ($line['tax_unformatted'] * $line['quantity_uf']);
        //         }

        //         if (!empty($line['tax_id']) && $line['tax_percent'] == 0) {
        //             $total_exempt += $line['line_total_uf'];
        //         }

        //         $total_quantity += $line['quantity_uf'];
        //     }

        //     if (!empty($il->common_settings['total_quantity_label'])) {
        //         $output['total_quantity_label'] = $il->common_settings['total_quantity_label'];
        //         $output['total_quantity'] = $this->num_f($total_quantity, false, $business_details, true);
        //     }
        // } elseif ($transaction_type == 'sell_return') {
        //     $parent_sell = Transaction::find($transaction->return_parent_id);
        //     $lines = $parent_sell->sell_lines;

        //     foreach ($lines as $key => $value) {
        //         if (!empty($value->sub_unit_id)) {
        //             $formated_sell_line = $this->recalculateSellLineTotals($business_details->id, $value);

        //             $lines[$key] = $formated_sell_line;
        //         }
        //     }

        //     $details = $this->_receiptDetailsSellReturnLines($lines, $il, $business_details);
        //     $details = $this->quatityAmountFormat($details, $unit_d);
        //     $output['lines'] =$details['lines'];
        //     $output['taxes'] = [];
        //     foreach ($details['lines'] as $line) {
        //         if (!empty($line['group_tax_details'])) {
        //             foreach ($line['group_tax_details'] as $tax_group_detail) {
        //                 if (!isset($output['taxes'][$tax_group_detail['name']])) {
        //                     $output['taxes'][$tax_group_detail['name']] = 0;
        //                 }
        //                 $output['taxes'][$tax_group_detail['name']] += $tax_group_detail['calculated_tax'];
        //             }
        //         }
        //     }
        // }
        // //show cat code
        // $output['show_cat_code'] = $il->show_cat_code;
        // $output['cat_code_label'] = $il->cat_code_label;

        // //Subtotal
        // $output['subtotal_label'] = $il->sub_total_label . ':';
        // //Check is woocommerce and adjust subtotal
        // if(is_null($transaction->woocommerce_order_id)){
        //     $output['subtotal'] = ($transaction->total_before_tax != 0) ? $this->num_f(($transaction->total_before_tax + $transaction->shipping_charges), $show_currency, $business_details) : 0;
        // }else{
        //     $output['subtotal'] = ($transaction->total_before_tax != 0) ? $this->num_f(($transaction->total_before_tax - $transaction->shipping_charges), $show_currency, $business_details) : 0;
        // }
        // if(is_null($transaction->woocommerce_order_id)){
        //     $output['subtotal_unformatted'] = ($transaction->total_before_tax != 0) ? $transaction->total_before_tax : 0;
        // }else{
        //     $output['subtotal_unformatted'] = ($transaction->total_before_tax != 0) ? $transaction->total_before_tax : 0;
        // }

        // //round off
        // $output['round_off_label'] = !empty($il->round_off_label) ? $il->round_off_label . ':' : __('lang_v1.round_off') . ':';
        // $output['round_off'] = $this->num_f($transaction->round_off_amount, $show_currency, $business_details);
        // $output['round_off_amount'] = $transaction->round_off_amount;
        // $output['total_exempt'] = $this->num_f($total_exempt, $show_currency, $business_details);
        // $output['total_exempt_uf'] = $total_exempt;

        // $taxed_subtotal = $output['subtotal_unformatted'] -  $total_exempt;
        // $output['taxed_subtotal'] = $this->num_f($taxed_subtotal, $show_currency, $business_details);

        // //Discount
        // $discount_amount = $this->num_f($transaction->discount_amount, $show_currency, $business_details);
        // $output['line_discount_label'] = $invoice_layout->discount_label;
        // $output['discount_label'] = $invoice_layout->discount_label;
        // $output['discount_label'] .= ($transaction->discount_type == 'percentage') ? ' <small>(' .  $discount_amount . '%)</small> :' : '';

        // if ($transaction->discount_type == 'percentage') {
        //     $discount = ($transaction->discount_amount/100) * $transaction->total_before_tax;
        // } else {
        //     $discount = $transaction->discount_amount;
        // }
        // $output['discount'] = ($discount != 0) ? $this->num_f($discount, $show_currency, $business_details) : 0;
        // $output['total_line_discount'] = ($total_line_discount != 0) ? $this->num_f($total_line_discount, $show_currency, $business_details) : 0;

        // $output['shipping_cost_label'] = $invoice_layout->shipping_cost_label;
        // $output['shipping_cost'] = $transaction->shipping_charges;
        // //reward points
        // if ($business_details->enable_rp == 1 && !empty($transaction->rp_redeemed)) {
        //     $output['reward_point_label'] = $business_details->rp_name;
        //     $output['reward_point_amount'] = $this->num_f($transaction->rp_redeemed_amount, $show_currency, $business_details);
        // }

        // //Format tax
        // if (!empty($output['taxes'])) {
        //     foreach ($output['taxes'] as $key => $value) {
        //         $output['taxes'][$key] = $this->num_f($value, $show_currency, $business_details);
        //     }
        // }

        // //Order Tax
        // $tax = $transaction->tax;
        // $output['tax_label'] = $invoice_layout->tax_label;
        // $output['line_tax_label'] = $invoice_layout->tax_label;
        // if (!empty($tax) && !empty($tax->name)) {
        //     $output['tax_label'] .= ' (' . $tax->name . ')';
        // }
        // $output['tax_label'] .= ':';
        // $output['tax'] = ($transaction->tax_amount != 0) ? $this->num_f($transaction->tax_amount, $show_currency, $business_details) : 0;

        // if ($transaction->tax_amount != 0 && $tax->is_tax_group) {
        //     $transaction_group_tax_details = $this->groupTaxDetails($tax, $transaction->tax_amount);

        //     $output['group_tax_details'] = [];
        //     foreach ($transaction_group_tax_details as $value) {
        //         $output['group_tax_details'][$value['name']] = $this->num_f($value['calculated_tax'], $show_currency, $business_details);
        //     }
        // }

        // //Shipping charges
        // $output['shipping_charges'] = ($transaction->shipping_charges != 0) ? $this->num_f($transaction->shipping_charges, $show_currency, $business_details) : 0;
        // $output['shipping_charges_label'] = trans("sale.shipping_charges");
        // //Shipping details
        // $output['shipping_details'] = $transaction->shipping_details;
        // $output['shipping_details_label'] = trans("sale.shipping_details");
        // $output['packing_charge_label'] = trans("lang_v1.packing_charge");
        // $output['packing_charge'] = ($transaction->packing_charge != 0) ? $this->num_f($transaction->packing_charge, $show_currency, $business_details) : 0;

        // //Total
        // if ($transaction_type == 'sell_return') {
        //     $output['total_label'] = $invoice_layout->cn_amount_label . ':';
        //     $output['total'] = $this->num_f($transaction->final_total, $show_currency, $business_details);
        // } else {
        //     $output['total_label'] = $invoice_layout->total_label . ':';
        //     $output['total'] = $this->num_f($transaction->final_total, $show_currency, $business_details);
        // }
        // if (!empty($il->common_settings['show_total_in_words'])) {
        //     $output['total_in_words'] = $this->numToWord($transaction->final_total);
        // }

        // //Paid & Amount due, only if final
        // if ($transaction_type == 'sell' && $transaction->status == 'final') {
        //     $paid_amount = $this->getTotalPaid($transaction->id);
        //     $due = $transaction->final_total - $paid_amount;

        //     $output['total_paid'] = ($paid_amount == 0) ? 0 : $this->num_f($paid_amount, $show_currency, $business_details);
        //     $output['total_paid_label'] = $il->paid_label;
        //     $output['total_due'] = ($due == 0) ? 0 : $this->num_f($due, $show_currency, $business_details);
        //     $output['total_due_label'] = $il->total_due_label;

        //     if ($il->show_previous_bal == 1) {
        //         $all_due = $this->getContactDue($transaction->contact_id);
        //         if (!empty($all_due)) {
        //             $output['all_bal_label'] = $il->prev_bal_label;
        //             $output['all_due'] = $this->num_f($all_due, $show_currency, $business_details);
        //         }
        //     }

        //     //Get payment details
        //     $output['payments'] = [];
        //     if ($il->show_payments == 1) {
        //         $payments = $transaction->payment_lines->toArray();
        //         $payment_types = $this->payment_types($transaction->location_id, true);
        //         if (!empty($payments)) {
        //             foreach ($payments as $value) {
        //                 $method = !empty($payment_types[$value['method']]) ? $payment_types[$value['method']] : '';
        //                 if ($value['method'] == 'cash') {
        //                     $output['payments'][] =
        //                         ['method' => $method . ($value['is_return'] == 1 ? ' (' . $il->change_return_label . ')(-)' : ''),
        //                             'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
        //                             'date' => $this->format_date($value['paid_on'], false, $business_details)
        //                         ];
        //                     if ($value['is_return'] == 1) {
        //                     }
        //                 } elseif ($value['method'] == 'card') {
        //                     $output['payments'][] =
        //                         ['method' => $method . (!empty($value['card_transaction_number']) ? (', Transaction Number:' . $value['card_transaction_number']) : ''),
        //                             'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
        //                             'date' => $this->format_date($value['paid_on'], false, $business_details)
        //                         ];
        //                 } elseif ($value['method'] == 'cheque') {
        //                     $output['payments'][] =
        //                         ['method' => $method . (!empty($value['cheque_number']) ? (', Chq.No:' . $value['cheque_number']) : ''),
        //                             'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
        //                             'date' => $this->format_date($value['paid_on'], false, $business_details)
        //                         ];
        //                 } elseif ($value['method'] == 'bank_transfer') {
        //                     $output['payments'][] =
        //                         ['method' => $method . (!empty($value['bank_account_number']) ? (', Account Number:' . $value['bank_account_number']) : ''),
        //                             'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
        //                             'date' => $this->format_date($value['paid_on'], false, $business_details)
        //                         ];
        //                 } elseif ($value['method'] == 'advance') {
        //                     $output['payments'][] =
        //                         ['method' => $method,
        //                             'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
        //                             'date' => $this->format_date($value['paid_on'], false, $business_details)
        //                         ];
        //                 } elseif ($value['method'] == 'other') {
        //                     $output['payments'][] =
        //                         ['method' => $method,
        //                             'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
        //                             'date' => $this->format_date($value['paid_on'], false, $business_details)
        //                         ];
        //                 } elseif ($value['method'] == 'custom_pay_1') {
        //                     $output['payments'][] =
        //                         ['method' => $method . (!empty($value['transaction_no']) ? (', ' . trans("lang_v1.transaction_no") . ':' . $value['transaction_no']) : ''),
        //                             'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
        //                             'date' => $this->format_date($value['paid_on'], false, $business_details)
        //                         ];
        //                 } elseif ($value['method'] == 'custom_pay_2') {
        //                     $output['payments'][] =
        //                         ['method' => $method . (!empty($value['transaction_no']) ? (', ' . trans("lang_v1.transaction_no") . ':' . $value['transaction_no']) : ''),
        //                             'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
        //                             'date' => $this->format_date($value['paid_on'], false, $business_details)
        //                         ];
        //                 } elseif ($value['method'] == 'custom_pay_3') {
        //                     $output['payments'][] =
        //                         ['method' => $method . (!empty($value['transaction_no']) ? (', ' . trans("lang_v1.transaction_no") . ':' . $value['transaction_no']) : ''),
        //                             'amount' => $this->num_f($value['amount'], $show_currency, $business_details),
        //                             'date' => $this->format_date($value['paid_on'], false, $business_details)
        //                         ];
        //                 }
        //             }
        //         }
        //     }
        // }

        //Check for barcode
        $output['barcode'] = ($wl->show_barcode == 1) ? $transaction->invoice_no : false;

        // //Additional notes
        // $output['additional_notes'] = $transaction->additional_notes;
        // $output['footer_text'] = $invoice_layout->footer_text;

        //Barcode related information.
        $output['show_barcode'] = ! empty($wl->show_barcode) ? true : false;

        // //Module related information.
        // $il->module_info = !empty($il->module_info) ? json_decode($il->module_info, true) : [];
        // if (!empty($il->module_info['tables']) && $this->isModuleEnabled('tables')) {
        //     //Table label & info
        //     $output['table_label'] = null;
        //     $output['table'] = null;
        //     if (isset($il->module_info['tables']['show_table'])) {
        //         $output['table_label'] = !empty($il->module_info['tables']['table_label']) ? $il->module_info['tables']['table_label'] : '';
        //         if (!empty($transaction->res_table_id)) {
        //             $table = ResTable::find($transaction->res_table_id);
        //         }

        //         //res_table_id
        //         $output['table'] = !empty($table->name) ? $table->name : '';
        //     }
        // }

        // if (!empty($il->module_info['types_of_service']) && $this->isModuleEnabled('types_of_service') && !empty($transaction->types_of_service_id)) {
        //     //Table label & info
        //     $output['types_of_service_label'] = null;
        //     $output['types_of_service'] = null;
        //     if (isset($il->module_info['types_of_service']['show_types_of_service'])) {
        //         $output['types_of_service_label'] = !empty($il->module_info['types_of_service']['types_of_service_label']) ? $il->module_info['types_of_service']['types_of_service_label'] : '';
        //         $output['types_of_service'] = $transaction->types_of_service->name;
        //     }

        //     if (isset($il->module_info['types_of_service']['show_tos_custom_fields'])) {
        //         $output['types_of_service_custom_fields'] = [];
        //         if (!empty($transaction->service_custom_field_1)) {
        //             $output['types_of_service_custom_fields'][__('lang_v1.service_custom_field_1')] = $transaction->service_custom_field_1;
        //         }
        //         if (!empty($transaction->service_custom_field_2)) {
        //             $output['types_of_service_custom_fields'][__('lang_v1.service_custom_field_2')] = $transaction->service_custom_field_2;
        //         }
        //         if (!empty($transaction->service_custom_field_3)) {
        //             $output['types_of_service_custom_fields'][__('lang_v1.service_custom_field_3')] = $transaction->service_custom_field_3;
        //         }
        //         if (!empty($transaction->service_custom_field_4)) {
        //             $output['types_of_service_custom_fields'][__('lang_v1.service_custom_field_4')] = $transaction->service_custom_field_4;
        //         }
        //     }
        // }

        // if (!empty($il->module_info['service_staff']) && $this->isModuleEnabled('service_staff')) {
        //     //Waiter label & info
        //     $output['service_staff_label'] = null;
        //     $output['service_staff'] = null;
        //     if (isset($il->module_info['service_staff']['show_service_staff'])) {
        //         $output['service_staff_label'] = !empty($il->module_info['service_staff']['service_staff_label']) ? $il->module_info['service_staff']['service_staff_label'] : '';
        //         if (!empty($transaction->res_waiter_id)) {
        //             $waiter = \App\User::find($transaction->res_waiter_id);
        //         }

        //         //res_table_id
        //         $output['service_staff'] = !empty($waiter->id) ? implode(' ', [$waiter->first_name, $waiter->last_name]) : '';
        //     }
        // }

        // //Repair module details
        // if (!empty($il->module_info['repair']) && $transaction->sub_type == 'repair') {
        //     if (!empty($il->module_info['repair']['show_repair_status'])) {
        //         $output['repair_status_label'] = $il->module_info['repair']['repair_status_label'];
        //         $output['repair_status'] = '';
        //         if (!empty($transaction->repair_status_id)) {
        //             $repair_status = \Modules\Repair\Entities\RepairStatus::find($transaction->repair_status_id);
        //             $output['repair_status'] = $repair_status->name;
        //         }
        //     }

        //     if (!empty($il->module_info['repair']['show_repair_warranty'])) {
        //         $output['repair_warranty_label'] = $il->module_info['repair']['repair_warranty_label'];
        //         $output['repair_warranty'] = '';
        //         if (!empty($transaction->repair_warranty_id)) {
        //             $repair_warranty = \App\Warranty::find($transaction->repair_warranty_id);
        //             $output['repair_warranty'] = $repair_warranty->name;
        //         }
        //     }

        //     if (!empty($il->module_info['repair']['show_serial_no'])) {
        //         $output['serial_no_label'] = $il->module_info['repair']['serial_no_label'];
        //         $output['repair_serial_no'] = $transaction->repair_serial_no;
        //     }

        //     if (!empty($il->module_info['repair']['show_defects'])) {
        //         $output['defects_label'] = $il->module_info['repair']['defects_label'];
        //         $output['repair_defects'] = $transaction->repair_defects;
        //     }

        //     if (!empty($il->module_info['repair']['show_model'])) {
        //         $output['model_no_label'] = $il->module_info['repair']['model_no_label'];

        //         $output['repair_model_no'] = '';

        //         if (!empty($transaction->repair_model_id)) {
        //             $device_model = \Modules\Repair\Entities\DeviceModel::find($transaction->repair_model_id);

        //             if (!empty($device_model)) {
        //                 $output['repair_model_no'] = $device_model->name;
        //             }
        //         }
        //     }

        //     if (!empty($il->module_info['repair']['show_repair_checklist'])) {
        //         $output['repair_checklist_label'] = $il->module_info['repair']['repair_checklist_label'];
        //         $output['checked_repair_checklist'] = $transaction->repair_checklist;

        //         $checklists = [];
        //         if (!empty($transaction->repair_model_id)) {
        //             $model = \Modules\Repair\Entities\DeviceModel::find($transaction->repair_model_id);

        //             if (!empty($model) && !empty($model->repair_checklist)) {
        //                 $checklists = explode('|', $model->repair_checklist);
        //             }
        //         }

        //         $output['repair_checklist'] = $checklists;
        //     }

        //     if (!empty($il->module_info['repair']['show_device'])) {
        //         $output['device_label'] = $il->module_info['repair']['device_label'];
        //         $device = \App\Category::find($transaction->repair_device_id);

        //         $output['repair_device'] = '';
        //         if (!empty($device)) {
        //             $output['repair_device'] = $device->name;
        //         }
        //     }

        //     if (!empty($il->module_info['repair']['show_brand'])) {
        //         $output['brand_label'] = $il->module_info['repair']['brand_label'];
        //         $brand = \App\Brands::find($transaction->repair_brand_id);
        //         $output['repair_brand'] = '';
        //         if (!empty($brand)) {
        //             $output['repair_brand'] = $brand->name;
        //         }
        //     }
        // }

        // $output['design'] = $il->design;
        // $output['table_tax_headings'] = !empty($il->table_tax_headings) ? array_filter(json_decode($il->table_tax_headings), 'strlen') : null;
        return (object) $output;
    }

    /**
     * Returns each line details for sell invoice display
     *
     * @return array
     */
    protected function _receiptDetailsSellLines($lines, $il, $business_details)
    {

        $is_lot_number_enabled = $business_details->enable_lot_number;
        $is_product_expiry_enabled = $business_details->enable_product_expiry;

        $output_lines = [];
        //$output_taxes = ['taxes' => []];
        $product_custom_fields_settings = ! empty($il->product_custom_fields) ? $il->product_custom_fields : [];

        $is_warranty_enabled = ! empty($business_details->common_settings['enable_product_warranty']) ? true : false;

        $price_groups = SellingPriceGroup::where('business_id', $business_details->id)
            ->where('is_slim4', '1')
            ->first();

        $totalsavings = 0;
        $totalunitprice = 0;
        $totalmarketprice = 0;
        $totaldiscount = 0;

        $sum_of_unit_price_before_discount = 0;
        $sum_of_unit_price = 0;

        foreach ($lines as $line) {
            $product = $line->product;
            $variation = $line->variations;
            $product_variation = $line->variations->product_variation;
            $unit = $line->product->unit;
            $brand = $line->product->brand;
            $cat = $line->product->category;
            $tax_details = TaxRate::find($line->tax_id);
            $market_price = $line->market_price;

            $unit_name = ! empty($unit->short_name) ? $unit->short_name : '';

            if (! empty($line->sub_unit->short_name)) {
                $unit_name = $line->sub_unit->short_name;
            }

            //REFER recalculateSellLineTotals() method to understand logic when products with Sub Units come to place
            //Stores Sub Unit Multiplier if exists
            $sub_unit_multiplier = 1;
            if (empty($line->sub_unit->base_unit_multiplier)) {
                $sub_unit_multiplier = 1;
            } else {
                $sub_unit_multiplier = $line->sub_unit->base_unit_multiplier;
            }

            $totalunitprice = $totalunitprice + ($line->unit_price_before_discount * $line->quantity);
            $totalmarketprice = $totalmarketprice + ($market_price * $line->quantity);

            //Stores the sum of Unit Price without discount
            $sum_of_unit_price_before_discount += ($line->unit_price_before_discount * $line->quantity);

            //Checks the sub unit multiplier is greater than 1 and stores the sum of unit prices which are discounts are applied
            if ($sub_unit_multiplier > 1) {
                $sum_of_unit_price += (($line->unit_price / $sub_unit_multiplier) * $line->quantity);
            } else {
                $sum_of_unit_price += ($line->unit_price * $line->quantity);
            }

            $variation_name = $product_variation->name;
            if (isset($il->common_settings['hide_variation_name'])) {
                $variation_name = ' ';
            }
            $line_array = [
                //Field for 1st column
                'name' => $product->name,
                'Market_Price' => $market_price,
                'variation' => (empty($variation->name) || $variation->name == 'DUMMY') ? '' : $variation->name,
                'product_variation' => (empty($product_variation->name) || $product_variation->name == 'DUMMY') ? '' : $variation_name,
                //Field for 2nd column
                'quantity' => $this->num_f($line->quantity, false, $business_details, true),
                'quantity_uf' => $line->quantity,
                'units' => $unit_name,

                'unit_price' => $this->num_f($line->unit_price, false, $business_details),
                'tax' => $this->num_f($line->item_tax, false, $business_details),
                'tax_id' => $line->tax_id,
                'tax_unformatted' => $line->item_tax,
                'tax_name' => ! empty($tax_details) ? $tax_details->name : null,
                'tax_percent' => ! empty($tax_details) ? $tax_details->amount : null,

                //Field for 3rd column
                'unit_price_inc_tax' => $this->num_f($line->unit_price_inc_tax, false, $business_details),
                'unit_price_exc_tax' => $this->num_f($line->unit_price, false, $business_details),
                'price_exc_tax' => $line->quantity * $line->unit_price,
                'unit_price_before_discount' => $this->num_f($line->unit_price_before_discount, false, $business_details),

                //Fields for 4th column
                'line_total' => $this->num_f($line->unit_price_inc_tax * $line->quantity, false, $business_details),
                'line_total_uf' => $line->unit_price_inc_tax * $line->quantity,
                'prescription_qty' => $line->prescription_qty,
                'prescription_dose' => $line->prescription_dose,
                'prescription_days' => $line->prescription_days,
            ];

            $line_array['total_line_unit_price_before_discount'] = $line->unit_price_before_discount * $line->quantity;

            $temp = [];

            if (! empty($product->product_custom_field1) && in_array('product_custom_field1', $product_custom_fields_settings)) {
                $temp[] = $product->product_custom_field1;
            }
            if (! empty($product->product_custom_field2) && in_array('product_custom_field2', $product_custom_fields_settings)) {
                $temp[] = $product->product_custom_field2;
            }
            if (! empty($product->product_custom_field3) && in_array('product_custom_field3', $product_custom_fields_settings)) {
                $temp[] = $product->product_custom_field3;
            }
            if (! empty($product->product_custom_field4) && in_array('product_custom_field4', $product_custom_fields_settings)) {
                $temp[] = $product->product_custom_field4;
            }
            if (! empty($temp)) {
                $line_array['product_custom_fields'] = implode(',', $temp);
            }

            if (! empty($product['product_description']) && $il->show_product_description == 1) {
                $line_array['product_description_as_added'] = $product['product_description'];
            }

            //Group product taxes by name.
            if (! empty($tax_details)) {
                if ($tax_details->is_tax_group) {
                    $group_tax_details = $this->groupTaxDetails($tax_details, $line->quantity * $line->item_tax);

                    $line_array['group_tax_details'] = $group_tax_details;

                    // foreach ($group_tax_details as $key => $value) {
                    //     if (!isset($output_taxes['taxes'][$key])) {
                    //         $output_taxes['taxes'][$key] = 0;
                    //     }
                    //     $output_taxes['taxes'][$key] += $value;
                    // }
                }
                // else {
                //     $tax_name = $tax_details->name;
                //     if (!isset($output_taxes['taxes'][$tax_name])) {
                //         $output_taxes['taxes'][$tax_name] = 0;
                //     }
                //     $output_taxes['taxes'][$tax_name] += ($line->quantity * $line->item_tax);
                // }
            }

            $line_array['line_discount'] = method_exists($line, 'get_discount_amount') ? $this->num_f($line->get_discount_amount(), false, $business_details) : 0;

            $line_array['line_discount_without_symbols'] = str_replace(',', '', $line_array['line_discount']);

            $line_array['discount_type'] = $line->line_discount_type;

            if ($line->line_discount_type == 'percentage') {
                $line_array['line_discount'] .= ' ('.$this->num_f($line->line_discount_amount, false, $business_details).'%)';
            }

            if ($il->show_brand == 1) {
                $line_array['brand'] = ! empty($brand->name) ? $brand->name : '';
            }
            if ($il->show_sku == 1) {
                $line_array['sub_sku'] = ! empty($variation->sub_sku) ? $variation->sub_sku : '';
            }
            if ($il->show_image == 1) {
                $media = $variation->media;
                if (count($media)) {
                    $first_img = $media->first();
                    $line_array['image'] = ! empty($first_img->display_url) ? $first_img->display_url : asset('/img/default.png');
                } else {
                    $line_array['image'] = $product->image_url;
                }
            }
            if ($il->show_cat_code == 1) {
                $line_array['cat_code'] = ! empty($cat->short_code) ? $cat->short_code : '';
            }
            if ($il->show_sale_description == 1) {
                $line_array['sell_line_note'] = ! empty($line->sell_line_note) ? $line->sell_line_note : '';
            }
            if ($is_lot_number_enabled == 1 && $il->show_lot == 1) {
                $line_array['lot_number'] = ! empty($line->lot_details->lot_number) ? $line->lot_details->lot_number : null;
                $line_array['lot_number_label'] = ! empty($il->lot_number_label) ? $il->lot_number_label : __('lang_v1.lot_number_label');
            }

            if ($is_product_expiry_enabled == 1 && $il->show_expiry == 1) {
                $line_array['product_expiry'] = ! empty($line->lot_details->exp_date) ? $this->format_date($line->lot_details->exp_date, false, $business_details) : null;
                $line_array['product_expiry_label'] = __('lang_v1.expiry');
            }

            //Set warranty data if enabled
            if ($is_warranty_enabled && ! empty($line->warranties->first())) {
                $warranty = $line->warranties->first();
                if (! empty($il->common_settings['show_warranty_name'])) {
                    $line_array['warranty_name'] = $warranty->name;
                }
                if (! empty($il->common_settings['show_warranty_description'])) {
                    $line_array['warranty_description'] = $warranty->description;
                }
                if (! empty($il->common_settings['show_warranty_exp_date']) && $warranty['is_life_time_warranty'] == 0) {
                    $line_array['warranty_exp_date'] = $warranty->getEndDate($line->transaction->transaction_date);
                }
            }

            //If modifier is set set modifiers line to parent sell line
            if (! empty($line->modifiers)) {
                foreach ($line->modifiers as $modifier_line) {
                    $product = $modifier_line->product;
                    $variation = $modifier_line->variations;
                    $unit = $modifier_line->product->unit;
                    $brand = $modifier_line->product->brand;
                    $cat = $modifier_line->product->category;

                    $modifier_line_array = [
                        //Field for 1st column
                        'name' => $product->name,
                        'name' => $product->name,
                        'variation' => (empty($variation->name) || $variation->name == 'DUMMY') ? '' : $variation->name,
                        //Field for 2nd column
                        'quantity' => $this->num_f($modifier_line->quantity, false, $business_details),
                        'units' => ! empty($unit->short_name) ? $unit->short_name : '',

                        //Field for 3rd column
                        'unit_price_inc_tax' => $this->num_f($modifier_line->unit_price_inc_tax, false, $business_details),
                        'unit_price_exc_tax' => $this->num_f($modifier_line->unit_price, false, $business_details),
                        'price_exc_tax' => $modifier_line->quantity * $modifier_line->unit_price,

                        //Fields for 4th column
                        'line_total' => $this->num_f($modifier_line->unit_price_inc_tax * $line->quantity, false, $business_details),
                    ];

                    if ($il->show_sku == 1) {
                        $modifier_line_array['sub_sku'] = ! empty($variation->sub_sku) ? $variation->sub_sku : '';
                    }
                    if ($il->show_cat_code == 1) {
                        $modifier_line_array['cat_code'] = ! empty($cat->short_code) ? $cat->short_code : '';
                    }
                    if ($il->show_sale_description == 1) {
                        $modifier_line_array['sell_line_note'] = ! empty($line->sell_line_note) ? $line->sell_line_note : '';
                    }

                    $line_array['modifiers'][] = $modifier_line_array;
                }
            }

            $output_lines[] = $line_array;
        }
        $totaldiscount = $sum_of_unit_price - $sum_of_unit_price_before_discount;
        $totalsavings = ($totalmarketprice - $totalunitprice) - $totaldiscount;
        $this->num_f($totalsavings, true, $business_details);

        return ['lines' => $output_lines, 'totalsavings' => $totalsavings];
    }

    /**
     * Returns each line details for sell return invoice display
     *
     * @return array
     */
    protected function _receiptDetailsSellReturnLines($lines, $il, $business_details)
    {
        $is_lot_number_enabled = $business_details->enable_lot_number;
        $is_product_expiry_enabled = $business_details->enable_product_expiry;

        $output_lines = [];
        $output_taxes = ['taxes' => []];

        $price_groups = SellingPriceGroup::where('business_id', $business_details->id)
            ->where('is_slim4', '1')
            ->first();

        $totalsavings = 0;
        $totalunitprice = 0;
        $totalmarketprice = 0;
        $totaldiscount = 0;

        $sum_of_unit_price_before_discount = 0;
        $sum_of_unit_price = 0;

        foreach ($lines as $line) {
            //Group product taxes by name.
            $tax_details = TaxRate::find($line->tax_id);
            // if (!empty($tax_details)) {
            //     if ($tax_details->is_tax_group) {
            //         $group_tax_details = $this->groupTaxDetails($tax_details, $line->quantity_returned * $line->item_tax);
            //         foreach ($group_tax_details as $key => $value) {
            //             if (!isset($output_taxes['taxes'][$key])) {
            //                 $output_taxes['taxes'][$key] = 0;
            //             }
            //             $output_taxes['taxes'][$key] += $value;
            //         }
            //     } else {
            //         $tax_name = $tax_details->name;
            //         if (!isset($output_taxes['taxes'][$tax_name])) {
            //             $output_taxes['taxes'][$tax_name] = 0;
            //         }
            //         $output_taxes['taxes'][$tax_name] += ($line->quantity_returned * $line->item_tax);
            //     }
            // }

            $product = $line->product;
            $variation = $line->variations;
            $unit = $line->product->unit;
            $brand = $line->product->brand;
            $cat = $line->product->category;
            $market_price = $line->market_price;

            $unit_name = ! empty($unit->short_name) ? $unit->short_name : '';
            if (! empty($line->sub_unit->short_name)) {
                $unit_name = $line->sub_unit->short_name;
            }

            $totalunitprice = $totalunitprice + ($line->unit_price_before_discount * $line->quantity);

            $totalmarketprice = $totalmarketprice + ($market_price * $line->quantity);
            //Stores the sum of Unit Price without discount
            $sum_of_unit_price_before_discount += ($line->unit_price_before_discount * $line->quantity);

            $line_array = [
                //Field for 1st column
                'name' => $product->name,
                'variation' => (empty($variation->name) || $variation->name == 'DUMMY') ? '' : $variation->name,
                'product_variation' => (empty($product_variation->name) || $product_variation->name == 'DUMMY') ? '' : $product_variation->name,
                'Market_Price' => $market_price,
                //Field for 2nd column
                'quantity' => $this->num_f($line->quantity_returned, false, $business_details, true),
                'units' => $unit_name,

                'unit_price' => $this->num_f($line->unit_price, false, $business_details),
                'tax' => $this->num_f($line->item_tax, false, $business_details),
                'tax_name' => ! empty($tax_details) ? $tax_details->name : null,
                'unit_price_before_discount' => $this->num_f($line->unit_price_before_discount, false, $business_details),

                //Field for 3rd column
                'unit_price_inc_tax' => $this->num_f($line->unit_price_inc_tax, false, $business_details),
                'unit_price_exc_tax' => $this->num_f($line->unit_price, false, $business_details),
                'price_exc_tax' => $line->quantity * $line->unit_price,

                //Fields for 4th column
                'line_total' => $this->num_f($line->unit_price_inc_tax * $line->quantity_returned, false, $business_details),
            ];
            $line_array['line_discount'] = 0;

            //Group product taxes by name.
            if (! empty($tax_details)) {
                if ($tax_details->is_tax_group) {
                    $group_tax_details = $this->groupTaxDetails($tax_details, $line->quantity * $line->item_tax);

                    $line_array['group_tax_details'] = $group_tax_details;

                    // foreach ($group_tax_details as $key => $value) {
                    //     if (!isset($output_taxes['taxes'][$key])) {
                    //         $output_taxes['taxes'][$key] = 0;
                    //     }
                    //     $output_taxes['taxes'][$key] += $value;
                    // }
                }
                // else {
                //     $tax_name = $tax_details->name;
                //     if (!isset($output_taxes['taxes'][$tax_name])) {
                //         $output_taxes['taxes'][$tax_name] = 0;
                //     }
                //     $output_taxes['taxes'][$tax_name] += ($line->quantity * $line->item_tax);
                // }
            }

            if ($il->show_brand == 1) {
                $line_array['brand'] = ! empty($brand->name) ? $brand->name : '';
            }
            if ($il->show_sku == 1) {
                $line_array['sub_sku'] = ! empty($variation->sub_sku) ? $variation->sub_sku : '';
            }
            if ($il->show_cat_code == 1) {
                $line_array['cat_code'] = ! empty($cat->short_code) ? $cat->short_code : '';
            }
            if ($il->show_sale_description == 1) {
                $line_array['sell_line_note'] = ! empty($line->sell_line_note) ? $line->sell_line_note : '';
            }
            //updated
            if ($is_lot_number_enabled == 1 && $il->show_lot == 1) {
                $line_array['lot_number'] = ! empty($line->lot_details->lot_number) ? $line->lot_details->lot_number : null;
                $line_array['lot_number_label'] = __('lang_v1.lot_number_label');
            }

            // if ($is_product_expiry_enabled == 1 && $il->show_expiry == 1) {
            //     $line_array['product_expiry'] = !empty($line->lot_details->exp_date) ? $this->format_date($line->lot_details->exp_date) : null;
            //     $line_array['product_expiry_label'] = __('lang_v1.expiry');
            // }

            $output_lines[] = $line_array;
        }

        $totalsavings = ($totalmarketprice - $totalunitprice);

        return ['lines' => $output_lines, 'taxes' => $output_taxes, 'totalsavings' => $totalsavings];
    }

    /**
     * Gives the invoice number for a Final/Draft invoice
     *
     * @param  int  $business_id
     * @param  string  $status
     * @param  string  $location_id
     * @return string
     */
    public function getInvoiceNumber($business_id, $status, $location_id, $invoice_scheme_id = null)
    {
        if ($status == 'final') {
            if (empty($invoice_scheme_id)) {
                $scheme = $this->getInvoiceScheme($business_id, $location_id);
            } else {
                $scheme = InvoiceScheme::where('business_id', $business_id)
                    ->find($invoice_scheme_id);
            }

            if ($scheme->scheme_type == 'blank') {
                $prefix = $scheme->prefix;
            } else {
                $prefix = date('Y').'-';
            }

            //Count
            $count = $scheme->start_number + $scheme->invoice_count;
            $count = str_pad($count, $scheme->total_digits, '0', STR_PAD_LEFT);

            //Prefix + count
            $invoice_no = $prefix.$count;

            //Increment the invoice count
            $scheme->invoice_count = $scheme->invoice_count + 1;
            $scheme->save();

            return $invoice_no;
        } elseif ($status == 'draft') {
            $ref_count = $this->setAndGetReferenceCount('draft', $business_id);
            $invoice_no = $this->generateReferenceNumber('draft', $ref_count, $business_id);

            return $invoice_no;
        } else {
            return Str::random(5);
        }
    }

    public function getKotNumber($business_id, $location_id, $invoice_scheme_id = null)
    {
        if (empty($invoice_scheme_id)) {
            $scheme = $this->getInvoiceScheme($business_id, $location_id);
        } else {
            $scheme = InvoiceScheme::where('business_id', $business_id)
                ->find($invoice_scheme_id);
        }
        if ($scheme->scheme_type == 'blank') {
            $prefix = $scheme->prefix;
        } else {
            $prefix = date('Y').'-';
        }
        //Count
        $count = $scheme->start_number + ($scheme->invoice_count - 1);
        $count = str_pad($count, $scheme->total_digits, '0', STR_PAD_LEFT);
        //Prefix + count
        $invoice_no = $prefix.$count;

        return $invoice_no;
    }

    public function getKotRefNumber($business_id, $location_id, $invoice_scheme_id = null)
    {
        if (empty($invoice_scheme_id)) {
            $scheme = $this->getInvoiceScheme($business_id, $location_id);
        } else {
            $scheme = InvoiceScheme::where('business_id', $business_id)
                ->find($invoice_scheme_id);
        }
        if ($scheme->scheme_type == 'blank') {
            $prefix = $scheme->prefix;
        } else {
            $prefix = date('Y').'-';
        }
        //Count
        $count = $scheme->start_number + $scheme->invoice_count;
        $count = str_pad($count, $scheme->total_digits, '0', STR_PAD_LEFT);
        //Prefix + count
        $invoice_no = $prefix.$count;

        return $invoice_no;
    }

    private function getInvoiceScheme($business_id, $location_id)
    {
        $scheme_id = BusinessLocation::where('business_id', $business_id)
            ->where('id', $location_id)
            ->first()
            ->invoice_scheme_id;
        if (! empty($scheme_id) && $scheme_id != 0) {
            $scheme = InvoiceScheme::find($scheme_id);
        }

        //Check if scheme is not found then return default scheme
        if (empty($scheme)) {
            $scheme = InvoiceScheme::where('business_id', $business_id)
                ->where('is_default', 1)
                ->first();
        }

        return $scheme;
    }

    /**
     * Gives the list of products for a purchase transaction
     *
     * @param  int  $business_id
     * @param  int  $transaction_id
     * @return array
     */
    public function getPurchaseProducts($business_id, $transaction_id)
    {
        $products = Transaction::join('purchase_lines as pl', 'transactions.id', '=', 'pl.transaction_id')
            ->leftjoin('products as p', 'pl.product_id', '=', 'p.id')
            ->leftjoin('variations as v', 'pl.variation_id', '=', 'v.id')
            ->where('transactions.business_id', $business_id)
            ->where('transactions.id', $transaction_id)
            ->where('transactions.type', 'purchase')
            ->select('p.id as product_id', 'p.name as product_name', 'v.id as variation_id', 'v.name as variation_name', 'pl.quantity as quantity')
            ->orderBy('product_id', 'ASC')
            ->get();

        return $products;
    }

    /**
     * Gives the total purchase amount for a business within the date range passed
     *
     * @param  int  $business_id
     * @param  int  $transaction_id
     * @return array
     */
    public function getPurchaseTotals($business_id, $start_date = null, $end_date = null, $location_id = null, $user_id = null)
    {
        $query = Transaction::where('business_id', $business_id)
            ->where('type', 'purchase')
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) {
                $query->where('is_exchange', '!=', 1)
                    ->where('payment_status', '!=', 'setoff');
            })
            ->select(
                'final_total',
                DB::raw('(final_total - tax_amount) as total_exc_tax'),
                DB::raw('SUM((SELECT SUM(tp.amount) FROM transaction_payments as tp WHERE tp.transaction_id=transactions.id)) as total_paid'),
                DB::raw('SUM(total_before_tax) as total_before_tax'),
                'shipping_charges'
            )
            ->groupBy('transactions.id');

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (! empty($start_date) && ! empty($end_date)) {
            $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        if (empty($start_date) && ! empty($end_date)) {
            $query->whereDate('transaction_date', '<=', $end_date);
        }

        //Filter by the location
        if (! empty($location_id)) {
            $query->where('transactions.location_id', $location_id);
        }
        //Filter by the location
        if (! empty($user_id)) {
            $query->where('transactions.created_by', $user_id);
        }

        $purchase_details = $query->get();

        $output['total_purchase_inc_tax'] = $purchase_details->sum('final_total');
        //$output['total_purchase_exc_tax'] = $purchase_details->sum('total_exc_tax');
        $output['total_purchase_exc_tax'] = $purchase_details->sum('total_before_tax');
        $output['purchase_due'] = $purchase_details->sum('final_total') -
            $purchase_details->sum('total_paid');
        $output['total_shipping_charges'] = $purchase_details->sum('shipping_charges');

        return $output;
    }

    /**
     * Gives the total sell amount for a business within the date range passed
     *
     * @param  int  $business_id
     * @param  int  $transaction_id
     * @return array
     */
    public function getSellTotals($business_id, $start_date = null, $end_date = null, $location_id = null, $created_by = null)
    {
        $query = Transaction::where('transactions.business_id', $business_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->select(
                'transactions.id',
                'final_total',
                DB::raw('(final_total - tax_amount) as total_exc_tax'),
                DB::raw('(SELECT SUM(IF(tp.is_return = 1, -1*tp.amount, tp.amount)) FROM transaction_payments as tp WHERE tp.transaction_id = transactions.id) as total_paid'),
                DB::raw('SUM(total_before_tax) as total_before_tax'),
                'shipping_charges'
            )
            ->groupBy('transactions.id');

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (! empty($start_date) && ! empty($end_date)) {
            $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        if (empty($start_date) && ! empty($end_date)) {
            $query->whereDate('transaction_date', '<=', $end_date);
        }

        //Filter by the location
        if (! empty($location_id)) {
            $query->where('transactions.location_id', $location_id);
        }

        if (! empty($created_by)) {
            $query->where('transactions.created_by', $created_by);
        }

        $sell_details = $query->get();

        $output['total_sell_inc_tax'] = $sell_details->sum('final_total');
        //$output['total_sell_exc_tax'] = $sell_details->sum('total_exc_tax');
        $output['total_sell_exc_tax'] = $sell_details->sum('total_before_tax');
        $output['invoice_due'] = $sell_details->sum('final_total') - $sell_details->sum('total_paid');
        $output['total_shipping_charges'] = $sell_details->sum('shipping_charges');

        return $output;
    }

    /**
     * Gives the total input tax for a business within the date range passed
     *
     * @param  int  $business_id
     * @param  string  $start_date default null
     * @param  string  $end_date default null
     * @return float
     */
    public function getInputTax($business_id, $start_date = null, $end_date = null, $location_id = null)
    {
        //Calculate purchase taxes
        $query1 = Transaction::where('transactions.business_id', $business_id)
            ->leftjoin('tax_rates as T', 'transactions.tax_id', '=', 'T.id')
            ->whereIn('type', ['purchase', 'purchase_return'])
            ->whereNotNull('transactions.tax_id')
            ->select(
                DB::raw("SUM( IF(type='purchase', transactions.tax_amount, -1 * transactions.tax_amount) ) as transaction_tax"),
                'T.name as tax_name',
                'T.id as tax_id',
                'T.is_tax_group'
            );

        //Calculate purchase line taxes
        $query2 = Transaction::where('transactions.business_id', $business_id)
            ->leftjoin('purchase_lines as pl', 'transactions.id', '=', 'pl.transaction_id')
            ->leftjoin('tax_rates as T', 'pl.tax_id', '=', 'T.id')
            ->where('type', 'purchase')
            ->whereNotNull('pl.tax_id')
            ->select(
                DB::raw('SUM( (pl.quantity - pl.quantity_returned) * pl.item_tax ) as product_tax'),
                'T.name as tax_name',
                'T.id as tax_id',
                'T.is_tax_group'
            );

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query1->whereIn('transactions.location_id', $permitted_locations);
            $query2->whereIn('transactions.location_id', $permitted_locations);
        }

        if (! empty($start_date) && ! empty($end_date)) {
            $query1->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
            $query2->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        if (! empty($location_id)) {
            $query1->where('transactions.location_id', $location_id);
            $query2->where('transactions.location_id', $location_id);
        }

        $transaction_tax_details = $query1->groupBy('T.id')
            ->get();

        $product_tax_details = $query2->groupBy('T.id')
            ->get();
        $tax_details = [];
        foreach ($transaction_tax_details as $transaction_tax) {
            $tax_details[$transaction_tax->tax_id]['tax_name'] = $transaction_tax->tax_name;
            $tax_details[$transaction_tax->tax_id]['tax_amount'] = $transaction_tax->transaction_tax;

            $tax_details[$transaction_tax->tax_id]['is_tax_group'] = false;
            if ($transaction_tax->is_tax_group == 1) {
                $tax_details[$transaction_tax->tax_id]['is_tax_group'] = true;
            }
        }

        foreach ($product_tax_details as $product_tax) {
            if (! isset($tax_details[$product_tax->tax_id])) {
                $tax_details[$product_tax->tax_id]['tax_name'] = $product_tax->tax_name;
                $tax_details[$product_tax->tax_id]['tax_amount'] = $product_tax->product_tax;

                $tax_details[$product_tax->tax_id]['is_tax_group'] = false;
                if ($product_tax->is_tax_group == 1) {
                    $tax_details[$product_tax->tax_id]['is_tax_group'] = true;
                }
            } else {
                $tax_details[$product_tax->tax_id]['tax_amount'] += $product_tax->product_tax;
            }
        }

        //If group tax add group tax details
        foreach ($tax_details as $key => $value) {
            if ($value['is_tax_group']) {
                $tax_details[$key]['group_tax_details'] = $this->groupTaxDetails($key, $value['tax_amount']);
            }
        }

        $output['tax_details'] = $tax_details;
        $output['total_tax'] = $transaction_tax_details->sum('transaction_tax') + $product_tax_details->sum('product_tax');

        return $output;
    }

    /**
     * Gives the total output tax for a business within the date range passed
     *
     * @param  int  $business_id
     * @param  string  $start_date default null
     * @param  string  $end_date default null
     * @return float
     */
    public function getOutputTax($business_id, $start_date = null, $end_date = null, $location_id = null)
    {
        //Calculate sell taxes
        $query1 = Transaction::where('transactions.business_id', $business_id)
            ->leftjoin('tax_rates as T', 'transactions.tax_id', '=', 'T.id')
            ->whereIn('type', ['sell', 'sell_return'])
            ->whereNotNull('transactions.tax_id')
            ->where('transactions.status', '=', 'final')
            ->select(
                DB::raw("SUM( IF(type='sell', transactions.tax_amount, -1 * transactions.tax_amount) ) as transaction_tax"),
                'T.name as tax_name',
                'T.id as tax_id',
                'T.is_tax_group'
            );

        //Calculate sell line taxes
        $query2 = Transaction::where('transactions.business_id', $business_id)
            ->leftjoin('transaction_sell_lines as tsl', 'transactions.id', '=', 'tsl.transaction_id')
            ->leftjoin('tax_rates as T', 'tsl.tax_id', '=', 'T.id')
            ->where('type', 'sell')
            ->whereNotNull('tsl.tax_id')
            ->where('transactions.status', '=', 'final')
            ->select(
                DB::raw('SUM( (tsl.quantity - tsl.quantity_returned) * tsl.item_tax ) as product_tax'),
                'T.name as tax_name',
                'T.id as tax_id',
                'T.is_tax_group'
            );

        ///Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query1->whereIn('transactions.location_id', $permitted_locations);
            $query2->whereIn('transactions.location_id', $permitted_locations);
        }

        if (! empty($start_date) && ! empty($end_date)) {
            $query1->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
            $query2->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        if (! empty($location_id)) {
            $query1->where('transactions.location_id', $location_id);
            $query2->where('transactions.location_id', $location_id);
        }

        $transaction_tax_details = $query1->groupBy('T.id')
            ->get();

        $product_tax_details = $query2->groupBy('T.id')
            ->get();
        $tax_details = [];
        foreach ($transaction_tax_details as $transaction_tax) {
            $tax_details[$transaction_tax->tax_id]['tax_name'] = $transaction_tax->tax_name;
            $tax_details[$transaction_tax->tax_id]['tax_amount'] = $transaction_tax->transaction_tax;

            $tax_details[$transaction_tax->tax_id]['is_tax_group'] = false;
            if ($transaction_tax->is_tax_group == 1) {
                $tax_details[$transaction_tax->tax_id]['is_tax_group'] = true;
            }
        }

        foreach ($product_tax_details as $product_tax) {
            if (! isset($tax_details[$product_tax->tax_id])) {
                $tax_details[$product_tax->tax_id]['tax_name'] = $product_tax->tax_name;
                $tax_details[$product_tax->tax_id]['tax_amount'] = $product_tax->product_tax;

                $tax_details[$product_tax->tax_id]['is_tax_group'] = false;
                if ($product_tax->is_tax_group == 1) {
                    $tax_details[$product_tax->tax_id]['is_tax_group'] = true;
                }
            } else {
                $tax_details[$product_tax->tax_id]['tax_amount'] += $product_tax->product_tax;
            }
        }

        //If group tax add group tax details
        // foreach ($tax_details as $key => $value) {
        //     if ($value['is_tax_group']) {
        //         $tax_details[$key]['group_tax_details'] = $this->groupTaxDetails($key, $value['tax_amount']);
        //     }
        // }

        $output['tax_details'] = $tax_details;
        $output['total_tax'] = $transaction_tax_details->sum('transaction_tax') + $product_tax_details->sum('product_tax');

        return $output;
    }

    /**
     * Gives the total expense tax for a business within the date range passed
     *
     * @param  int  $business_id
     * @param  string  $start_date default null
     * @param  string  $end_date default null
     * @return float
     */
    public function getExpenseTax($business_id, $start_date = null, $end_date = null, $location_id = null)
    {
        //Calculate expense taxes
        $query = Transaction::where('transactions.business_id', $business_id)
            ->leftjoin('tax_rates as T', 'transactions.tax_id', '=', 'T.id')
            ->where('type', 'expense')
            ->whereNotNull('transactions.tax_id')
            ->select(
                DB::raw('SUM(transactions.tax_amount) as transaction_tax'),
                'T.name as tax_name',
                'T.id as tax_id',
                'T.is_tax_group'
            );

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (! empty($start_date) && ! empty($end_date)) {
            $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        if (! empty($location_id)) {
            $query->where('transactions.location_id', $location_id);
        }

        $transaction_tax_details = $query->groupBy('T.id')
            ->get();

        $tax_details = [];
        foreach ($transaction_tax_details as $transaction_tax) {
            $tax_details[$transaction_tax->tax_id]['tax_name'] = $transaction_tax->tax_name;
            $tax_details[$transaction_tax->tax_id]['tax_amount'] = $transaction_tax->transaction_tax;

            $tax_details[$transaction_tax->tax_id]['is_tax_group'] = false;
            if ($transaction_tax->is_tax_group == 1) {
                $tax_details[$transaction_tax->tax_id]['is_tax_group'] = true;
            }
        }

        //If group tax add group tax details
        foreach ($tax_details as $key => $value) {
            if ($value['is_tax_group']) {
                $tax_details[$key]['group_tax_details'] = $this->groupTaxDetails($key, $value['tax_amount']);
            }
        }

        $output['tax_details'] = $tax_details;
        $output['total_tax'] = $transaction_tax_details->sum('transaction_tax');

        return $output;
    }

    /**
     * Gives total sells of last 30 days day-wise
     *
     * @param  int  $business_id
     * @param  array  $filters
     * @return Obj
     */
    public function getSellsLast30Days($business_id, $group_by_location = false)
    {
        $query = Transaction::leftjoin('transactions as SR', function ($join) {
            $join->on('SR.return_parent_id', '=', 'transactions.id')
                ->where('SR.type', 'sell_return');
        })
            ->where('transactions.business_id', $business_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->whereBetween(DB::raw('date(transactions.transaction_date)'), [Carbon::now()->subDays(30), Carbon::now()]);

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        $query->select(
            DB::raw("DATE_FORMAT(transactions.transaction_date, '%Y-%m-%d') as date"),
            DB::raw('SUM( transactions.final_total - COALESCE(SR.final_total, 0) ) as total_sells')
        )
            ->groupBy(DB::raw('Date(transactions.transaction_date)'));

        if ($group_by_location) {
            $query->addSelect('transactions.location_id');
            $query->groupBy('transactions.location_id');
        }
        $sells = $query->get();

        if (! $group_by_location) {
            $sells = $sells->pluck('total_sells', 'date');
        }

        return $sells;
    }

    /**
     * Gives total sells of current FY month-wise
     *
     * @param  int  $business_id
     * @param  string  $start
     * @param  string  $end
     * @return Obj
     */
    public function getSellsCurrentFy($business_id, $start, $end, $group_by_location = false)
    {
        $query = Transaction::leftjoin('transactions as SR', function ($join) {
            $join->on('SR.return_parent_id', '=', 'transactions.id')
                ->where('SR.type', 'sell_return');
        })
            ->where('transactions.business_id', $business_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->whereBetween(DB::raw('date(transactions.transaction_date)'), [$start, $end]);

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        $query->groupBy(DB::raw("DATE_FORMAT(transactions.transaction_date, '%Y-%m')"))
            ->select(
                DB::raw("DATE_FORMAT(transactions.transaction_date, '%m-%Y') as yearmonth"),
                DB::raw('SUM( transactions.final_total - COALESCE(SR.final_total, 0)) as total_sells')
            );
        if ($group_by_location) {
            $query->addSelect('transactions.location_id');
            $query->groupBy('transactions.location_id');
        }

        $sells = $query->get();
        if (! $group_by_location) {
            $sells = $sells->pluck('total_sells', 'yearmonth');
        }

        return $sells;
    }

    /**
     * Retrives expense report
     *
     * @param  int  $business_id
     * @param  array  $filters
     * @param  string  $type = by_category (by_category or total)
     * @return Obj
     */
    public function getExpenseReport(
        $business_id,
        $filters = [],
        $type = 'by_category'
    ) {
        $query = Transaction::leftjoin('expense_categories AS ec', 'transactions.expense_category_id', '=', 'ec.id')
            ->where('transactions.business_id', $business_id)
            ->whereIn('type', ['expense', 'expense_refund']);
        // ->where('payment_status', 'paid');

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (! empty($filters['location_id'])) {
            $query->where('transactions.location_id', $filters['location_id']);
        }

        if (! empty($filters['expense_for'])) {
            $query->where('transactions.expense_for', $filters['expense_for']);
        }

        if (! empty($filters['category'])) {
            $query->where('ec.id', $filters['category']);
        }

        if (! empty($filters['start_date']) && ! empty($filters['end_date'])) {
            $query->whereBetween(DB::raw('date(transaction_date)'), [$filters['start_date'],
                $filters['end_date']]);
        }

        //Check tht type of report and return data accordingly
        if ($type == 'by_category') {
            $expenses = $query->select(
                DB::raw("SUM( IF(transactions.type='expense_refund', -1 * final_total, final_total) ) as total_expense"),
                'ec.name as category'
            )
                ->groupBy('expense_category_id')
                ->get();
        } elseif ($type == 'total') {
            $expenses = $query->select(
                DB::raw("SUM( IF(transactions.type='expense_refund', -1 * final_total, final_total) ) as total_expense")
            )
                ->first();
        }

        return $expenses;
    }

    /**
     * Get total paid amount for a transaction
     *
     * @param  int  $transaction_id
     * @return int
     */
    public function getTotalPaid($transaction_id)
    {
        $total_paid = TransactionPayment::where('transaction_id', $transaction_id)
            ->select(DB::raw('SUM(IF( is_return = 0, amount, amount*-1))as total_paid'))
            ->first()
            ->total_paid;

        return $total_paid;
    }

    /**
     * Calculates the payment status and returns back.
     *
     * @param  int  $transaction_id
     * @param  float  $final_amount = null
     * @return string
     */
    public function calculatePaymentStatus($transaction_id, $final_amount = null, $is_settlement = null, $return_paid = null)
    {
        //Getting current order status to check is it cancelled one
        $current_status = Transaction::find($transaction_id)->status;
        if (is_null($return_paid)) {
            $total_paid = $this->getTotalPaid($transaction_id);
        } else {
            $total_paid = $return_paid;
        }
        $total_paid = $this->num_uf($total_paid);

        if (is_null($final_amount)) {
            $final_amount = Transaction::find($transaction_id)->final_total;
        }
        $final_amount = $this->num_uf($final_amount);

        if ($current_status == 'cancelled') {
            $status = 'setoff';
        } else {
            $status = 'due';
        }

        if (is_null($is_settlement)) {
            if ($final_amount <= $total_paid) {
                if ($current_status == 'cancelled') {
                    $status = 'refund-required';
                } else {
                    $status = 'paid';
                }
            } elseif ($total_paid > 0 && $final_amount > $total_paid) {
                if ($current_status == 'cancelled') {
                    $status = 'refund-required';
                } else {
                    $status = 'partial';
                }
            }
        } else {
            if ($final_amount <= $total_paid) {
                $status = 'setoff';
            } elseif ($total_paid > 0 && $final_amount > $total_paid) {
                $status = 'partial-setoff';
            }
        }

        return $status;
    }

    /**
     * Purchase currency details
     *
     * @param  int  $business_id
     * @return object
     */
    public function purchaseCurrencyDetails($business_id)
    {
        $business = Business::find($business_id);
        $output = ['purchase_in_diff_currency' => false,
            'p_exchange_rate' => 1,
            'decimal_seperator' => '.',
            'thousand_seperator' => ',',
            'symbol' => '',
        ];

        //Check if diff currency is used or not.
        if ($business->purchase_in_diff_currency == 1) {
            $output['purchase_in_diff_currency'] = true;
            $output['p_exchange_rate'] = $business->p_exchange_rate;

            $currency_id = $business->purchase_currency_id;
        } else {
            $output['purchase_in_diff_currency'] = false;
            $output['p_exchange_rate'] = 1;
            $currency_id = $business->currency_id;
        }

        $currency = Currency::find($currency_id);
        $output['thousand_separator'] = $currency->thousand_separator;
        $output['decimal_separator'] = $currency->decimal_separator;
        $output['symbol'] = $currency->symbol;
        $output['code'] = $currency->code;
        $output['name'] = $currency->currency;

        return (object) $output;
    }


    /**
     * Check if transaction can be edited based on business     transaction_edit_days
     *
     * @param  int/object $transaction
     * @param  int  $edit_duration
     * @return bool
     */
    public function canBeEdited($transaction, $edit_duration)
    {
        if (! is_object($transaction)) {
            $transaction = Transaction::find($transaction);
        }
        if (empty($transaction)) {
            return false;
        }

        $date = Carbon::parse($transaction->transaction_date)
            ->addDays($edit_duration);

        $today = today();

        if ($date->gte($today)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Calculates total stock on the given date
     *
     * @param  int  $business_id
     * @param  string  $date
     * @param  int  $location_id
     * @param  bool  $is_opening = false
     * @return float
     */
    public function getOpeningClosingStock($business_id, $date, $location_id, $is_opening = false, $by_sale_price = false, $filters = [])
    {
        $query = PurchaseLine::on('reports')->join(
            'transactions as purchase',
            'purchase_lines.transaction_id',
            '=',
            'purchase.id'
        )
            ->where('purchase.business_id', $business_id);

        $price_query_part = '(purchase_lines.purchase_price +
                            COALESCE(purchase_lines.item_tax, 0))';

        if ($by_sale_price) {
            $price_query_part = 'v.sell_price_inc_tax';
        }

        $query->leftjoin('variations as v', 'v.id', '=', 'purchase_lines.variation_id')
            ->leftjoin('products as p', 'p.id', '=', 'purchase_lines.product_id');

        if (! empty($filters['category_id'])) {
            $query->where('p.category_id', $filters['category_id']);
        }
        if (! empty($filters['sub_category_id'])) {
            $query->where('p.sub_category_id', $filters['sub_category_id']);
        }
        if (! empty($filters['brand_id'])) {
            $query->where('p.brand_id', $filters['brand_id']);
        }
        if (! empty($filters['unit_id'])) {
            $query->where('p.unit_id', $filters['unit_id']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('purchase.created_by', $filters['user_id']);
        }

        //If opening
        if ($is_opening) {
            $next_day = Carbon::createFromFormat('Y-m-d', $date)->addDay()->format('Y-m-d');

            $query->where(function ($query) use ($date, $next_day) {
                $query->whereRaw("date(transaction_date) <= '$date'")
                    ->orWhereRaw("date(transaction_date) = '$next_day' AND purchase.type='opening_stock' ");
            });
        } else {
            $query->whereRaw("date(transaction_date) <= '$date'");
        }

        $query->select(
            DB::raw("SUM((purchase_lines.quantity - purchase_lines.quantity_returned - purchase_lines.quantity_adjusted -
                            (SELECT COALESCE(SUM(tspl.quantity - tspl.qty_returned), 0) FROM
                            transaction_sell_lines_purchase_lines AS tspl
                            JOIN transaction_sell_lines as tsl ON
                            tspl.sell_line_id=tsl.id
                            JOIN transactions as sale ON
                            tsl.transaction_id=sale.id
                            WHERE tspl.purchase_line_id = purchase_lines.id AND
                            date(sale.transaction_date) <= '$date') ) * $price_query_part
                        ) as stock")
        );

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('purchase.location_id', $permitted_locations);
        }

        if (! empty($location_id)) {
            $query->where('purchase.location_id', $location_id);
        }

        $details = $query->first();

        return $details->stock;
    }

    /**
     * Gives the total sell commission for a commission agent within the date range passed
     *
     * @param  int  $business_id
     * @param  string  $start_date
     * @param  string  $end_date
     * @param  int  $location_id
     * @param  int  $commission_agent
     * @return array
     */
    public function getTotalSellCommission($business_id, $start_date = null, $end_date = null, $location_id = null, $commission_agent = null)
    {
        $query = Transaction::leftjoin('transactions as SR', function ($join) {
            $join->on('SR.return_parent_id', '=', 'transactions.id')
                ->where('SR.type', 'sell_return');
        })
            ->where('transactions.business_id', $business_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->select(DB::raw('SUM( transactions.final_total - COALESCE(SR.final_total, 0) ) as final_total'));

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (! empty($start_date) && ! empty($end_date)) {
            $query->whereBetween(DB::raw('date(transactions.transaction_date)'), [$start_date, $end_date]);
        }

        //Filter by the location
        if (! empty($location_id)) {
            $query->where('transactions.location_id', $location_id);
        }

        if (! empty($commission_agent)) {
            $query->where('transactions.commission_agent', $commission_agent);
        }

        $sell_details = $query->get();

        $output['total_sales_with_commission'] = $sell_details->sum('final_total');

        return $output;
    }

    public function groupTaxDetails($tax, $amount)
    {
        if (! is_object($tax)) {
            $tax = TaxRate::find($tax);
        }

        if (! empty($tax)) {
            $sub_taxes = $tax->sub_taxes;

            $sum = $tax->sub_taxes->sum('amount');

            $details = [];
            foreach ($sub_taxes as $sub_tax) {
                $details[] = [
                    'id' => $sub_tax->id,
                    'name' => $sub_tax->name,
                    'amount' => $sub_tax->amount,
                    'calculated_tax' => ($amount / $sum) * $sub_tax->amount,
                ];
            }

            return $details;
        } else {
            return [];
        }
    }

    public function sumGroupTaxDetails($group_tax_details)
    {
        $output = [];

        foreach ($group_tax_details as $group_tax_detail) {
            if (! isset($output[$group_tax_detail['name']])) {
                $output[$group_tax_detail['name']] = 0;
            }
            $output[$group_tax_detail['name']] += $group_tax_detail['calculated_tax'];
        }

        return $output;
    }

    /**
     * Retrieves all available lot numbers of a product from variation id
     *
     * @param  int  $variation_id
     * @param  int  $business_id
     * @param  int  $location_id
     * @return array
     */
    public function getLotNumbersFromVariation($variation_id, $business_id, $location_id, $exclude_empty_lot = false, $account_method = 'fifo')
    {
        $query = PurchaseLine::join(
            'transactions as T',
            'purchase_lines.transaction_id',
            '=',
            'T.id'
        )
            ->groupBy('purchase_lines.id')
            ->where('T.business_id', $business_id)
            ->where('T.location_id', $location_id)
            ->where('T.status', 'received')
            ->where('purchase_lines.variation_id', $variation_id);

        //If expiry is disabled
        if (request()->session()->get('business.enable_product_expiry') == 0) {
            $query->whereNotNull('purchase_lines.lot_number');
        }
        if ($exclude_empty_lot) {
            //            $query->whereRaw('(purchase_lines.quantity_sold + purchase_lines.quantity_adjusted + purchase_lines.quantity_returned) < purchase_lines.quantity');
            $query->havingRaw('qty_available > 0');
        }
        // } else {
        //     $query->whereRaw('(purchase_lines.quantity_sold + purchase_lines.quantity_adjusted + purchase_lines.quantity_returned) <= purchase_lines.quantity');
        // }
        $order = 'ASC';
        if ($account_method == 'lifo') {
            $order = 'DESC';
        }

        $purchase_lines = $query->select('purchase_lines.id as purchase_line_id',
            'lot_number',
            'purchase_lines.exp_date as exp_date',
            'purchase_lines.purchase_price',
            DB::raw('SUM((purchase_lines.quantity - (purchase_lines.quantity_sold + purchase_lines.quantity_adjusted + purchase_lines.quantity_returned + purchase_lines.mfg_quantity_used))) AS qty_available'),
            DB::raw("(SELECT SUM(tsl.quantity) FROM transaction_sell_lines AS tsl INNER JOIN transactions AS t ON tsl.transaction_id = t.id WHERE
        tsl.variation_id = $variation_id AND t.type = 'sell_transfer' AND t.status = 'in_transit' AND t.location_id = $location_id) as flaged_qty"))
            ->orderBy('purchase_lines.id', $order)
            ->get();

        return $purchase_lines;
    }

    /**
     * Checks if credit limit of a customer is exceeded
     *
     * @param  array  $input
     * @param  int  $exclude_transaction_id (For update sell)
     * @return mixed
     * if exceeded returns credit_limit else false
     */
    public function isCustomerCreditLimitExeeded(
        $input,
        $exclude_transaction_id = null
    ) {
        //If draft ignore credit limit check
        if ($input['status'] == 'draft') {
            return false;
        }

        $final_total = $this->num_uf($input['final_total']);
        $curr_total_payment = 0;
        $is_credit_sale = isset($input['is_credit_sale']) && $input['is_credit_sale'] == 1 ? true : false;
        if (! empty($input['payment']) && ! $is_credit_sale) {
            foreach ($input['payment'] as $payment) {
                $curr_total_payment += $this->num_uf($payment['amount']);
            }
        }

        //If not credit sell ignore credit limit check
        if ($final_total <= $curr_total_payment) {
            return false;
        }

        $credit_limit = Contact::find($input['contact_id'])->credit_limit;

        if ($credit_limit == null) {
            return false;
        }

        $query = Contact::where('contacts.id', $input['contact_id'])
            ->join('transactions AS t', 'contacts.id', '=', 't.contact_id');

        //Exclude transaction id if update transaction
        if (! empty($exclude_transaction_id)) {
            $query->where('t.id', '!=', $exclude_transaction_id);
        }

        $credit_details = $query->select(
            DB::raw("SUM(IF(t.type = 'sell', final_total, 0)) as total_invoice"),
            DB::raw("SUM(IF(t.type = 'sell', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as invoice_paid")
        )->first();

        $total_invoice = ! empty($credit_details->total_invoice) ? $credit_details->total_invoice : 0;
        $invoice_paid = ! empty($credit_details->invoice_paid) ? $credit_details->invoice_paid : 0;

        $curr_due = $final_total - $curr_total_payment;

        $total_due = $total_invoice - $invoice_paid + $curr_due;

        if ($total_due <= $credit_limit) {
            return false;
        }

        return $credit_limit;
    }

    /**
     * Check if return exist for a particular purchase or sell
     *
     * @param  id  $transacion_id
     * @return bool
     */
    public function isReturnExist($transacion_id)
    {
        return Transaction::where('return_parent_id', $transacion_id)->exists();
    }

    /**
     * Recalculates sell line data according to subunit data
     *
     * @param  int  $unit_id
     * @return array
     */
    public function recalculateSellLineTotals($business_id, $sell_line)
    {
        $unit_details = $this->getSubUnits($business_id, $sell_line->product->unit->id);

        $sub_unit = null;
        $sub_unit_id = $sell_line->sub_unit_id;
        foreach ($unit_details as $key => $value) {
            if ($key == $sub_unit_id) {
                $sub_unit = $value;
            }
        }

        if (! empty($sub_unit)) {
            $multiplier = ! empty($sub_unit['multiplier']) ? $sub_unit['multiplier'] : 1;
            $sell_line->quantity = $sell_line->quantity / $multiplier;
            $sell_line->unit_price_before_discount = $sell_line->unit_price_before_discount * $multiplier;
            $sell_line->unit_price = $sell_line->unit_price * $multiplier;
            $sell_line->unit_price_inc_tax = $sell_line->unit_price_inc_tax * $multiplier;
            $sell_line->item_tax = $sell_line->item_tax * $multiplier;
            $sell_line->quantity_returned = $sell_line->quantity_returned / $multiplier;

            $sell_line->unit_details = $unit_details;
        }

        return $sell_line;
    }

    /**
     * Check if lot number is used in any sell
     *
     * @param  obj  $transaction
     * @return bool
     */
    public function isLotUsed($transaction)
    {
        foreach ($transaction->purchase_lines as $purchase_line) {
            $exists = TransactionSellLine::where('lot_no_line_id', $purchase_line->id)->exists();
            if ($exists) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieves and sum total amount paid for a transaction
     *
     * @param  int  $transaction_id
     */
    public function getTotalAmountPaid($transaction_id)
    {
        $paid = TransactionPayment::where(
            'transaction_id',
            $transaction_id
        )->sum('amount');

        return $paid;
    }

    /**
     * Calculates transaction totals for the given transaction types
     *
     * @param  int  $business_id
     * @param  array  $transaction_types
     * available types = ['purchase_return', 'sell_return', 'expense',
     * 'stock_adjustment', 'sell_transfer', 'purchase', 'sell']
     * @param  string  $start_date = null
     * @param  string  $end_date = null
     * @param  int  $location_id = null
     * @param  int  $created_by = null
     * @return array
     */
    public function getTransactionTotals(
        $business_id,
        $transaction_types,
        $start_date = null,
        $end_date = null,
        $location_id = null,
        $created_by = null
    ) {
        $query = Transaction::where('business_id', $business_id);

        //Check for permitted locations of a user
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (! empty($start_date) && ! empty($end_date)) {
            $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        if (empty($start_date) && ! empty($end_date)) {
            $query->whereDate('transaction_date', '<=', $end_date);
        }

        //Filter by the location
        if (! empty($location_id)) {
            $query->where('transactions.location_id', $location_id);
        }

        //Filter by created_by
        if (! empty($created_by)) {
            $query->where('transactions.created_by', $created_by);
        }

        if (in_array('purchase_return', $transaction_types)) {
            $query->addSelect(
                DB::raw("SUM(IF(transactions.type='purchase_return', final_total, 0)) as total_purchase_return_inc_tax"),
                DB::raw("SUM(IF(transactions.type='purchase_return', total_before_tax, 0)) as total_purchase_return_exc_tax")
            );
        }

        if (in_array('sell_return', $transaction_types)) {
            $query->addSelect(
                DB::raw("SUM(IF(transactions.type='sell_return', final_total, 0)) as total_sell_return_inc_tax"),
                DB::raw("SUM(IF(transactions.type='sell_return', total_before_tax, 0)) as total_sell_return_exc_tax"),
                DB::raw("SUM(IF(transactions.type='sell_return', shipping_charges, 0)) as total_return_shipping_charges"),
                DB::raw("SUM(IF(transactions.type='sell_return', payment_fee_amount, 0)) as total_return_payment_fee"),
                DB::raw("SUM(IF(transactions.type='sell_return', round_off_amount, 0)) as total_return_round_off_amount")
            );
        }

        $query->addSelect(
            DB::raw('SUM(shipping_charges) as total_shipping_charges')

        );

        $query->addSelect(
            DB::raw('SUM(payment_fee_amount) as total_payment_fee')

        );

        if (in_array('sell_transfer', $transaction_types)) {
            $query->addSelect(
                DB::raw("SUM(IF(transactions.type='sell_transfer', shipping_charges, 0)) as total_transfer_shipping_charges")

            );
        }

        if (in_array('expense', $transaction_types)) {
            $query->addSelect(
                DB::raw("SUM(IF(transactions.type='expense', final_total, 0)) as total_expense")
            );

            $query->addSelect(
                DB::raw("SUM(IF(transactions.type='expense_refund', final_total, 0)) as total_expense_refund")
            );
        }

        if (in_array('payroll', $transaction_types)) {
            $query->addSelect(
                DB::raw("SUM(IF(transactions.type='payroll', final_total, 0)) as total_payroll")
            );
        }

        if (in_array('stock_adjustment', $transaction_types)) {
            $query->addSelect(
                DB::raw("SUM(IF(transactions.type='stock_adjustment', final_total, 0)) as total_adjustment"),
                DB::raw("SUM(IF(transactions.type='stock_adjustment', total_amount_recovered, 0)) as total_recovered")
            );
        }

        if (in_array('purchase', $transaction_types)) {
            $query->addSelect(
                DB::raw("SUM(IF(transactions.type='purchase', IF(discount_type = 'percentage', COALESCE(discount_amount, 0)*total_before_tax/100, COALESCE(discount_amount, 0)), 0)) as total_purchase_discount")
            );
        }

        if (in_array('sell', $transaction_types)) {
            $query->addSelect(
                DB::raw("SUM(IF(transactions.type='sell' AND transactions.status='final', IF(discount_type = 'percentage', COALESCE(discount_amount, 0)*total_before_tax/100, COALESCE(discount_amount, 0)), 0)) as total_sell_discount"),
                DB::raw("SUM(IF(transactions.type='sell' AND transactions.status='final', rp_redeemed_amount, 0)) as total_reward_amount"),
                DB::raw("SUM(IF(transactions.type='sell' AND transactions.status='final', round_off_amount, 0)) as total_sell_round_off")
            );
        }

        $transaction_totals = $query->first();
        $output = [];

        if (in_array('purchase_return', $transaction_types)) {
            $output['total_purchase_return_inc_tax'] = ! empty($transaction_totals->total_purchase_return_inc_tax) ?
                $transaction_totals->total_purchase_return_inc_tax : 0;

            $output['total_purchase_return_exc_tax'] =
                ! empty($transaction_totals->total_purchase_return_exc_tax) ?
                    $transaction_totals->total_purchase_return_exc_tax : 0;
        }

        if (in_array('sell_return', $transaction_types)) {
            $output['total_sell_return_inc_tax'] =
                ! empty($transaction_totals->total_sell_return_inc_tax) ?
                    $transaction_totals->total_sell_return_inc_tax : 0;

            $output['total_sell_return_exc_tax'] =
                ! empty($transaction_totals->total_sell_return_exc_tax) ?
                    $transaction_totals->total_sell_return_exc_tax : 0;

            $output['total_return_round_off_amount'] =
                ! empty($transaction_totals->total_return_round_off_amount) ?
                    $transaction_totals->total_return_round_off_amount : 0;

            $output['total_return_shipping_charges'] =
                ! empty($transaction_totals->total_return_shipping_charges) ?
                    $transaction_totals->total_return_shipping_charges : 0;

            $output['total_return_payment_fee'] =
                ! empty($transaction_totals->total_return_payment_fee) ?
                    $transaction_totals->total_return_payment_fee : 0;
        }

        if (in_array('sell_transfer', $transaction_types)) {
            $output['total_transfer_shipping_charges'] =
                ! empty($transaction_totals->total_transfer_shipping_charges) ?
                    $transaction_totals->total_transfer_shipping_charges : 0;
        }

        if (in_array('expense', $transaction_types)) {
            $total_expense = ! empty($transaction_totals->total_expense) ?
                $transaction_totals->total_expense : 0;
            $total_expense_refund = ! empty($transaction_totals->total_expense_refund) ?
                $transaction_totals->total_expense_refund : 0;
            $output['total_expense'] = $total_expense - $total_expense_refund;
        }

        $total_shipping_charges = ! empty($transaction_totals->total_shipping_charges) ?
            $transaction_totals->total_shipping_charges : 0;
        $output['total_shipping_charges'] = $total_shipping_charges - $transaction_totals->total_return_shipping_charges;

        //payment_fee
        $total_payment_fee = ! empty($transaction_totals->total_payment_fee) ?
            $transaction_totals->total_payment_fee : 0;
        $output['total_payment_fee'] = $total_payment_fee - $transaction_totals->total_return_payment_fee;

        if (in_array('payroll', $transaction_types)) {
            $output['total_payroll'] =
                ! empty($transaction_totals->total_payroll) ?
                    $transaction_totals->total_payroll : 0;
        }

        if (in_array('stock_adjustment', $transaction_types)) {
            $output['total_adjustment'] =
                ! empty($transaction_totals->total_adjustment) ?
                    $transaction_totals->total_adjustment : 0;

            $output['total_recovered'] =
                ! empty($transaction_totals->total_recovered) ?
                    $transaction_totals->total_recovered : 0;
        }

        if (in_array('purchase', $transaction_types)) {
            $output['total_purchase_discount'] =
                ! empty($transaction_totals->total_purchase_discount) ?
                    $transaction_totals->total_purchase_discount : 0;
        }

        if (in_array('sell', $transaction_types)) {
            $output['total_sell_discount'] =
                ! empty($transaction_totals->total_sell_discount) ?
                    $transaction_totals->total_sell_discount : 0;

            $output['total_reward_amount'] =
                ! empty($transaction_totals->total_reward_amount) ?
                    $transaction_totals->total_reward_amount : 0;

            $output['total_sell_round_off'] =
                ! empty($transaction_totals->total_sell_round_off) ?
                    $transaction_totals->total_sell_round_off : 0;
        }

        return $output;
    }

    public function getGrossProfit($business_id, $start_date = null, $end_date = null, $location_id = null, $user_id = null)
    {
        $query = TransactionSellLinesPurchaseLines::join('transaction_sell_lines
                        as SL', 'SL.id', '=', 'transaction_sell_lines_purchase_lines.sell_line_id')
            ->join('transactions as sale', 'SL.transaction_id', '=', 'sale.id')
            ->leftjoin('purchase_lines as PL', 'PL.id', '=', 'transaction_sell_lines_purchase_lines.purchase_line_id')
            ->join('variations as v', 'SL.variation_id', '=', 'v.id')
            ->where('sale.business_id', $business_id);

        if (! empty($start_date) && ! empty($end_date) && $start_date != $end_date) {
            $query->whereDate('sale.transaction_date', '>=', $start_date)
                ->whereDate('sale.transaction_date', '<=', $end_date);
            // $query->whereBetween(DB::raw('sale.transaction_date'), [$start_date, $end_date]);
        }
        if (! empty($start_date) && ! empty($end_date) && $start_date == $end_date) {
            $query->whereDate('sale.transaction_date', $end_date);
        }

        //Filter by the location
        if (! empty($location_id)) {
            $query->where('sale.location_id', $location_id);
        }

        if (! empty($user_id)) {
            $query->where('sale.created_by', $user_id);
        }

        $gross_profit_obj = $query->select(DB::raw('SUM(
                        (transaction_sell_lines_purchase_lines.quantity - transaction_sell_lines_purchase_lines.qty_returned) * (SL.unit_price_inc_tax - IFNULL(PL.purchase_price_inc_tax, v.default_purchase_price) ) ) as gross_profit'))
            ->first();
        $gross_profit = ! empty($gross_profit_obj->gross_profit) ? $gross_profit_obj->gross_profit : 0;

        //Deduct the sell transaction discounts.
        $transaction_totals = $this->getTransactionTotals($business_id, ['sell'], $start_date, $end_date, $location_id, $user_id);
        $sell_discount = ! empty($transaction_totals['total_sell_discount']) ? $transaction_totals['total_sell_discount'] : 0;

        //Get total selling price of products with stock disabled
        $query_2 =
            TransactionSellLine::join('transactions as sale',
                'transaction_sell_lines.transaction_id', '=', 'sale.id')
                ->join('products as p', 'p.id', '=', 'transaction_sell_lines.product_id')
                ->where('sale.business_id', $business_id)
                ->where('sale.status', 'final')
                ->where('sale.type', 'sell')
                ->where('p.enable_stock', 0);

        if (! empty($start_date) && ! empty($end_date) && $start_date != $end_date) {
            $query_2->whereBetween(DB::raw('sale.transaction_date'), [$start_date, $end_date]);
        }
        if (! empty($start_date) && ! empty($end_date) && $start_date == $end_date) {
            $query_2->whereDate('sale.transaction_date', $end_date);
        }

        //Filter by the location
        if (! empty($location_id)) {
            $query_2->where('sale.location_id', $location_id);
        }

        if (! empty($user_id)) {
            $query_2->where('sale.created_by', $user_id);
        }

        $stock_disabled_product_sell_details =
            $query_2->select(DB::raw('SUM(
                        (transaction_sell_lines.quantity - transaction_sell_lines.quantity_returned ) * transaction_sell_lines.unit_price_inc_tax ) as gross_profit'))
                ->first();

        $stock_disabled_product_profit = ! empty($stock_disabled_product_sell_details->gross_profit) ? $stock_disabled_product_sell_details->gross_profit : 0;

        //KNOWS ISSUE: If products are returned then also the discount gets applied for it.

        return $gross_profit + $stock_disabled_product_profit - $sell_discount;
    }

    /**
     * Calculates reward points to be earned from an order
     *
     * @return int
     */
    public function calculateRewardPoints($business_id, $total)
    {
        if (session()->has('business')) {
            $business = session()->get('business');
        } else {
            $business = Business::find($business_id);
        }
        $total_points = 0;

        if ($business->enable_rp == 1) {
            //check if order total elegible for reward
            if ($business->min_order_total_for_rp > $total) {
                return $total_points;
            }
            $amount_per_unit_point = $business->amount_for_unit_rp;

            $total_points = floor($total / $amount_per_unit_point);

            if (! empty($business->max_rp_per_order) && $business->max_rp_per_order < $total_points) {
                $total_points = $business->max_rp_per_order;
            }
        }

        return $total_points;
    }

    /**
     * Calculates reward points to be redeemed from an order
     *
     * @return array
     */
    public function getRewardRedeemDetails($business_id, $customer_id)
    {
        if (session()->has('business')) {
            $business = session()->get('business');
        } else {
            $business = Business::find($business_id);
        }
        $details = ['points' => 0, 'amount' => 0];

        $customer = Contact::where('business_id', $business_id)
            ->find($customer_id);
        $customer_reward_points = $customer->total_rp;

        //If zero reward point or walk in customer return blank values
        if (empty($customer_reward_points) || $customer->is_default == 1) {
            return $details;
        }

        $min_reward_point_required = $business->min_redeem_point;

        if (! empty($min_reward_point_required) && $customer_reward_points < $min_reward_point_required) {
            return $details;
        }

        $max_redeem_point = $business->max_redeem_point;

        if (! empty($max_redeem_point) && $max_redeem_point <= $customer_reward_points) {
            $customer_reward_points = $max_redeem_point;
        }

        $amount_per_unit_point = $business->redeem_amount_per_unit_rp;

        $equivalent_amount = $customer_reward_points * $amount_per_unit_point;

        $details = ['points' => $customer_reward_points, 'amount' => $equivalent_amount];

        return $details;
    }

    /**
     * Checks whether a reward point date is expired
     *
     * @return bool
     */
    public function isRewardExpired($date, $business_id)
    {
        if (session()->has('business')) {
            $business = session()->get('business');
        } else {
            $business = Business::find($business_id);
        }

        $is_expired = false;

        if (! empty($business->rp_expiry_period)) {
            $expiry_date = Carbon::parse($date);
            if ($business->rp_expiry_type == 'month') {
                $expiry_date = $expiry_date->addMonths($business->rp_expiry_period);
            } elseif ($business->rp_expiry_type == 'year') {
                $expiry_date = $expiry_date->addYears($business->rp_expiry_period);
            }

            if ($expiry_date->format('Y-m-d') >= Carbon::now()->format('Y-m-d')) {
                $is_expired = true;
            }
        }

        return $is_expired;
    }

    /**
     * common function to get
     * list purchase
     *
     * @param  int  $business_id
     * @return object
     */
    public function getListPurchases($business_id)
    {
        $purchases = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->join(
                'business_locations AS BS',
                'transactions.location_id',
                '=',
                'BS.id'
            )
            ->leftJoin(
                'transaction_payments AS TP',
                'transactions.id',
                '=',
                'TP.transaction_id'
            )
            ->leftJoin(
                'transactions AS PR',
                'transactions.id',
                '=',
                'PR.return_parent_id'
            )
            ->leftJoin('users as u', 'transactions.created_by', '=', 'u.id')
            ->where('transactions.business_id', $business_id)
            ->where('transactions.type', 'purchase')
            ->select(
                'transactions.id',
                'transactions.document',
                'transactions.transaction_date',
                'transactions.ref_no',
                'transactions.supplier_bill_no',
                'contacts.name',
                'transactions.status',
                'transactions.payment_status',
                'transactions.final_total',
                'BS.name as location_name',
                'transactions.pay_term_number',
                'transactions.pay_term_type',
                'transactions.created_at',
                'transactions.updated_at',
                'PR.id as return_transaction_id',
                'transactions.is_exchange',
                DB::raw('SUM(TP.amount) as amount_paid'),
                DB::raw('(SELECT SUM(TP2.amount) FROM transaction_payments AS TP2 WHERE
                        TP2.transaction_id=PR.id ) as return_paid'),
                DB::raw('DATE_FORMAT(PR.transaction_date, "%Y/%m/%d") as return_date'),
                DB::raw('COUNT(PR.id) as return_exists'),
                DB::raw('COALESCE(PR.final_total, 0) as amount_return'),
                DB::raw("CONCAT(COALESCE(u.surname, ''),' ',COALESCE(u.first_name, ''),' ',COALESCE(u.last_name,'')) as added_by"),

            )
            ->groupBy('transactions.id');

        return $purchases;
    }

    /**
     * common function to get
     * list sell
     *
     * @param  int  $business_id
     * @return object
     */
    public function getListSells($business_id, $all_sale_id = 'sell')
    {
        $sells = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
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
            ->where('transactions.business_id', $business_id)
            ->where('transactions.status', 'final');

        $sells->where('transactions.type', $all_sale_id);

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
            DB::raw('DATE_FORMAT(transactions.created_at, "%Y/%m/%d %H:%m:%s") as created_at'),
            DB::raw('DATE_FORMAT(transactions.updated_at, "%Y/%m/%d %H:%m:%s") as updated_at'),
            'transactions.ogf_is_sync',
            'cap.commision',
            'cap.commission_payment',
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
            'transactions.is_cod',
            DB::raw("CONCAT(COALESCE(comi_agent.surname, ''),' ',COALESCE(comi_agent.first_name, ''),' ',COALESCE(comi_agent.last_name,'')) as com_agent"),
            DB::raw(
                "(SELECT IF((temp.approve_total/temp.line_count)=1,1,0) FROM
                            (SELECT
                                 IF(aaa.status = 'approved',1,0) as approve_total,
                                 COUNT(aaa.id) as line_count
                             FROM admin_approve_actions AS aaa WHERE aaa.transaction_id = transactions.id
                                             AND variation_id IS NOT NULL) temp) as approved"
            )

        );

        return $sells;
    }

    /**
     * common function to get
     * list sell
     *
     * @param  int  $business_id
     * @return object
     */
    public function getListContactSells($business_id)
    {
        $user_id = session()->get('user.id');
        $user_crm_id = User::where('id', $user_id)->select('crm_contact_id')->first();
        $contact_id = $user_crm_id->crm_contact_id;
        $contactpos = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
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
            ->where('transactions.business_id', $business_id)
            ->where('transactions.type', 'sell')
            ->whereNotIn('transactions.status', ['final', 'ordered'])
            ->where('transactions.contact_id', $contact_id)
            ->select(
                'transactions.id',
                'transactions.transaction_date',
                'transactions.is_direct_sale',
                'transactions.invoice_no',
                'transactions.status',
                'transactions.is_quotation',
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
                DB::raw('DATE_FORMAT(transactions.transaction_date, "%Y/%m/%d") as sale_date'),
                DB::raw("CONCAT(COALESCE(u.surname, ''),' ',COALESCE(u.first_name, ''),' ',COALESCE(u.last_name,'')) as added_by"),
                DB::raw('(SELECT SUM(IF(TP.is_return = 1,-1*TP.amount,TP.amount)) FROM transaction_payments AS TP WHERE
                        TP.transaction_id=transactions.id) as total_paid'),
                'bl.name as business_location',
                DB::raw('COUNT(SR.id) as return_exists'),
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
                'msts.sale_type as multi_sale_type'
            );

        return $contactpos;
    }

    public function getTransactionReturnDue($business_id, $transaction_id)
    {

        $return_due = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
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
            ->where('transactions.business_id', $business_id)
            ->where('transactions.id', $transaction_id)
            ->select(

                DB::raw('(SELECT SUM(IF(TP.is_return = 1,-1*TP.amount,TP.amount)) FROM transaction_payments AS TP WHERE
                        TP.transaction_id=transactions.id) as total_paid'),
                'bl.name as business_location',
                DB::raw('(SELECT SUM(TP2.amount) FROM transaction_payments AS TP2 WHERE
                        TP2.transaction_id=SR.id ) as return_paid'),
                DB::raw('COALESCE(SR.final_total, 0) as amount_return')

            )->first();

        return $return_due;
    }

    /**
     * Function to get ledger details
     */
    public function getLedgerDetails($contact_id, $start, $end)
    {
        //Get sum of totals before start date
        $previous_transaction_sums = $this->__transactionQuery($contact_id, $start)
            ->select(
                DB::raw("SUM(IF(type = 'purchase', final_total, 0)) as total_purchase"),
                DB::raw("SUM(IF(type = 'sell' AND status = 'final', final_total, 0)) as total_invoice"),
                DB::raw("SUM(IF(type = 'sell_return', final_total, 0)) as total_sell_return"),
                DB::raw("SUM(IF(type = 'purchase_return', final_total, 0)) as total_purchase_return")
            )->first();

        //Get payment totals before start date
        $prev_payments_sum = $this->__paymentQuery($contact_id, $start)
            ->select(DB::raw('SUM(transaction_payments.amount) as total_paid'))
            ->first();

        $total_prev_invoice = $previous_transaction_sums->total_purchase + $previous_transaction_sums->total_invoice - $previous_transaction_sums->total_sell_return - $previous_transaction_sums->total_purchase_return;
        //$total_prev_paid = $prev_payments_sum->total_paid;
        $beginning_balance = $total_prev_invoice - $prev_payments_sum->total_paid;

        //Get transaction totals between dates
        $transactions = $this->__transactionQuery($contact_id, $start, $end)
            ->with(['location'])->get();
        $transaction_types = Transaction::transactionTypes();
        $ledger = [];

        $opening_balance = 0;
        $opening_balance_paid = 0;

        foreach ($transactions as $transaction) {

            if ($transaction->type == 'opening_balance') {
                //Skip opening balance, it will be added in the end
                $opening_balance += $transaction->final_total;

                continue;
            }

            $ledger[] = [
                'date' => $transaction->transaction_date,
                'ref_no' => in_array($transaction->type, ['sell', 'sell_return']) ? $transaction->invoice_no : $transaction->ref_no,
                'type' => $transaction_types[$transaction->type],
                'location' => $transaction->location->name,
                'payment_status' => __('lang_v1.'.$transaction->payment_status),
                'total' => $transaction->final_total,
                'payment_method' => '',
                'debit' => '',
                'credit' => '',
                'others' => $transaction->additional_notes,
            ];
        }

        $invoice_sum = $transactions->where('type', 'sell')->sum('final_total');
        $purchase_sum = $transactions->where('type', 'purchase')->sum('final_total');
        $sell_return_sum = $transactions->where('type', 'sell_return')->sum('final_total');
        $purchase_return_sum = $transactions->where('type', 'purchase_return')->sum('final_total');
        $advance_payment = 0;

        //Get payment totals between dates
        $payments = $this->__paymentQuery($contact_id, $start, $end)
            ->select('transaction_payments.*', 'bl.name as location_name', 't.type as transaction_type', 't.ref_no', 't.invoice_no')
            ->get();
        $paymentTypes = $this->payment_types(null, true);

        foreach ($payments as $payment) {

            if ($payment->transaction_type == 'opening_balance') {
                $opening_balance_paid += $payment->amount;
            }

            //Hide all the adjusted payments because it has already been summed as advance payment
            if (! empty($payment->parent_id)) {
                continue;
            }

            $ref_no = in_array($payment->transaction_type, ['sell', 'sell_return']) ? $payment->invoice_no : $payment->ref_no;
            $note = $payment->note;
            if (! empty($ref_no)) {
                $note .= '<small>'.__('account.payment_for').': '.$ref_no.'</small>';
            }

            if ($payment->is_advance == 1) {
                $note .= '<small>'.__('lang_v1.advance_payment').'</small>';
                $advance_payment += $payment->amount;
            }
            if ($payment->is_exchange == 1) {
                $note .= '<small> ('.__('lang_v1.exchange').')</small>';
            }

            if ($payment->method == 'advance') {
                $advance_payment += (-1) * ($payment->amount);
            }

            $ledger[] = [
                'date' => $payment->paid_on,
                'ref_no' => $payment->payment_ref_no,
                'type' => $transaction_types['payment'],
                'location' => $payment->location_name,
                'payment_status' => '',
                'total' => '',
                'payment_method' => ! empty($paymentTypes[$payment->method]) ? $paymentTypes[$payment->method] : '',
                'debit' => in_array($payment->transaction_type, ['purchase', 'sell_return']) ? $payment->amount : '',
                'credit' => in_array($payment->transaction_type, ['sell', 'purchase_return', 'opening_balance']) || $payment->is_advance == 1 ? $payment->amount : '',
                'others' => $note,
            ];
        }

        $total_invoice_paid = $payments->where('transaction_type', 'sell')->where('is_return', 0)->sum('amount');
        $total_sell_change_return = $payments->where('transaction_type', 'sell')->where('is_return', 1)->sum('amount');
        $total_sell_change_return = ! empty($total_sell_change_return) ? $total_sell_change_return : 0;
        $total_invoice_paid -= $total_sell_change_return;
        $total_purchase_paid = $payments->where('transaction_type', 'purchase')->where('is_return', 0)->sum('amount');
        $total_sell_return_paid = $payments->where('transaction_type', 'sell_return')->sum('amount');
        $total_purchase_return_paid = $payments->where('transaction_type', 'purchase_return')->sum('amount');

        $start_date = $this->format_date($start);
        $end_date = $this->format_date($end);

        $total_invoice = $invoice_sum - $sell_return_sum;
        $total_purchase = $purchase_sum - $purchase_return_sum;

        $opening_balance_due = $opening_balance - $opening_balance_paid;

        $total_paid = $total_invoice_paid + $total_purchase_paid - $total_sell_return_paid - $total_purchase_return_paid;
        $curr_due = $total_invoice + $total_purchase - $total_paid + $beginning_balance + $opening_balance_due - $advance_payment;

        //Sort by date
        if (! empty($ledger)) {
            usort($ledger, function ($a, $b) {
                $t1 = strtotime($a['date']);
                $t2 = strtotime($b['date']);

                return $t2 - $t1;
            });
        }

        //Add Beginning balance & openining balance to ledger
        $ledger = array_merge([[
            'date' => $start,
            'ref_no' => '',
            'type' => __('lang_v1.opening_balance'),
            'location' => '',
            'payment_status' => '',
            'total' => $beginning_balance + $opening_balance_due,
            'payment_method' => '',
            'debit' => '',
            'credit' => '',
            'others' => '',
        ]], $ledger);

        $output = [
            'ledger' => $ledger,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'total_invoice' => $total_invoice,
            'total_purchase' => $total_purchase,
            'beginning_balance' => $beginning_balance + $opening_balance_due,
            'balance_due' => $curr_due,
            'total_paid' => $total_paid,
        ];

        return $output;
    }

    /**
     * Query to get transaction totals for a customer
     */
    private function __transactionQuery($contact_id, $start, $end = null)
    {
        $business_id = request()->session()->get('user.business_id');
        $transaction_type_keys = array_keys(Transaction::transactionTypes());

        $query = Transaction::where('transactions.contact_id', $contact_id)
            ->where('transactions.business_id', $business_id)
            ->where('status', '!=', 'draft')
            ->whereIn('type', $transaction_type_keys);

        if (! empty($start) && ! empty($end)) {
            $query->whereDate(
                'transactions.transaction_date',
                '>=',
                $start
            )
                ->whereDate('transactions.transaction_date', '<=', $end)->get();
        }

        if (! empty($start) && empty($end)) {
            $query->whereDate('transactions.transaction_date', '<', $start);
        }
        $query->whereNotIn('transactions.status', ['ordered', 'draft', 'pending', 'cancelled']);

        return $query;
    }

    /**
     * Query to get payment details for a customer
     */
    private function __paymentQuery($contact_id, $start, $end = null)
    {
        $business_id = request()->session()->get('user.business_id');

        $query = TransactionPayment::leftJoin(
            'transactions as t',
            'transaction_payments.transaction_id',
            '=',
            't.id'
        )
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->where('transaction_payments.payment_for', $contact_id);
        //->whereNull('transaction_payments.parent_id');

        if (! empty($start) && ! empty($end)) {
            $query->whereDate('paid_on', '>=', $start)
                ->whereDate('paid_on', '<=', $end);
        }

        if (! empty($start) && empty($end)) {
            $query->whereDate('paid_on', '<', $start);
        }

        return $query;
    }

    public function getProfitLossDetails($business_id, $location_id, $start_date, $end_date, $user_id = null)
    {
        //For Opening stock date should be 1 day before
        $day_before_start_date = Carbon::createFromFormat('Y-m-d', $start_date)->subDay()->format('Y-m-d');

        $filters = ['user_id' => $user_id];
        //Get Opening stock
        $opening_stock = $this->getOpeningClosingStock($business_id, $day_before_start_date, $location_id, true, false, $filters);

        $opening_stock_by_sp = $this->getOpeningClosingStock($business_id, $day_before_start_date, $location_id, true, true, $filters);

        //Get Closing stock
        $closing_stock = $this->getOpeningClosingStock(
            $business_id,
            $end_date,
            $location_id,
            false,
            false,
            $filters
        );

        $closing_stock_by_sp = $this->getOpeningClosingStock(
            $business_id,
            $end_date,
            $location_id,
            false,
            true,
            $filters
        );

        //Get Purchase details
        $purchase_details = $this->getPurchaseTotals(
            $business_id,
            $start_date,
            $end_date,
            $location_id,
            $user_id
        );

        //Get Sell details
        $sell_details = $this->getSellTotals(
            $business_id,
            $start_date,
            $end_date,
            $location_id,
            $user_id
        );

        $transaction_types = [
            'purchase_return', 'sell_return', 'expense', 'stock_adjustment', 'sell_transfer', 'purchase', 'sell',
        ];

        $transaction_totals = $this->getTransactionTotals(
            $business_id,
            $transaction_types,
            $start_date,
            $end_date,
            $location_id,
            $user_id
        );

        $gross_profit = $this->getGrossProfit(
            $business_id,
            $start_date,
            $end_date,
            $location_id,
            $user_id
        );
        $data['total_purchase_shipping_charge'] = ! empty($purchase_details['total_shipping_charges']) ? $purchase_details['total_shipping_charges'] : 0;
        $data['total_sell_shipping_charge'] = ! empty($sell_details['total_shipping_charges']) ? $sell_details['total_shipping_charges'] : 0;
        //Shipping
        $data['total_transfer_shipping_charges'] = ! empty($transaction_totals['total_transfer_shipping_charges']) ? $transaction_totals['total_transfer_shipping_charges'] : 0;
        $data['total_sell_return_shipping_cost'] = $transaction_totals['total_return_shipping_charges'];
        //payment fee
        $data['total_return_payment_fee'] = ! empty($transaction_totals['total_return_payment_fee']) ? $transaction_totals['total_return_payment_fee'] : 0;
        $data['total_payment_fee'] = ! empty($transaction_totals['total_payment_fee']) ? $transaction_totals['total_payment_fee'] : 0;
        //? get Total Return Round Off Amount
        $data['total_return_round_off_amount'] = ! empty($transaction_totals['total_return_round_off_amount']) ? $transaction_totals['total_return_round_off_amount'] : 0;
        //Discounts
        $total_purchase_discount = $transaction_totals['total_purchase_discount'];
        $total_sell_discount = $transaction_totals['total_sell_discount'];
        $total_reward_amount = $transaction_totals['total_reward_amount'];
        $total_sell_round_off = $transaction_totals['total_sell_round_off'];

        //Stocks
        $data['opening_stock'] = ! empty($opening_stock) ? $opening_stock : 0;
        $data['closing_stock'] = ! empty($closing_stock) ? $closing_stock : 0;

        $data['opening_stock_by_sp'] = ! empty($opening_stock_by_sp) ? $opening_stock_by_sp : 0;
        $data['closing_stock_by_sp'] = ! empty($closing_stock_by_sp) ? $closing_stock_by_sp : 0;

        //Purchase
        $data['total_purchase'] = ! empty($purchase_details['total_purchase_exc_tax']) ? $purchase_details['total_purchase_exc_tax'] : 0;
        $data['total_purchase_discount'] = ! empty($total_purchase_discount) ? $total_purchase_discount : 0;
        $data['total_purchase_return'] = $transaction_totals['total_purchase_return_exc_tax'];

        //Sales
        $data['total_sell'] = ! empty($sell_details['total_sell_exc_tax']) ? $sell_details['total_sell_exc_tax'] : 0;
        $data['total_sell_discount'] = ! empty($total_sell_discount) ? $total_sell_discount : 0;
        $data['total_sell_return'] = $transaction_totals['total_sell_return_exc_tax'];

        $data['total_sell_round_off'] = ! empty($total_sell_round_off) ? $total_sell_round_off : 0;

        //Expense
        $data['total_expense'] = $transaction_totals['total_expense'];

        //Stock adjustments
        $data['total_adjustment'] = $transaction_totals['total_adjustment'];
        $data['total_recovered'] = $transaction_totals['total_recovered'];

        // $data['closing_stock'] = $data['closing_stock'] - $data['total_adjustment'];

        $data['total_reward_amount'] = ! empty($total_reward_amount) ? $total_reward_amount : 0;

        $moduleUtil = new ModuleUtil();

        $module_parameters = [
            'business_id' => $business_id,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'location_id' => $location_id,
            'user_id' => $user_id,
        ];
        $modules_data = $moduleUtil->getModuleData('profitLossReportData', $module_parameters);

        $data['left_side_module_data'] = [];
        $data['right_side_module_data'] = [];
        $module_total = 0;
        if (! empty($modules_data)) {
            foreach ($modules_data as $module_data) {
                if (! empty($module_data[0])) {
                    foreach ($module_data[0] as $array) {
                        $data['left_side_module_data'][] = $array;
                        if (! empty($array['add_to_net_profit'])) {
                            $module_total -= $array['value'];
                        }
                    }
                }
                if (! empty($module_data[1])) {
                    foreach ($module_data[1] as $array) {
                        $data['right_side_module_data'][] = $array;
                        if (! empty($array['add_to_net_profit'])) {
                            $module_total += $array['value'];
                        }
                    }
                }
            }
        }

        // $data['net_profit'] = $module_total + $data['total_sell']
        //                         + $data['closing_stock']
        //                         - $data['total_purchase']
        //                         - $data['total_sell_discount']
        //                         + $data['total_sell_round_off']
        //                         - $data['total_reward_amount']
        //                         - $data['opening_stock']
        //                         - $data['total_expense']
        //                         + $data['total_recovered']
        //                         - $data['total_transfer_shipping_charges']
        //                         - $data['total_purchase_shipping_charge']
        //                         + $data['total_sell_shipping_charge']
        //                         + $data['total_purchase_discount']
        //                         + $data['total_purchase_return']
        //                         - $data['total_sell_return'];

        $data['net_profit'] = $module_total + $gross_profit
            + ($data['total_sell_round_off'] + $data['total_recovered'] + $data['total_sell_shipping_charge'] + $data['total_purchase_discount']
            ) - ($data['total_reward_amount'] + $data['total_expense'] + $data['total_adjustment'] + $data['total_transfer_shipping_charges'] + $data['total_purchase_shipping_charge'] + $data['total_sell_return_shipping_cost']
            );

        //get gross profit from Project Module
        $module_parameters = [
            'business_id' => $business_id,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'location_id' => $location_id,
        ];
        $project_module_data = $moduleUtil->getModuleData('grossProfit', $module_parameters);

        if (! empty($project_module_data['Project']['gross_profit'])) {
            $gross_profit = $gross_profit + $project_module_data['Project']['gross_profit'];
            $data['gross_profit_label'] = __('project::lang.project_invoice');
        }

        $data['gross_profit'] = $gross_profit;

        //get sub type for total sales
        $sales_by_subtype = Transaction::where('business_id', $business_id)
            ->where('type', 'sell')
            ->where('status', 'final');
        if (! empty($start_date) && ! empty($end_date)) {
            if ($start_date == $end_date) {
                $sales_by_subtype->whereDate('transaction_date', $end_date);
            } else {
                $sales_by_subtype->whereBetween(DB::raw('transaction_date'), [$start_date, $end_date]);
            }
        }
        $sales_by_subtype = $sales_by_subtype->select(DB::raw('SUM(total_before_tax) as total_before_tax'), 'sub_type')
            ->whereNotNull('sub_type')
            ->groupBy('transactions.sub_type')
            ->get();
        $data['total_sell_by_subtype'] = $sales_by_subtype;

        return $data;
    }

    public function quatityAmountFormat($details, $unit_d)
    {

        $products = $details['lines'];
        foreach ($products as $key => $product) {
            foreach ($unit_d as $u => $unit) {
                if ($product['units'] == $unit->short_name) {
                    $allow_decimal[] = $unit->allow_decimal;
                }

            }
        }
        $count = count($details['lines']);
        $number = [];
        for ($i = 0; $i < $count; $i++) {
            $number = $this->num_uf($details['lines'][$i]['quantity']);
            if ($allow_decimal[$i] == 0) {
                $details['lines'][$i]['quantity'] = number_format($number, 0, '.', ',');
            } else {
                continue;
            }

        }

        return $details;
    }

    public function getSaleType($id)
    {

        if ($id > 0) {
            $sale_type = SaleType::select('name')->where('id', $id)->get();

            return $sale_type[0]['name'];
        }

    }

    private function getPromoRedeem($id)
    {
        if (PromoCardRedeemReport::where('transaction_id', $id)->exists()) {
            $promo_redeem = PromoCardRedeemReport::select('discount_rate')->where('transaction_id', $id)->first();

            return $promo_redeem->discount_rate;
        }
    }

    private function getPromoRedeemID($id)
    {
        if (PromoCardRedeemReport::where('transaction_id', $id)->exists()) {
            $promo_redeem = PromoCardRedeemReport::select('promo_code')->where('transaction_id', $id)->first();

            return $promo_redeem->promo_code;
        }
    }

    public function filterFromPurchaseOrer($purchase_transfer, $purchase_lines, $update_purchase_transfer, $update_purchase_lines, $business)
    {
        if (! is_null($purchase_transfer)) {
            $input_data = $purchase_transfer;
        } elseif (! is_null($update_purchase_transfer)) {
            $input_data = $update_purchase_transfer;
        } elseif (! is_null($update_purchase_lines)) {
            $input_data = $update_purchase_lines;
        } else {
            $input_data = $purchase_lines;
        }

        $qty_sum_query = $this->get_pl_quantity_sum_string('PL');
        $output = [];
        // $purchase_line=[];
        foreach ($input_data as $line) {
            $query = Transaction::join('purchase_lines AS PL', 'transactions.id', '=', 'PL.transaction_id')
                ->where('transactions.business_id', $business['id'])
                ->where('transactions.location_id', $business['location_id'])
                ->whereIn('transactions.type', ['purchase', 'purchase_transfer',
                    'opening_stock', 'production_purchase'])
                ->where('transactions.status', 'received')
                ->whereRaw("( $qty_sum_query ) < PL.quantity")
                ->where('PL.product_id', $line['product_id'])
                ->where('PL.variation_id', $line['variation_id']);

            //Sort according to LIFO or FIFO
            if ($business['accounting_method'] == 'lifo') {
                $query = $query->orderBy('transaction_date', 'desc');
            } else {
                $query = $query->orderBy('transaction_date', 'asc');
            }

            $rows = $query->select(
                'PL.id as purchase_lines_id',
                'PL.lot_number as lot_number',
                DB::raw("(PL.quantity - ( $qty_sum_query )) AS quantity_available"),
                'PL.quantity_sold as quantity_sold',
                'PL.quantity_adjusted as quantity_adjusted',
                'PL.quantity_returned as quantity_returned',
                'PL.mfg_quantity_used as mfg_quantity_used',
                'transactions.invoice_no',
                'transactions.contact_id as contact_id'
            )->get();

            $quantity_transfer = $line['quantity'];

            foreach ($rows as $row) {

                $qty_new = 0;
                //If transfer ave lot number qty sould take from purchase from that number
                if (array_key_exists('lot_number', $line)) {

                    if ($row['lot_number'] == $line['lot_number']) {
                        if ($row['quantity_available'] >= $quantity_transfer) {
                            $qty_new = $quantity_transfer;
                            $quantity_transfer = 0;
                        } else {
                            $qty_new = $row['quantity_available'];
                            $quantity_transfer = $quantity_transfer - $row['quantity_available'];
                        }
                    } else {
                        continue;
                    }

                } else {
                    if ($row['quantity_available'] >= $quantity_transfer) {
                        $qty_new = $quantity_transfer;
                        $quantity_transfer = 0;
                    } else {
                        $qty_new = $row['quantity_available'];
                        $quantity_transfer = $quantity_transfer - $row['quantity_available'];
                    }
                }
                if (! is_null($purchase_transfer)) {
                    $output[] = ['location_id' => $line['location_id'],
                        'ref_no' => $line['ref_no'],
                        'transaction_date' => $line['transaction_date'],
                        'additional_notes' => $line['additional_notes'],
                        'shipping_charges' => $line['shipping_charges'],
                        'final_total' => ($this->num_uf($line['unit_price']) * $this->num_uf($qty_new)),
                        'total_before_tax' => ($this->num_uf($line['unit_price']) * $this->num_uf($qty_new)),
                        'type' => $line['type'],
                        'business_id' => $line['business_id'],
                        'created_by' => $line['created_by'],
                        'payment_status' => $line['payment_status'],
                        'status' => $line['status'],
                        'contact_id' => $row['contact_id'],
                        'transfer_parent_id' => $line['transfer_parent_id'],
                    ];
                } elseif (! is_null($update_purchase_transfer)) {
                    $output[] = [
                        'transaction_date' => $line['transaction_date'],
                        'additional_notes' => $line['additional_notes'],
                        'shipping_charges' => $line['shipping_charges'],
                        'final_total' => ($this->num_uf($line['unit_price']) * $this->num_uf($qty_new)),
                        'total_before_tax' => ($this->num_uf($line['unit_price']) * $this->num_uf($qty_new)),
                        'status' => $line['status'],
                    ];
                } elseif (! is_null($update_purchase_lines)) {
                    $output[] = [
                        'quantity' => $this->num_uf($qty_new),
                    ];
                } else {
                    if (array_key_exists('lot_number', $line)) {
                        $output[] = ['product_id' => $line['product_id'],
                            'variation_id' => $line['variation_id'],
                            'quantity' => $qty_new,
                            'item_tax' => $line['item_tax'],
                            'tax_id' => $line['tax_id'],
                            'purchase_price' => ($line['purchase_price']),
                            'purchase_price_inc_tax' => $line['purchase_price_inc_tax'],
                            'lot_number' => $line['lot_number'],
                            'mfg_date' => $line['mfg_date'],
                            'exp_date' => $line['exp_date'],
                            'lot_no_line_id' => $line['lot_no_line_id'],
                        ];
                    } else {
                        $output[] = ['product_id' => $line['product_id'],
                            'variation_id' => $line['variation_id'],
                            'quantity' => $qty_new,
                            'item_tax' => $line['item_tax'],
                            'tax_id' => $line['tax_id'],
                            'purchase_price' => ($line['purchase_price']),
                            'purchase_price_inc_tax' => $line['purchase_price_inc_tax'],
                        ];
                    }
                }

                if ($quantity_transfer == 0) {
                    break;
                }
            }

        }

        return $output;

    }

    public function getGiftCardValue($id)
    {
        if (GiftCard::where('redeem_transaction_id', $id)->exists()) {
            $gift_redeems = GiftCard::join('transaction_sell_lines as tsl', 'tsl.id', '=', 'gift_cards.sold_tsl_id')
                ->select('gift_cards.name as name', 'serial_no', 'profit', 'unit_price_inc_tax', 'value')
                ->where('redeem_transaction_id', $id)->get();
            $gift_cards_value = 0;
            $gift_cards_sold = 0;
            $gift_cards_profit = 0;
            $gift_card_redeems = [];
            $gift_card_redeems['serial_no'] = '';
            $gift_card_redeems['name'] = '';
            foreach ($gift_redeems as $gift_redeem) {
                $gift_cards_value += $gift_redeem->value;
                $gift_cards_sold += $gift_redeem->unit_price_inc_tax;
                $gift_cards_profit += $gift_redeem->profit;
                $gift_card_redeems['name'] .= $gift_redeem->name.',';
                $gift_card_redeems['serial_no'] .= $gift_redeem->serial_no.',';
            }

            $gift_card_redeems['discount'] = $gift_cards_sold - $gift_cards_profit;

            return $gift_card_redeems;
        }
    }

    /**
     * Develop by @KasunRoczDev
     * Date: 2023-02-02
     *
     * @return mixed
     */
    public function getLotNumbersFromVariationOnPurchaseline($variation_id, $purchase_line_id, $business_id, $location_id)
    {
        $query = PurchaseLine::from('purchase_lines as pl')
            ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
            ->where('pl.id', $purchase_line_id)
            ->where('t.business_id', $business_id)
            ->where('t.location_id', $location_id)
            ->where('t.status', 'received')
            ->where('pl.variation_id', $variation_id);

        $purchase_lines = $query->select(
            'pl.id as purchase_line_id',
            'lot_number',
            'pl.exp_date as exp_date',
            'pl.purchase_price',
            DB::raw('SUM((pl.quantity - (pl.quantity_sold + pl.quantity_adjusted + pl.quantity_returned + pl.mfg_quantity_used))) AS qty_available'),
            DB::raw("(SELECT SUM(tsl.quantity) FROM transaction_sell_lines AS tsl INNER JOIN transactions AS t ON tsl.transaction_id = t.id WHERE
        tsl.variation_id = $variation_id AND t.type = 'sell_transfer' AND t.status = 'in_transit' AND t.location_id = $location_id) as flaged_qty"))
            ->latest('pl.id')->first();

        return $purchase_lines;
    }

    public function import_batch_exists($type, $batch_no, $business_id)
    {
        return Transaction::where('business_id', $business_id)
            ->where('type', $type)
            ->where('import_batch', $batch_no)
            ->exists();
    }

    /**
     * @dev Prabhath
     *
     * @return mixed
     */
    protected function calculateFinalTotal($invoice_total, $shipping_cost, $payment_fee, $round_off_amount)
    {
        $invoice_total =
            $invoice_total +
            $this->num_uf($shipping_cost) +
            $this->num_uf($payment_fee) +
            $this->num_uf($round_off_amount);

        return $invoice_total;
    }
}
