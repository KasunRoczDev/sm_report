<?php

namespace App\Utils;

use App\PurchaseLine;

class InventoryUtil extends Util
{
    public function __construct()
    {

    }

    public function productReformatOnAccountMethod(?array $products, $location_id)
    {
        $account_method = (Auth()->user()->business->accounting_method == 'fifo') ? 'ASC' : 'DESC';
        $final_total = 0;
        $formatted_products = [];
        foreach ($products as $p => $product) {
            $pl_wise_avl_qty = $this->getVariationPLQtyAvl($product['variation_id'],
                $product['lot_no_line_id'] ?? null, $location_id, $account_method);
            $format_under_price = $this->variationFormatUnderPl($pl_wise_avl_qty, $product['quantity']);

            if (! empty($product['lot_no_line_id'])) {
                $unit_price = $pl_wise_avl_qty[0]->purchase_price_inc_tax;
                $total_price = $unit_price * $product['quantity'];
                $formatted_products[] = [
                    'product_id' => $product['product_id'],
                    'variation_id' => $product['variation_id'],
                    'enable_stock' => $product['enable_stock'],
                    'quantity' => $product['quantity'],
                    'unit_price' => $unit_price,
                    'price' => $total_price,
                    'lot_no_line_id' => $product['lot_no_line_id'],
                ];
                $final_total += $total_price;
            } else {
                foreach ($format_under_price as $fup) {
                    $formatted_products[] = [
                        'product_id' => $product['product_id'],
                        'variation_id' => $product['variation_id'],
                        'enable_stock' => $product['enable_stock'],
                        'quantity' => $fup['quantity'],
                        'unit_price' => $fup['unit_price'],
                        'price' => $fup['price'],
                    ];
                    $final_total += $fup['price'];
                }
            }
        }

        return [
            'products' => $formatted_products,
            'final_total' => $final_total,
        ];

    }

    public function getVariationPLQtyAvl($variation_id, $purchase_line_id, $location_id, $account_method)
    {
        return PurchaseLine::getVariationAvlQtyPlWise($variation_id)
            ->when(! empty($purchase_line_id), function ($q) use ($purchase_line_id) {
                $q->where('purchase_lines.id', $purchase_line_id);
            })
            ->join('transactions as t', function ($join) use ($location_id) {
                $join->where('t.location_id', $location_id)
                    ->on('purchase_lines.transaction_id', '=', 't.id');
            })
            ->OrderBy('purchase_lines.id', $account_method)->get();
    }

    private function variationFormatUnderPl($pl_wise_avl_qty, $quantity): array
    {
        $output = [];
        foreach ($pl_wise_avl_qty as $k => $line_qty) {
            if (($line_qty->qty_avl >= $quantity) && $quantity != 0) {
                $output[$k] = [
                    'quantity' => $quantity,
                    'unit_price' => $line_qty->purchase_price_inc_tax,
                    'price' => filter_var($quantity, FILTER_SANITIZE_NUMBER_FLOAT,
                        FILTER_FLAG_ALLOW_FRACTION) * filter_var($line_qty->purchase_price_inc_tax,
                            FILTER_SANITIZE_NUMBER_FLOAT,
                            FILTER_FLAG_ALLOW_FRACTION),
                ];
                $quantity = 0;
            } elseif ($quantity != 0) {
                $output[$k] = [
                    'quantity' => $line_qty->qty_avl,
                    'unit_price' => $line_qty->purchase_price_inc_tax,
                    'price' => filter_var($line_qty->qty_avl, FILTER_SANITIZE_NUMBER_FLOAT,
                        FILTER_FLAG_ALLOW_FRACTION) * filter_var($line_qty->purchase_price_inc_tax,
                            FILTER_SANITIZE_NUMBER_FLOAT,
                            FILTER_FLAG_ALLOW_FRACTION),
                ];
                $quantity = $quantity - $line_qty->qty_avl;
            }
        }

        return $output;
    }

    public function productTotalPrice($input)
    {
        $account_method = (Auth()->user()->business->accounting_method == 'fifo') ? 'ASC' : 'DESC';
        $pl_wise_avl_qty = $this->getVariationPLQtyAvl($input['variation_id'], $input['purchase_line_id'] ?? null,
            $input['location_id'], $account_method);
        $format_under_price = $this->variationFormatUnderPl($pl_wise_avl_qty, $input['quantity']);
        $total_price = 0;
        foreach ($format_under_price as $fup) {
            $total_price += $fup['price'];
        }

        return $total_price;
    }
}
