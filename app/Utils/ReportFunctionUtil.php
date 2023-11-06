<?php

namespace App\Utils;



class ReportFunctionUtil extends Util
{

    /**
     * @param $row
     * @return string
     */
    function getFullAddress($row): string
    {
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
        return '<div>' . $address_line_1 . '' . $address_line_2 . '' . $city . '' . $state . '' . $country . '' . $zip_code . '</div>';
    }

    /**
     * @param $row
     * @return string
     */
    function invoice_no($row): string
    {
        $invoice_no = $row->invoice_no;
        if ($row->is_cod) {
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
    }
}
