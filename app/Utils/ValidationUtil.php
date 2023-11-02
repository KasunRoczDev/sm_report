<?php

namespace App\Utils;

use App\AdminApproveActions;
use App\Product;
use App\PurchaseLine;
use App\TransactionSellLine;
use App\Variation;
use Exception;
use Illuminate\Support\Facades\DB;

class ValidationUtil
{
    /**
     * Checks if the product is available in the business
     *
     * @param  int  $product_id
     * @param  int  $business_id
     * @return bool
     */
    public function __construct(ProductUtil $productUtil)
    {
        $this->productUtil = $productUtil;
    }

    public function sellLineLotQtyValidation($products, $from_st_transfer = 0)
    {
        if (session()->get('business.enable_lot_number') == 1) {
            $lot_line_qty_msg = [];
            foreach ($products as $k => $p) {
                if (empty($p['lot_no_line_id'])) {
                    unset($products[$k]);
                } else {
                    $check_lot = PurchaseLine::where('id', $p['lot_no_line_id'])->select('lot_number')->first();
                    if (empty($check_lot)) {
                        unset($products[$k]);
                    }
                }
            }

            foreach ($products as $check_lot_line) {
                if (! empty($check_lot_line['lot_no_line_id'])) {
                    $quantity = str_replace(',', '', $check_lot_line['quantity']);
                    $base_multipler = ($from_st_transfer) ? 1 : str_replace(',', '.', $check_lot_line['base_unit_multiplier']);
                    $qty = $quantity * $base_multipler;
                    if (! empty($lot_line_qty_msg[$check_lot_line['lot_no_line_id']])) {
                        $qty += $lot_line_qty_msg[$check_lot_line['lot_no_line_id']]['qty'];
                    }
                    $lot_line_qty_msg[$check_lot_line['lot_no_line_id']] = ['qty' => $qty];
                }
            }

            if (! empty($lot_line_qty_msg)) {
                foreach ($lot_line_qty_msg as $line_id => $line_qty) {
                    $pl_qty = PurchaseLine::where('id', $line_id)
                        ->select(
                            DB::raw('SUM(quantity-(quantity_sold+quantity_returned+quantity_adjusted+mfg_quantity_used)) as available_quantity')
                        )->first()->available_quantity;
                    $sale_pl_qty = $line_qty['qty'];
                    if ($pl_qty < $sale_pl_qty) {
                        $pl_details = PurchaseLine::find($line_id);
                        $product_name = Product::find($pl_details['product_id'])->name;
                        $variation_name = Variation::find($pl_details['variation_id'])->name;
                        $product_name .= ($variation_name == 'DUMMY') ? '' : ' '.$variation_name.'  ';
                        $error_msg = $product_name.'  Sufficient Quantities are not available in the selected LOT. Please Select another Lot';
                        throw new Exception($error_msg, 5211);
                    }
                }
            }
        }
    }

    public function minimum_selling_price($products, $transaction_id, $business_id, $business_details, $location_id, $pos_settings)
    {
        if (auth()->user()->can('sell.minimu_price_override')) {
            return true;
        }
        $type = ! empty($pos_settings['type_selling_price_validate']) ? $pos_settings['type_selling_price_validate'] : null;
        if (($type != 'approval') && ($type != 'block')) {
            return true;
        }
        if ($transaction_id) {
            foreach ($products as $key => $product) {
                $old_price_approved = AdminApproveActions::where('transaction_id', $transaction_id)
                    ->whereVariationId($product['variation_id'])->whereStatus('approved');
                if ($old_price_approved->exists()) {
                    $old_approved_selling_price = $old_price_approved->select(DB::raw('JSON_EXTRACT(request_note, "$.product_actual_sell_price") as selling_price'))
                        ->first()->selling_price;
                    if ($old_approved_selling_price <= $product['unit_price_inc_tax']) {
                        unset($products[$key]);
                    }
                }
            }
        }

        foreach ($products as $product) {
            $current_details = $this->productUtil->getDetailsFromVariation($product['variation_id'], $business_id, $location_id);
            $this->check_minimum_price($product, $pos_settings, $current_details, $business_details);
        }

    }

    public function check_minimum_price($product, $pos_settings, $current_details, $business_details)
    {
        $unit_price_inc_tax = str_replace(',', '', $product['unit_price_inc_tax']);
        if (! empty($pos_settings['option_under_minimum_selling_price_approvel'])) {
            $option = $pos_settings['option_under_minimum_selling_price_approvel'];
            if ($option == 1) {
                if ($current_details->default_sell_price > $unit_price_inc_tax) {
                    $error_msg = 'The selling price is less than the minimum selling price. Please change the selling price.';
                    throw new Exception($error_msg, 5211);
                }
            } elseif ($option == 2) {
                $lowest_profit_margin = ! empty($business_details->lowest_profit_margin) ? $business_details->lowest_profit_margin : 0;
                $lot_line_id = ! empty($product['transaction_sell_lines_id'])
                    ? TransactionSellLine::where('id', $product['transaction_sell_lines_id'])->first()->lot_no_line_id
                    : (! empty($product['lot_no_line_id'])
                        ? $product['lot_no_line_id']
                        : null);

                if (! empty($lot_line_id)) {
                    $product_cost = PurchaseLine::where('id', $lot_line_id)->first()->purchase_price;
                    $product_lowest_sell_price = ($product_cost) * ((100 + $lowest_profit_margin) / 100);
                } else {
                    $product_lowest_sell_price = ($current_details->default_purchase_price) * ((100 + $lowest_profit_margin) / 100);
                }

                if ($product_lowest_sell_price > $unit_price_inc_tax) {
                    $error_msg = 'The selling price is less than the minimum selling price. Please change the selling price.';
                    throw new Exception($error_msg, 5211);
                }
            } elseif ($option == 3) {
                if (! empty($current_details->minimum_selling_price) ? $unit_price_inc_tax < $current_details->minimum_selling_price : false) {
                    $error_msg = 'The selling price is less than the minimum selling price. Please change the selling price.';
                    throw new Exception($error_msg, 5211);
                }
            }
        }
    }

    public function validateSaleNotOnAdminRequestPending($id)
    {
        $pending_request_exists = AdminApproveActions::where('transaction_id', $id)->where('status', '!=', 'approved')
            ->whereNotNull('variation_id')->exists();

        if ($pending_request_exists) {
            if (auth()->user()->can('approve.admin.request')) {
                throw new Exception('Your request cannot be processed due to the PENDING SELLING PRICE APPROVAL. Please approve the selling price request to process.', 5211);
            }
            throw new Exception('Cannot add the payment because there are some items in the invoice with under prices that admin has not approved. ', 5211);
        }
    }
}
