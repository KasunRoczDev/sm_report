<?php

namespace App\Utils;

use App\Models\Discount;
use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\TaxRate;
use App\Models\Variation;
use App\Models\VariationGroupPrice;
use App\Models\VariationLocationDetails;
use App\Models\VariationTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProductUtil extends Util
{
    private BusinessUtil $businessUtil;
    private QueryCommonFormatterUtil $queryCommonFormatterUtil;
    private Util $util;

    public function __construct()
    {
        $this->businessUtil = new \App\Utils\BusinessUtil();
        $this->queryCommonFormatterUtil = new \App\Utils\QueryCommonFormatterUtil();
        $this->util = new \App\Utils\Util();
    }


    /**
     * Get all details for a product from its variation id
     *
     * @param int $variation_id
     * @param int $business_id
     * @param int|null $location_id
     * @param bool $check_qty (If false qty_available is not checked)
     * @return array
     */
    public function getDetailsFromVariation(int $variation_id, int $business_id, int $location_id = null, bool $check_qty = true)
    {
        $query = Variation::join('products AS p', 'variations.product_id', '=', 'p.id')
            ->join('product_variations AS pv', 'variations.product_variation_id', '=', 'pv.id')
            ->leftjoin('variation_location_details AS vld', 'variations.id', '=', 'vld.variation_id')
            ->leftjoin('units', 'p.unit_id', '=', 'units.id')
            ->leftjoin('brands', function ($join) {
                $join->on('p.brand_id', '=', 'brands.id')
                    ->whereNull('brands.deleted_at');
            })
            ->where('p.business_id', $business_id)
            ->where('variations.id', $variation_id);


        //Add condition for check of quantity. (if stock is not enabled or qty_available > 0)
        if ($check_qty) {
            $query->where(function ($query) {
                $query->where('p.enable_stock', '!=', 1)
                    ->orWhere('vld.qty_available', '>', 0);
            });
        }

        if (!empty($location_id) && $check_qty) {
            //Check for enable stock, if enabled check for location id.
            $query->where(function ($query) use ($location_id) {
                $query->where('p.enable_stock', '!=', 1)
                    ->orWhere('vld.location_id', $location_id);
            });
        }

        if ($location_id) {
            $product = $query->select(
                DB::raw("IF(pv.is_dummy = 0, CONCAT(p.name,
                    ' (', pv.name, ':',variations.name, ')'), p.name) AS product_name"),
                'p.id as product_id',
                'p.brand_id',
                'p.category_id',
                'p.tax as tax_id',
                'p.enable_stock',
                'p.enable_sr_no',
                'p.type as product_type',
                'p.name as product_actual_name',
                'p.warranty_id',
                'pv.name as product_variation_name',
                'pv.is_dummy as is_dummy',
                'variations.name as variation_name',
                'variations.sub_sku',
                'p.barcode_type',
                'vld.qty_available',
                'variations.default_purchase_price',
                'variations.default_sell_price',
                'variations.sell_price_inc_tax',
                'variations.id as variation_id',
                'variations.combo_variations',  //Used in combo products
                'variations.minimum_selling_price',
                'units.short_name as unit',
                'units.id as unit_id',
                'units.allow_decimal as unit_allow_decimal',
                'brands.name as brand',
                DB::raw('(SELECT purchase_price_inc_tax FROM purchase_lines WHERE
            variation_id=variations.id ORDER BY id DESC LIMIT 1) as last_purchased_price'),
                DB::raw("(SELECT SUM(tsl.quantity) FROM transaction_sell_lines AS tsl INNER JOIN transactions AS t ON tsl.transaction_id = t.id WHERE
            tsl.variation_id = $variation_id AND t.type = 'sell_transfer' AND t.status = 'in_transit' AND t.location_id = $location_id) as flaged_qty") //location need for in_transit product in POS
            )->first();
        } else { //reason label not print other wise
            $product = $query->select(
                DB::raw("IF(pv.is_dummy = 0, CONCAT(p.name,
                    ' (', pv.name, ':',variations.name, ')'), p.name) AS product_name"),
                'p.id as product_id',
                'p.brand_id',
                'p.category_id',
                'p.tax as tax_id',
                'p.enable_stock',
                'p.enable_sr_no',
                'p.type as product_type',
                'p.name as product_actual_name',
                'p.warranty_id',
                'pv.name as product_variation_name',
                'pv.is_dummy as is_dummy',
                'variations.name as variation_name',
                'variations.sub_sku',
                'p.barcode_type',
                'vld.qty_available',
                'variations.default_purchase_price',
                'variations.default_sell_price',
                'variations.sell_price_inc_tax',
                'variations.id as variation_id',
                'variations.combo_variations',  //Used in combo products
                'units.short_name as unit',
                'units.id as unit_id',
                'units.allow_decimal as unit_allow_decimal',
                'brands.name as brand',
                DB::raw('(SELECT purchase_price_inc_tax FROM purchase_lines WHERE
            variation_id=variations.id ORDER BY id DESC LIMIT 1) as last_purchased_price'),
                DB::raw("(SELECT SUM(tsl.quantity) FROM transaction_sell_lines AS tsl INNER JOIN transactions AS t ON tsl.transaction_id = t.id WHERE
            tsl.variation_id = $variation_id AND t.type = 'sell_transfer' AND t.status = 'in_transit' ) as flaged_qty")
            )->first();
        }

        if ($product->product_type == 'combo') {
            if ($check_qty) {
                if (empty($db_connection)) {
                    $product->qty_available = $this->calculateComboQuantity($location_id, $product->combo_variations);
                }
            }
            $product->combo_products = $this->calculateComboDetails($location_id, $product->combo_variations);

        }

        return $product;
    }

    public function getDetailsFromVariation2($variation_id, $business_id, $location_id = null, $check_qty = true)
    {
        $query = Variation::join('products AS p', 'variations.product_id', '=', 'p.id')
            ->join('product_variations AS pv', 'variations.product_variation_id', '=', 'pv.id')
            ->leftjoin('variation_location_details AS vld', 'variations.id', '=', 'vld.variation_id')
            ->leftjoin('units', 'p.unit_id', '=', 'units.id')
            ->leftjoin('brands', function ($join) {
                $join->on('p.brand_id', '=', 'brands.id')
                    ->whereNull('brands.deleted_at');
            })
            ->where('p.business_id', $business_id)
            ->where('variations.id', $variation_id);

        //Add condition for check of quantity. (if stock is not enabled or qty_available > 0)
        if ($check_qty) {
            $query->where(function ($query) {
                $query->where('p.enable_stock', '!=', 1)
                    ->orWhere('vld.qty_available', '>', 0);
            });
        }

        if (! empty($location_id) && $check_qty) {
            //Check for enable stock, if enabled check for location id.
            $query->where(function ($query) use ($location_id) {
                $query->where('p.enable_stock', '!=', 1)
                    ->orWhere('vld.location_id', $location_id);
            });
        }

        $product = $query->select(
            DB::raw("IF(pv.is_dummy = 0, CONCAT(p.name,
                    ' (', pv.name, ':',variations.name, ')'), p.name) AS product_name"),
            'p.id as product_id',
            'p.brand_id',
            'p.category_id',
            'p.tax as tax_id',
            'p.enable_stock',
            'p.enable_sr_no',
            'p.type as product_type',
            'p.name as product_actual_name',
            'p.warranty_id',
            'pv.name as product_variation_name',
            'pv.is_dummy as is_dummy',
            'variations.name as variation_name',
            'variations.sub_sku',
            'p.barcode_type',
            'vld.qty_available',
            'variations.default_sell_price',
            'variations.sell_price_inc_tax',
            'variations.id as variation_id',
            'variations.combo_variations',  //Used in combo products
            'units.short_name as unit',
            'units.id as unit_id',
            'units.allow_decimal as unit_allow_decimal',
            'brands.name as brand',
            DB::raw('(SELECT purchase_price_inc_tax FROM purchase_lines WHERE
                        variation_id=variations.id ORDER BY id DESC LIMIT 1) as last_purchased_price')
        )->first();

        if ($product->product_type == 'combo') {
            if ($check_qty) {
                $product->qty_available = $this->calculateComboQuantity($location_id, $product->combo_variations);
            }

            $product->combo_products = $this->calculateComboDetails($location_id, $product->combo_variations);
        }

        return $product;
    }

    /**
     * Calculates the quantity of combo products based on
     * the quantity of variation items used.
     *
     * @param  int  $location_id
     * @param  array  $combo_variations
     * @return int
     */
    public function calculateComboQuantity($location_id, $combo_variations, array $db_connection = [])
    {
        if (empty($db_connection)) {
            //get stock of the items and calcuate accordingly.
            $combo_qty = 0;
            foreach ($combo_variations as $key => $value) {
                $vld = VariationLocationDetails::where('variation_id', $value['variation_id'])
                    ->where('location_id', $location_id)
                    ->first();
                $product = Product::find($vld->product_id);

                $variation_qty = ! empty($vld) ? $vld->qty_available : 0;
                $multiplier = $this->getMultiplierOf2Units($product->unit_id, $value['unit_id']);

                if ($key == 0) {
                    $combo_qty = ($variation_qty / $multiplier) / $combo_variations[$key]['quantity'];
                } else {
                    $combo_qty = min($combo_qty, ($variation_qty / $multiplier) / $combo_variations[$key]['quantity']);
                }
            }
        }
        if (! empty($db_connection)) {
            // Perform actions using $db_connection array
            $database = $db_connection['database'];
            $reports_landlord = $db_connection['reports_landlord'];
            $this->util->databaseConnectionConfig($database);

            //get stock of the items and calcuate accordingly.
            $combo_qty = 0;
            foreach ($combo_variations as $key => $value) {
                $vld = VariationLocationDetails::on($reports_landlord)->where('variation_id', $value['variation_id'])
                    ->where('location_id', $location_id)
                    ->first();
                $product = Product::find($vld->product_id);

                $variation_qty = ! empty($vld) ? $vld->qty_available : 0;
                $multiplier = $this->getMultiplierOf2Units($product->unit_id, $value['unit_id']);

                if ($key == 0) {
                    $combo_qty = ($variation_qty / $multiplier) / $combo_variations[$key]['quantity'];
                } else {
                    $combo_qty = min($combo_qty, ($variation_qty / $multiplier) / $combo_variations[$key]['quantity']);
                }
            }
        }

        return floor($combo_qty);
    }

    /**
     * Calculates the quantity of combo products based on
     * the quantity of variation items used.
     *
     * @param  int  $location_id
     * @param  array  $combo_variations
     * @return int
     */
    public function calculateComboDetails($location_id, $combo_variations, array $db_connection = [])
    {

        if (empty($db_connection)) {
            $details = [];
            foreach ($combo_variations as $key => $value) {
                $variation = Variation::with('product')->findOrFail($value['variation_id']);

                $vld = VariationLocationDetails::where('variation_id', $value['variation_id'])
                    ->where('location_id', $location_id)
                    ->first();

                $variation_qty = ! empty($vld) ? $vld->qty_available : 0;
                $multiplier = $this->getMultiplierOf2Units($variation->product->unit_id, $value['unit_id']);

                $details[] = [
                    'variation_id' => $value['variation_id'],
                    'product_id' => $variation->product_id,
                    'qty_required' => $this->num_uf($value['quantity']) * $multiplier,
                ];
            }
        }
        if (! empty($db_connection)) {
            // Perform actions using $db_connection array
            $database = $db_connection['database'];
            $reports_landlord = $db_connection['reports_landlord'];
            $this->util->databaseConnectionConfig($database);

            $details = [];
            foreach ($combo_variations as $key => $value) {
                $variation = Variation::on($reports_landlord)->with('product')->findOrFail($value['variation_id']);

                $vld = VariationLocationDetails::on($reports_landlord)->where('variation_id', $value['variation_id'])
                    ->where('location_id', $location_id)
                    ->first();

                $variation_qty = ! empty($vld) ? $vld->qty_available : 0;
                $multiplier = $this->getMultiplierOf2Units($variation->product->unit_id, $value['unit_id'], [
                    'database' => $db_connection['database'],
                    'reports_landlord' => 'reports_landlord',
                ]);

                $details[] = [
                    'variation_id' => $value['variation_id'],
                    'product_id' => $variation->product_id,
                    'qty_required' => $this->num_uf($value['quantity']) * $multiplier,
                ];
            }
        }

        return $details;
    }

    /**
     * Calculates the total amount of invoice
     *
     * @param  array  $products
     * @param  int  $tax_id
     * @param  array  $discount['discount_type', 'discount_amount']
     * @return mixed (false, array)
     */
    public function calculateInvoiceTotal($products, $tax_id, $discount = null, $uf_number = true)
    {
        if (empty($products)) {
            return false;
        }

        $output = ['total_before_tax' => 0, 'tax' => 0, 'total_line_discount' => 0, 'total_before_line_dis' => 0, 'discount' => 0, 'final_total' => 0, 'payment_fee' => 0];

        //Sub Total
        foreach ($products as $product) {

            $unit_price_inc_tax = $uf_number ? $this->num_uf($product['unit_price_inc_tax']) : $product['unit_price_inc_tax'];
            $quantity = $uf_number ? $this->num_uf($product['quantity']) : $product['quantity'];

            $output['total_before_tax'] += $quantity * $unit_price_inc_tax;

            //Add modifier price to total if exists
            if (! empty($product['modifier_price'])) {
                foreach ($product['modifier_price'] as $key => $modifier_price) {
                    $modifier_price = $uf_number ? $this->num_uf($modifier_price) : $modifier_price;
                    $uf_modifier_price = $this->num_uf($modifier_price);
                    $modifier_qty = isset($product['modifier_quantity'][$key]) ? $product['modifier_quantity'][$key] : 0;
                    $modifier_total = $uf_modifier_price * $modifier_qty;
                    $output['total_before_tax'] += $modifier_total;
                }
            }
        }

        //Calculate discount
        if (is_array($discount)) {
            $discount_amount = $uf_number ? $this->num_uf($discount['discount_amount']) : $discount['discount_amount'];
            if ($discount['discount_type'] == 'fixed') {
                $output['discount'] = $discount_amount;
            } else {
                $output['discount'] = ($discount_amount / 100) * $output['total_before_tax'];
            }
        }

        //Calculate total line discount
        foreach ($products as $product) {
            if (! empty($product['line_discount_amount'])) {
                $line_discount = $uf_number ? $this->num_uf($product['line_discount_amount']) : $product['line_discount_amount'];
                $quantity = $uf_number ? $this->num_uf($product['quantity']) : $product['quantity'];

                if ($product['line_discount_type'] == 'percentage') {
                    $product_unit_price = $uf_number ? $this->num_uf($product['unit_price']) : $product['unit_price'];
                    $line_discount = ($line_discount / 100) * $product_unit_price;
                }
                $output['total_line_discount'] += $quantity * $line_discount;
            }
        }

        //Tax
        $output['tax'] = 0;
        if (! empty($tax_id)) {
            $tax_details = TaxRate::find($tax_id);
            if (! empty($tax_details)) {
                $output['tax_id'] = $tax_id;
                $output['tax'] = ($tax_details->amount / 100) * ($output['total_before_tax'] - $output['discount']);
            }
        }

        //Calculate total
        $output['final_total'] = $output['total_before_tax'] + $output['tax'] - $output['discount'];

        //total before line discount
        $output['total_before_line_dis'] = $output['final_total'] + $output['total_line_discount'];

        return $output;
    }

    /**
     * Gives list of trending products
     *
     * @param  int  $business_id
     * @param  array  $filters
     * @return Obj
     */
    public function getTrendingProducts($business_id, $filters = [])
    {
        $query = Transaction::join(
            'transaction_sell_lines as tsl',
            'transactions.id',
            '=',
            'tsl.transaction_id'
        )
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftjoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('transactions.business_id', $business_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final');

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }
        if (! empty($filters['location_id'])) {
            $query->where('transactions.location_id', $filters['location_id']);
        }
        if (! empty($filters['category'])) {
            $query->where('p.category_id', $filters['category']);
        }
        if (! empty($filters['sub_category'])) {
            $query->where('p.sub_category_id', $filters['sub_category']);
        }
        if (! empty($filters['brand'])) {
            $query->where('p.brand_id', $filters['brand']);
        }
        if (! empty($filters['unit'])) {
            $query->where('p.unit_id', $filters['unit']);
        }
        if (! empty($filters['limit'])) {
            $query->limit($filters['limit']);
        } else {
            $query->limit(5);
        }

        if (! empty($filters['product_type'])) {
            $query->where('p.type', $filters['product_type']);
        }

        if (! empty($filters['start_date']) && ! empty($filters['end_date'])) {
            $query->whereBetween(DB::raw('date(transaction_date)'), [$filters['start_date'],
                $filters['end_date']]);
        }

        // $sell_return_query = "(SELECT SUM(TPL.quantity) FROM transactions AS T JOIN purchase_lines AS TPL ON T.id=TPL.transaction_id WHERE TPL.product_id=tsl.product_id AND T.type='sell_return'";
        // if ($permitted_locations != 'all') {
        //     $sell_return_query .= ' AND T.location_id IN ('
        //      . implode(',', $permitted_locations) . ') ';
        // }
        // if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
        //     $sell_return_query .= ' AND date(T.transaction_date) BETWEEN \'' . $filters['start_date'] . '\' AND \'' . $filters['end_date'] . '\'';
        // }
        // $sell_return_query .= ')';

        $products = $query->select(
            DB::raw('(SUM(tsl.quantity) - COALESCE(SUM(tsl.quantity_returned), 0)) as total_unit_sold'),
            'p.name as product',
            'u.short_name as unit'
        )->whereNull('tsl.parent_sell_line_id')
            ->groupBy('tsl.product_id')
            ->orderBy('total_unit_sold', 'desc')
            ->get();

        return $products;
    }

    /**
     * Gives list of products based on products id and variation id
     *
     * @param  int  $business_id
     * @param  int  $product_id
     * @param  int  $variation_id = null
     * @return Obj
     */
    public function getDetailsFromProduct($business_id, $product_id, $variation_id = null, $conditions = [])
    {
        $conditions = $conditions ?? []; // set default value if $conditions is not set
        $purpose = $conditions['purpose'] ?? 'Default'; // set default value if 'purpose' is not set in $conditions
        $is_not_all = $conditions['is_all_product'] ? 0 : 1;

        $product = Product::leftjoin('variations as v', 'products.id', '=', 'v.product_id')
            ->whereNull('v.deleted_at')
            ->where('products.business_id', $business_id);

        if ($purpose == 'Purchase_Wise_Lot') {
            $product->leftjoin('purchase_lines AS pl', 'v.id', '=', 'pl.variation_id')
                ->leftjoin('lot_number_prices as lt', 'lt.purchase_line_id', '=', 'pl.id')
                ->groupBy('pl.id');
        }
        if ($is_not_all) {

            if (! is_null($variation_id) && $variation_id !== '0') {
                $product->where('v.id', $variation_id);
            }
            $product->where('products.id', $product_id);
        }

        $product->select(
            'products.id as product_id',
            'products.name as product_name',
            'v.id as variation_id',
            'v.name as variation_name'
        );
        if ($purpose == 'Purchase_Wise_Lot') {
            $product
                ->selectRaw('lt.purchase_line_id as purchase_line_id')
                ->selectRaw('lt.lot_number as lot_number')
                ->selectRaw('sum(pl.quantity - (pl.quantity_sold + pl.quantity_returned + pl.quantity_adjusted + pl.mfg_quantity_used)) as quantity')
                ->selectRaw('COALESCE(lt.lot_price, v.default_sell_price) as default_sell_price');
        } else {
            $product->selectRaw('v.default_sell_price as default_sell_price');
        }

        return $product->get();
    }

    /**
     * Get rack details.
     *
     * @param  int  $business_id
     * @param  int  $product_id
     * @return void
     */
    public function getRackDetails($business_id, $product_id, $get_location = false)
    {
        $query = ProductRack::where('product_racks.business_id', $business_id)
            ->where('product_id', $product_id);

        if ($get_location) {
            $racks = $query->join('business_locations AS BL', 'product_racks.location_id', '=', 'BL.id')
                ->select(['product_racks.rack',
                    'product_racks.row',
                    'product_racks.position',
                    'BL.name'])
                ->get();
        } else {
            $racks = collect($query->select(['rack', 'row', 'position', 'location_id'])->get());

            $racks = $racks->mapWithKeys(function ($item, $key) {
                return [$item['location_id'] => $item->toArray()];
            })->toArray();
        }

        return $racks;
    }

    /**
     * Retrieves selling price group price for a product variation.
     *
     * @param  int  $variation_id
     * @param  int  $price_group_id
     * @param  int  $tax_id
     * @return decimal
     */
    public function getVariationGroupPrice($variation_id, $price_group_id, $tax_id)
    {
        $price_inc_tax =
            VariationGroupPrice::where('variation_id', $variation_id)
                ->where('price_group_id', $price_group_id)
                ->value('price_inc_tax');
        $price_exc_tax = $price_inc_tax;
        if (! empty($price_inc_tax) && ! empty($tax_id)) {
            $tax_amount = TaxRate::where('id', $tax_id)->value('amount');
            $price_exc_tax = $this->calc_percentage_base($price_inc_tax, $tax_amount);
        }

        return [
            'price_inc_tax' => $price_inc_tax,
            'price_exc_tax' => $price_exc_tax,
        ];
    }

    /**
     * Retrieves selling price group price for a product variation.
     *
     * @param  int  $variation_id
     * @param  int  $price_group_id
     * @param  int  $tax_id
     * @return decimal
     */
    public function getVariationGroupPriceForJob($variation_id, $price_group_id, $tax_id)
    {
        $price_inc_tax =
            VariationGroupPrice::on('mysql_wo')->where('variation_id', $variation_id)
                ->where('price_group_id', $price_group_id)
                ->value('price_inc_tax');
        $price_exc_tax = $price_inc_tax;
        if (! empty($price_inc_tax) && ! empty($tax_id)) {
            $tax_amount = TaxRate::on('mysql_wo')->where('id', $tax_id)->value('amount');
            $price_exc_tax = $this->calc_percentage_base($price_inc_tax, $tax_amount);
        }

        return [
            'price_inc_tax' => $price_inc_tax,
            'price_exc_tax' => $price_exc_tax,
        ];
    }

    /**
     * Retrieves selling price groups price for a product variation.
     *
     * @param  int  $variation_id
     * @param  int  $tax_id
     * @return decimal
     */
    public function getVariationGroupPrices($variation_id)
    {
        $selling_groups =
            VariationGroupPrice::join('selling_price_groups as spg', 'variation_group_prices.price_group_id', '=', 'spg.id')
                ->where('variation_group_prices.variation_id', $variation_id)
                ->select('spg.name as selling_group', 'variation_group_prices.price_inc_tax as selling_group_price')->get();

        return $selling_groups;
    }

    /**
     * Recalculates purchase line data according to subunit data
     *
     * @param  int  $purchase_line
     * @param  int  $business_id
     * @return array
     */
    public function changePurchaseLineUnit($purchase_line, $business_id)
    {
        $base_unit = $purchase_line->product->unit;
        $sub_units = $base_unit->sub_units;

        $sub_unit_id = $purchase_line->sub_unit_id;

        $sub_unit = $sub_units->filter(function ($item) use ($sub_unit_id) {
            return $item->id == $sub_unit_id;
        })->first();

        if (! empty($sub_unit)) {
            $multiplier = $sub_unit->base_unit_multiplier;
            $purchase_line->quantity = $purchase_line->quantity / $multiplier;
            $purchase_line->pp_without_discount = $purchase_line->pp_without_discount * $multiplier;
            $purchase_line->purchase_price = $purchase_line->purchase_price * $multiplier;
            $purchase_line->purchase_price_inc_tax = $purchase_line->purchase_price_inc_tax * $multiplier;
            $purchase_line->item_tax = $purchase_line->item_tax * $multiplier;
            $purchase_line->quantity_returned = $purchase_line->quantity_returned / $multiplier;
            $purchase_line->quantity_sold = $purchase_line->quantity_sold / $multiplier;
            $purchase_line->quantity_adjusted = $purchase_line->quantity_adjusted / $multiplier;
        }

        //SubUnits
        $purchase_line->sub_units_options = $this->getSubUnits($business_id, $base_unit->id, false, $purchase_line->product_id);

        return $purchase_line;
    }

    /**
     * Recalculates sell line data according to subunit data
     *
     * @param  int  $unit_id
     * @return array
     */
    public function changeSellLineUnit($business_id, $sell_line)
    {
        $unit_details = $this->getSubUnits($business_id, $sell_line->unit_id, false, $sell_line->product_id);

        $sub_unit = null;
        $sub_unit_id = $sell_line->sub_unit_id;
        foreach ($unit_details as $key => $value) {
            if ($key == $sub_unit_id) {
                $sub_unit = $value;
            }
        }

        if (! empty($sub_unit)) {
            $multiplier = $sub_unit['multiplier'];
            $sell_line->quantity_ordered = $sell_line->quantity_ordered / $multiplier;
            $sell_line->item_tax = $sell_line->item_tax * $multiplier;
            $sell_line->default_sell_price = $sell_line->default_sell_price * $multiplier;
            $sell_line->unit_price_before_discount = $sell_line->unit_price_before_discount * $multiplier;
            $sell_line->sell_price_inc_tax = $sell_line->sell_price_inc_tax * $multiplier;
            $sell_line->sub_unit_multiplier = $multiplier;

            $sell_line->unit_details = $unit_details;
        }

        return $sell_line;
    }

    /**
     * Retrieves current stock of a variation for the given location
     *
     * @param  int  $variation_id, int location_id
     * @return float
     */
    public function getCurrentStock($variation_id, $location_id)
    {
        $current_stock = VariationLocationDetails::where('variation_id', $variation_id)
            ->where('location_id', $location_id)
            ->value('qty_available');

        if ($current_stock == null) {
            $current_stock = 0;
        }

        return $current_stock;
    }

    /**
     * Finds out most relevant descount for the product
     *
     * @param  obj  $product, int $business_id, int $location_id, bool $is_cg,
     * bool $is_spg
     * @return obj discount
     */
    public function getProductDiscount($product, $business_id, $location_id, $is_cg = false, $is_spg = false, $variation_id = null, $is_pch = false)
    {

        $now = Carbon::now()->toDateTimeString();

        //Search if variation has discount
        if (! empty($variation_id) && ! $is_pch) {
            $query3 = Discount::where('business_id', $business_id)
                ->where('location_id', $location_id)
                ->where('is_active', 1)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->where('applicable_in_pch_group', 0)
                ->whereHas('variations', function ($q) use ($variation_id) {
                    $q->where('variation_id', $variation_id);
                })
                ->orderBy('priority', 'desc')
                ->latest();
            if ($is_cg) {
                $query3->where('applicable_in_cg', 1);
            }
            if ($is_spg) {
                $query3->where('applicable_in_spg', 1);
            }

            $discount_by_variation = $query3->first();
            if (! empty($discount_by_variation) && ! empty($discount)) {
                $discount = $discount_by_variation->priority >= $discount->priority ? $discount_by_variation : $discount;
            } elseif (empty($discount)) {
                $discount = $discount_by_variation;
            }

        } elseif (! empty($variation_id) && $is_pch) {
            $query3 = Discount::where('business_id', $business_id)
                ->where('location_id', $location_id)
                ->where('is_active', 1)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->where('applicable_in_pch_group', 1)
                ->whereHas('variations', function ($q) use ($variation_id) {
                    $q->where('variation_id', $variation_id);
                })
                ->orderBy('priority', 'desc')
                ->latest();

            $discount_by_variation = $query3->first();
            if (! empty($discount_by_variation) && ! empty($discount)) {
                $discount = $discount_by_variation->priority >= $discount->priority ? $discount_by_variation : $discount;
            } elseif (empty($discount)) {
                $discount = $discount_by_variation;
            }
        }

        if (empty($discount) && ! $is_pch) {

            //Search if both category and brand matches
            if ((! empty($product->brand_id) && ! empty($product->category_id))) {
                $query1 = Discount::where('business_id', $business_id)
                    ->where('location_id', $location_id)
                    ->where('is_active', 1)
                    ->where('starts_at', '<=', $now)
                    ->where('ends_at', '>=', $now)
                    ->where('brand_id', $product->brand_id)
                    ->where('category_id', $product->category_id)
                    ->where('applicable_in_pch_group', 0)
                    ->orderBy('priority', 'desc')
                    ->latest();
                if ($is_cg) {
                    $query1->where('applicable_in_cg', 1);
                }
                if ($is_spg) {
                    $query1->where('applicable_in_spg', 1);
                }
                $discount = $query1->first();
            }
        } elseif (empty($discount) && $is_pch) {

            if ((! empty($product->brand_id) && ! empty($product->category_id))) {
                $query1 = Discount::where('business_id', $business_id)
                    ->where('location_id', $location_id)
                    ->where('is_active', 1)
                    ->where('starts_at', '<=', $now)
                    ->where('ends_at', '>=', $now)
                    ->where('brand_id', $product->brand_id)
                    ->where('category_id', $product->category_id)
                    ->where('applicable_in_pch_group', 1)
                    ->orderBy('priority', 'desc')
                    ->latest();

                $discount = $query1->first();
            }
        }

        //Search if either category or brand matches
        if (empty($discount) && ! $is_pch) {
            //   if(( empty($product->brand_id) && !empty($product->category_id)) ||(!empty($product->brand_id) && empty($product->category_id)) ) {
            $query2 = Discount::where('business_id', $business_id)
                ->where('location_id', $location_id)
                ->where('is_active', 1)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->where('applicable_in_pch_group', 0)
                ->where(function ($q) use ($product) {
                    $q->whereRaw('(brand_id="'.$product->brand_id.'" AND category_id IS NULL)')
                        ->orWhereRaw('(category_id="'.$product->category_id.'" AND brand_id IS NULL)');
                })
                ->orderBy('priority', 'desc');
            if ($is_cg) {
                $query2->where('applicable_in_cg', 1);
            }
            if ($is_spg) {
                $query2->where('applicable_in_spg', 1);
            }

            $discount = $query2->first();
            //      }
        } elseif (empty($discount) && $is_pch) {
            $query2 = Discount::where('business_id', $business_id)
                ->where('location_id', $location_id)
                ->where('is_active', 1)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->where('applicable_in_pch_group', 1)
                ->where(function ($q) use ($product) {
                    $q->whereRaw('(brand_id="'.$product->brand_id.'" AND category_id IS NULL)')
                        ->orWhereRaw('(category_id="'.$product->category_id.'" AND brand_id IS NULL)');
                })
                ->orderBy('priority', 'desc');

            $discount = $query2->first();
            //      }
        }
        //        check discount and if discount variable is not empty (when run previous conditions)
        //        this will check the previous discount or any other discount has high priority
        //        first discount query get the discounts which is not brand,category and product

        if (! empty($discount) && ! $is_pch) {
            $discounts = Discount::where('business_id', $business_id)
                ->where('location_id', $location_id)
                ->where('is_active', 1)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->whereNull('brand_id')
                ->whereNull('category_id')
                ->where('applicable_in_pch_group', 0)
                ->whereNotIn('id', function ($query) {
                    $query->select('discount_id')
                        ->from('discount_variations');
                })
                ->orderBy('priority', 'desc');
            if ($is_cg) {
                $discounts->where('applicable_in_cg', 1);
            }
            if ($is_spg) {
                $discounts->where('applicable_in_spg', 1);
            }
            $discounts = $discounts->first();

            if (empty($discounts)) {
                $discount->formated_starts_at = $this->format_date($discount->starts_at->toDateTimeString(), true);
                $discount->formated_ends_at = $this->format_date($discount->ends_at->toDateTimeString(), true);

            } elseif ($discount->priority > $discounts->priority) {
                $discount->formated_starts_at = $this->format_date($discount->starts_at->toDateTimeString(), true);
                $discount->formated_ends_at = $this->format_date($discount->ends_at->toDateTimeString(), true);

            } elseif ($discounts->priority > $discount->priority) {
                $discount = $discounts;
            }

        } elseif (! empty($discount) && $is_pch) {
            $discounts = Discount::where('business_id', $business_id)
                ->where('location_id', $location_id)
                ->where('is_active', 1)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->whereNull('brand_id')
                ->whereNull('category_id')
                ->where('applicable_in_pch_group', 1)
                ->whereNotIn('id', function ($query) {
                    $query->select('discount_id')
                        ->from('discount_variations');
                })
                ->orderBy('priority', 'desc')
                ->first();

            if (empty($discounts)) {
                $discount->formated_starts_at = $this->format_date($discount->starts_at->toDateTimeString(), true);
                $discount->formated_ends_at = $this->format_date($discount->ends_at->toDateTimeString(), true);

            } elseif ($discount->priority > $discounts->priority) {
                $discount->formated_starts_at = $this->format_date($discount->starts_at->toDateTimeString(), true);
                $discount->formated_ends_at = $this->format_date($discount->ends_at->toDateTimeString(), true);

            } elseif ($discounts->priority > $discount->priority) {
                $discount = $discounts;
            }
        }
        // check the discount has only location
        if (empty($discount) && ! empty($location_id) && ! $is_pch) {

            $discounts = Discount::where('business_id', $business_id)
                ->where('location_id', $location_id)
                ->where('is_active', 1)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->where('applicable_in_pch_group', 0)
                ->whereNull('brand_id')
                ->whereNull('category_id')
                ->whereNotIn('id', function ($query) {
                    $query->select('discount_id')
                        ->from('discount_variations');
                })
                ->orderBy('priority', 'desc');
            if ($is_cg) {
                $discounts->where('applicable_in_cg', 1);
            }
            if ($is_spg) {
                $discounts->where('applicable_in_spg', 1);
            }
            $discount = $discounts->first();

        } elseif (empty($discount) && ! empty($location_id) && $is_pch) {

            $discount = Discount::where('business_id', $business_id)
                ->where('location_id', $location_id)
                ->where('is_active', 1)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now)
                ->where('applicable_in_pch_group', 1)
                ->whereNull('brand_id')
                ->whereNull('category_id')
                ->whereNotIn('id', function ($query) {
                    $query->select('discount_id')
                        ->from('discount_variations');
                })
                ->orderBy('priority', 'desc')
                ->first();
        }

        return $discount;

    }

    /**
     * Filters product as per the given inputs and return the details.
     *
     * @param  null  $location_id
     * @param  null  $not_for_selling
     * @param  null  $price_group_id
     * @param  array  $product_types
     * @param  array  $search_fields
     * @param  bool  $check_qty
     * @param  string  $search_type (like or exact)
     * @param  bool  $show_only_not_for_selling
     * @param  bool  $is_add_ingredients
     * @return object
     */
    public function filterProduct($business_id, $search_term, $location_id = null, $not_for_selling = null, $price_group_id = null, $product_types = [], $search_fields = [], $check_qty = false, $search_type = 'like', $show_only_not_for_selling = false, $is_add_ingredients = false)
    {

        $query = Product::join('variations', 'products.id', '=', 'variations.product_id')
            ->active()
            ->whereNull('variations.deleted_at')
            ->leftjoin('units as U', 'products.unit_id', '=', 'U.id')
            ->leftjoin('brands as b', 'products.brand_id', '=', 'b.id')
            ->leftjoin(
                'variation_location_details AS VLD',
                function ($join) use ($location_id) {
                    $join->on('variations.id', '=', 'VLD.variation_id');

                    //Include Location
                    if (! empty($location_id)) {
                        $join->where(function ($query) use ($location_id) {
                            $query->where('VLD.location_id', '=', $location_id);
                            //Check null to show products even if no quantity is available in a location.
                            //TODO: Maybe add a settings to show product not available at a location or not.
                            $query->orWhereNull('VLD.location_id');
                        });

                    }
                }
            );

        if (! is_null($not_for_selling)) {
            $query->where('products.not_for_selling', $not_for_selling);
        }

        if ($show_only_not_for_selling == true && $is_add_ingredients == 1) {
            $query->where('products.not_for_selling', 1);
        }

        if (! empty($price_group_id)) {
            $query->leftjoin(
                'variation_group_prices AS VGP',
                function ($join) use ($price_group_id) {
                    $join->on('variations.id', '=', 'VGP.variation_id')
                        ->where('VGP.price_group_id', '=', $price_group_id);
                }
            );
        }

        $query->where('products.business_id', $business_id)
            ->where('products.type', '!=', 'modifier');

        if (! empty($product_types)) {
            $query->whereIn('products.type', $product_types);
        }

        if (in_array('lot', $search_fields)) {
            $query->leftjoin('purchase_lines as pl', 'variations.id', '=', 'pl.variation_id');
        }

        //Include search
        if (! empty($search_term)) {

            //Search with like condition
            if ($search_type == 'like') {
                $query->where(function ($query) use ($search_term, $search_fields) {

                    if (in_array('name', $search_fields)) {
                        $query->where('products.name', 'like', '%'.$search_term.'%');
                    }

                    if (in_array('sku', $search_fields)) {
                        $query->orWhere('sku', 'like', '%'.$search_term.'%');
                    }

                    if (in_array('sub_sku', $search_fields)) {
                        $variation_search_term = explode('-', $search_term);
                        if (! empty($variation_search_term[1])) {
                            $query->orWhere('sub_sku', $search_term);
                        } else {
                            $query->orWhere('sub_sku', 'like', '%'.$search_term.'%');
                        }
                    }

                    if (in_array('lot', $search_fields)) {
                        $query->orWhere('pl.lot_number', 'like', '%'.$search_term.'%');
                    }

                    $query->orWhere('b.name', 'like', '%'.$search_term.'%');

                    if (in_array('custom', $search_fields)) {
                        $query->orWhere('products.product_custom_field1', 'like', '%'.$search_term.'%')
                            ->orWhere('products.product_custom_field2', 'like', '%'.$search_term.'%')
                            ->orWhere('products.product_custom_field3', 'like', '%'.$search_term.'%')
                            ->orWhere('products.product_custom_field4', 'like', '%'.$search_term.'%');

                    }

                });
            }

            //Search with exact condition
            if ($search_type == 'exact') {
                $query->where(function ($query) use ($search_term, $search_fields) {

                    if (in_array('name', $search_fields)) {
                        $query->where('products.name', $search_term);
                    }

                    if (in_array('sku', $search_fields)) {
                        $query->orWhere('sku', $search_term);
                    }

                    if (in_array('sub_sku', $search_fields)) {
                        $query->orWhere('sub_sku', $search_term);
                    }

                    if (in_array('lot', $search_fields)) {
                        $query->orWhere('pl.lot_number', $search_term);
                    }

                });
            }

        }

        //Include check for quantity
        if ($check_qty) {
            $query->where('VLD.qty_available', '>', 0);
        }

        if (! empty($location_id)) {
            $query->ForLocation($location_id);
        }

        $query->select(
            'products.id as product_id',
            'products.name',
            'products.type',
            'products.enable_stock',
            'variations.id as variation_id',
            'variations.name as variation',
            'VLD.qty_available',
            'variations.sell_price_inc_tax as selling_price',
            'variations.sub_sku',
            'U.short_name as unit',
            'b.name as brand_name'
        );

        if (! empty($price_group_id)) {
            $query->addSelect('VGP.price_inc_tax as variation_group_price');
        }

        if (in_array('lot', $search_fields)) {
            $query->addSelect('pl.id as purchase_line_id', 'pl.lot_number', 'pl.quantity');
            //            $lot = $query->get()->toArray();
            //            if(isset($lot[0]['lot_number'])) {
            //                if ($lot[0]['lot_number'] == $search_term) {
            //                    $query->addSelect(
            //                        DB::raw('(pl.quantity - pl.quantity_sold) as final_quantity')
            //                    );
            //                } else {
            //                    $query->addSelect(
            //                        DB::raw('pl.quantity as final_quantity')
            //                    );
            //                }
            //            }else{
            //                $query->addSelect(
            //                    DB::raw('pl.quantity as final_quantity')
            //                );
            //            }
        }

        $query->groupBy('variations.id');

        return $query->orderBy('VLD.qty_available', 'desc')
            ->get();
    }

    public function getProductStockDetails($business_id, $filters, $for)
    {
        $query = Variation::join('products as p', 'p.id', '=', 'variations.product_id')
            ->join('units', 'p.unit_id', '=', 'units.id')
            ->leftjoin('repair_device_models as rdm', 'rdm.id', '=', 'p.repair_model_id')
            ->leftjoin('variation_location_details as vld', 'variations.id', '=', 'vld.variation_id')
            ->leftjoin('business_locations as l', 'vld.location_id', '=', 'l.id')
            ->join('product_variations as pv', 'variations.product_variation_id', '=', 'pv.id')
            ->where('p.business_id', $business_id)
            ->whereIn('p.type', ['single', 'variable']);

        $permitted_locations = auth()->user()->permitted_locations();
        $location_filter = '';

        if ($permitted_locations != 'all') {
            $query->whereIn('vld.location_id', $permitted_locations);

            $locations_imploded = implode(', ', $permitted_locations);
            $location_filter .= "AND transactions.location_id IN ($locations_imploded) ";
        }

        if (! empty($filters['variation_id'])) {
            $query->where('vld.variation_id', $filters['variation_id']);
        }

        if (! empty($filters['location_id'])) {
            $location_id = $filters['location_id'];

            $query->where('vld.location_id', $location_id);

            $location_filter .= "AND transactions.location_id=$location_id";

            //If filter by location then hide products not available in that location
            $query->join('product_locations as pl', 'pl.product_id', '=', 'p.id')
                ->where(function ($q) use ($location_id) {
                    $q->where('pl.location_id', $location_id);
                });
        }

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

        if (! empty($filters['tax_id'])) {
            $query->where('p.tax', $filters['tax_id']);
        }

        if (! empty($filters['type'])) {
            $query->where('p.type', $filters['type']);
        }

        if (isset($filters['only_mfg_products']) && $filters['only_mfg_products'] == 1) {
            $query->join('mfg_recipes as mr', 'mr.variation_id', '=', 'variations.id');
        }

        if (isset($filters['active_state']) && $filters['active_state'] == 'active') {
            $query->where('p.is_inactive', 0);
        }
        if (isset($filters['active_state']) && $filters['active_state'] == 'inactive') {
            $query->where('p.is_inactive', 1);
        }
        if (isset($filters['not_for_selling']) && $filters['not_for_selling'] == 1) {
            $query->where('p.not_for_selling', 1);
        }

        if (! empty($filters['repair_model_id'])) {
            $query->where('p.repair_model_id', request()->get('repair_model_id'));
        }

        //TODO::Check if result is correct after changing LEFT JOIN to INNER JOIN
        $pl_query_string = $this->get_pl_quantity_sum_string('pl');

        if ($for == 'view_product' && ! empty(request()->input('product_id'))) {
            $location_filter = 'AND transactions.location_id=l.id';
        }

        $products = $query->select(
            // DB::raw("(SELECT SUM(quantity) FROM transaction_sell_lines LEFT JOIN transactions ON transaction_sell_lines.transaction_id=transactions.id WHERE transactions.status='final' $location_filter AND
            //     transaction_sell_lines.product_id=products.id) as total_sold"),

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

        if (isset($filters['show_manufacturing_data']) && $filters['show_manufacturing_data']) {
            $pl_query_string = $this->get_pl_quantity_sum_string('PL');
            $products->addSelect(
                DB::raw("(SELECT COALESCE(SUM(PL.quantity - ($pl_query_string)), 0) FROM transactions
                    JOIN purchase_lines AS PL ON transactions.id=PL.transaction_id
                    WHERE transactions.status='received' AND transactions.type='production_purchase' AND transactions.location_id=vld.location_id
                    AND (PL.variation_id=variations.id)) as total_mfg_stock")
            );
        }

        if (! empty($filters['product_id'])) {
            $products->where('p.id', $filters['product_id'])
                ->groupBy('l.id');
        }

        if ($for == 'view_product') {
            return $products->get();
        } elseif ($for == 'api') {
            return $products->paginate();
        } else {
            return $products;
        }
    }

    public function getProductStockDetailsPos($business_id, $filters, $for)
    {
        $query = Variation::join('products as p', 'p.id', '=', 'variations.product_id')
            ->join('units', 'p.unit_id', '=', 'units.id')
            ->join('variation_location_details as vld', 'variations.id', '=', 'vld.variation_id')
            ->join('business_locations as l', 'vld.location_id', '=', 'l.id')
            ->join('product_variations as pv', 'variations.product_variation_id', '=', 'pv.id')
            ->where('p.business_id', $business_id)
            ->whereIn('p.type', ['single', 'variable']);

        $permitted_locations = auth()->user()->permitted_locations();
        $location_filter = '';

        if ($permitted_locations != 'all' && ! auth()->user()->can('product.pos_stock_report')) {
            $query->whereIn('vld.location_id', $permitted_locations);

            $locations_imploded = implode(', ', $permitted_locations);
            $location_filter .= "AND transactions.location_id IN ($locations_imploded) ";
        }

        //TODO::Check if result is correct after changing LEFT JOIN to INNER JOIN
        $pl_query_string = $this->get_pl_quantity_sum_string('pl');

        if ($for == 'view_product' && ! empty(request()->input('product_id'))) {
            $location_filter = 'AND transactions.location_id=l.id';
        }

        $products = $query->select(
            DB::raw('SUM(vld.qty_available) as stock'),
            'variations.sub_sku as sku',
            'p.name as product',
            'p.type',
            'p.id as product_id',
            'units.short_name as unit',
            'p.enable_stock as enable_stock',
            'variations.sell_price_inc_tax as unit_price',
            'pv.name as product_variation',
            'variations.name as variation_name',
            'l.name as location_name'
        )->groupBy('variations.id', 'vld.location_id');

        if (isset($filters['show_manufacturing_data']) && $filters['show_manufacturing_data']) {
            $pl_query_string = $this->get_pl_quantity_sum_string('PL');
            $products->addSelect(
                DB::raw("(SELECT COALESCE(SUM(PL.quantity - ($pl_query_string)), 0) FROM transactions
                    JOIN purchase_lines AS PL ON transactions.id=PL.transaction_id
                    WHERE transactions.status='received' AND transactions.type='production_purchase' AND transactions.location_id=vld.location_id
                    AND (PL.variation_id=variations.id)) as total_mfg_stock")
            );
        }

        if ($for == 'view_product') {
            return $products->get();
        } elseif ($for == 'api') {
            return $products->paginate();
        } else {
            return $products;
        }
    }

    /**
     * Gives the details of combo product
     *
     * @param  array  $combo_variations
     * @param  int  $business_id
     * @return array
     */
    public function __getComboProductDetails($combo_variations, $business_id)
    {
        foreach ($combo_variations as $key => $value) {
            $combo_variations[$key]['variation'] =
                Variation::with(['product'])
                    ->find($value['variation_id']);

            $combo_variations[$key]['sub_units'] = $this->getSubUnits($business_id, $combo_variations[$key]['variation']['product']->unit_id, true);

            $combo_variations[$key]['multiplier'] = 1;

            if (! empty($combo_variations[$key]['sub_units'])) {
                if (isset($combo_variations[$key]['sub_units'][$combo_variations[$key]['unit_id']])) {
                    $combo_variations[$key]['multiplier'] = $combo_variations[$key]['sub_units'][$combo_variations[$key]['unit_id']]['multiplier'];
                    $combo_variations[$key]['unit_name'] = $combo_variations[$key]['sub_units'][$combo_variations[$key]['unit_id']]['name'];
                }
            }
        }

        return $combo_variations;
    }

    /**
     * Gives the details of combo product
     *
     * @param  array  $combo_variations
     * @param  int  $business_id
     * @return array
     */
    public function __getGroupProductDetails($grouped_products, $business_id)
    {
        foreach ($grouped_products as $key => $value) {
            $grouped_products[$key]['variation'] =
                Variation::with(['product'])
                    ->find($value['variation_id']);

        }

        return $grouped_products;
    }

    public function getVariationStockDetails($business_id, $variation_id, $location_id): array
    {
        $purchase_details = Variation::join('products as p', 'p.id', '=', 'variations.product_id')
            ->join('units', 'p.unit_id', '=', 'units.id')
            ->leftjoin('product_variations as pv', 'variations.product_variation_id', '=', 'pv.id')
            ->leftjoin('purchase_lines as pl', 'pl.variation_id', '=', 'variations.id')
            ->leftjoin('transactions as t', 'pl.transaction_id', '=', 't.id')
            ->where('t.location_id', $location_id)
            ->where('t.status', 'received')
            ->where('p.business_id', $business_id)
            ->where('variations.id', $variation_id)
            ->select(
                DB::raw("SUM(IF(t.type='purchase', pl.quantity, 0)) as total_purchase"),
                DB::raw("SUM(IF(t.type='production_purchase', pl.quantity, 0)) as total_mfg"),
                DB::raw("(SELECT SUM(pl.quantity_returned) FROM purchase_lines pl INNER JOIN transactions AS t ON pl.transaction_id = t.id
                 WHERE pl.variation_id=$variation_id AND t.location_id = $location_id) as total_purchase_return"),
                DB::raw('SUM(pl.quantity_adjusted) as total_adjusted'),
                DB::raw("SUM(IF(t.type='opening_stock', pl.quantity, 0)) as total_opening_stock"),
                DB::raw("SUM(IF(t.type='purchase_transfer', pl.quantity, 0)) as total_purchase_transfer"),
                DB::raw("(SELECT SUM(pl.quantity) FROM purchase_lines AS pl INNER JOIN transactions AS t ON pl.transaction_id = t.id WHERE
                pl.variation_id = $variation_id AND t.type = 'purchase_transfer' AND t.status = 'in_transit' AND t.location_id = $location_id) as flaged_qty_in"),
                'variations.sub_sku as sub_sku',
                'p.name as product',
                'p.type',
                'p.sku',
                'p.id as product_id',
                'units.short_name as unit',
                'pv.name as product_variation',
                'variations.name as variation_name',
                'variations.id as variation_id',
                't.type as purchase_type'
            )
            ->get()->first();

        $sell_details = Variation::join('products as p', 'p.id', '=', 'variations.product_id')
            ->leftjoin('transaction_sell_lines as sl', 'sl.variation_id', '=', 'variations.id')
            ->join('transactions as t', 'sl.transaction_id', '=', 't.id')
            ->where('t.location_id', $location_id)
            ->where('t.status', 'final')
            ->where('p.business_id', $business_id)
            ->where('variations.id', $variation_id)
            ->select(
                DB::raw("SUM(IF(t.type='sell', sl.quantity, 0)) as total_sold"),
                DB::raw("SUM(IF(t.type='sell', sl.quantity_returned, 0)) as total_sell_return"),
                DB::raw("SUM(IF(t.type='sell_transfer', sl.quantity, 0)) as total_sell_transfer"),
                DB::raw("SUM(IF(t.type='production_sell', sl.quantity, 0)) as total_mfg_used"),
                DB::raw("(SELECT SUM(tsl.quantity) FROM transaction_sell_lines AS tsl INNER JOIN transactions AS t ON tsl.transaction_id = t.id WHERE
                tsl.variation_id = $variation_id AND t.type = 'sell_transfer' AND t.status = 'in_transit' AND t.location_id= $location_id) as flaged_qty_out")
            )
            ->get()->first();

        $current_stock = VariationLocationDetails::where('variation_id',
            $variation_id)
            ->where('location_id', $location_id)
            ->first();

        if ($purchase_details->type == 'variable') {
            $product_name = $purchase_details->product.' - '.$purchase_details->product_variation.' - '.$purchase_details->variation_name.' ('.$purchase_details->sub_sku.')';
        } else {
            $product_name = $purchase_details->product.' ('.$purchase_details->sku.')';
        }

        $output = [
            'variation' => $product_name,
            'unit' => $purchase_details->unit,
            'total_purchase' => $purchase_details->total_purchase,
            'total_purchase_return' => $purchase_details->total_purchase_return,
            'total_adjusted' => $purchase_details->total_adjusted,
            'total_opening_stock' => $purchase_details->total_opening_stock,
            'total_purchase_transfer' => $purchase_details->total_purchase_transfer,
            'total_sold' => $sell_details->total_sold,
            'total_sell_return' => $sell_details->total_sell_return,
            'total_sell_transfer' => $sell_details->total_sell_transfer,
            'current_stock' => $current_stock->qty_available ?? 0,
            'flaged_out' => $sell_details->flaged_qty_out,
            'flaged_in' => $purchase_details->flaged_qty_in,
            'mfg_used' => $sell_details->total_mfg_used,
            'mfg_in' => $purchase_details->total_mfg,
        ];

        return $output;
    }

    public function getVariationStockHistory($business_id, $variation_id, $location_id): array
    {
        $stock_history = Transaction::leftjoin('transaction_sell_lines as sl',
            'sl.transaction_id', '=', 'transactions.id')
            ->leftjoin('purchase_lines as pl',
                'pl.transaction_id', '=', 'transactions.id')
            ->leftjoin('stock_adjustment_lines as al',
                'al.transaction_id', '=', 'transactions.id')
            ->leftjoin('transactions as return', 'transactions.return_parent_id', '=', 'return.id')
            ->leftjoin('purchase_lines as rpl',
                'rpl.transaction_id', '=', 'return.id')
            ->leftjoin('transaction_sell_lines as rsl',
                'rsl.transaction_id', '=', 'return.id')
            ->where('transactions.location_id', $location_id)
            ->where(function ($q) use ($variation_id) {
                $q->where('sl.variation_id', $variation_id)
                    ->orWhere('pl.variation_id', $variation_id)
                    ->orWhere('al.variation_id', $variation_id)
                    ->orWhere('rpl.variation_id', $variation_id)
                    ->orWhere('rsl.variation_id', $variation_id);
            })
            ->whereIn('transactions.type', ['sell', 'purchase', 'stock_adjustment', 'opening_stock', 'sell_transfer', 'purchase_transfer', 'production_purchase', 'production_sell', 'purchase_return', 'sell_return'])
            ->where(function ($q) {
                $q->whereNotIn('transactions.status', ['pending', 'cancelled'])->orwherenull('transactions.status');
            })
            ->select(
                'transactions.id as transaction_id',
                'transactions.type as transaction_type',
                DB::raw('SUM(sl.quantity) as sell_line_quantity'),
                DB::raw('SUM(pl.quantity) as purchase_line_quantity'),
                DB::raw('SUM(rsl.quantity_returned) as sell_return'),
                //? todo: check this is ok. if get a bug in live check with git history https://github.com/Storemate/storemate_v3/pull/1407/files
                // ? this on working fine when return same product different line (lot) in purchase. check the PR for sample
                DB::raw('SUM(rpl.quantity_returned) as purchase_return'),
                DB::raw("COALESCE((SELECT SUM(drpl.quantity_returned) FROM purchase_lines as drpl
                WHERE drpl.transaction_id = transactions.id AND drpl.variation_id = $variation_id
                GROUP BY drpl.transaction_id)) as purchase_return_direct"),
                DB::raw('SUM(al.quantity) as stock_adjusted'),
                'transactions.return_parent_id',
                'transactions.transaction_date',
                'transactions.status',
                'transactions.invoice_no',
                'transactions.ref_no',
                DB::raw('SUM(sl.unit_price_before_discount) as sell_price'),
                DB::raw('SUM(pl.purchase_price) as purchase_price'),
                DB::raw('SUM(al.unit_price) as stock_adj_unit_price'),
                DB::raw('SUM(rsl.unit_price) as return_sell_unit_price'),
                DB::raw('SUM(rpl.purchase_price) as return_purchase_unit_price1'),
                DB::raw("COALESCE((SELECT drpl.purchase_price FROM purchase_lines as drpl
                WHERE drpl.transaction_id = transactions.id AND drpl.variation_id = $variation_id
                GROUP BY drpl.transaction_id)) as return_purchase_unit_price2"),
                DB::raw('IF(pl.quantity_returned > 0,pl.purchase_price,rpl.purchase_price) as return_purchase_unit_price')
            )
            ->orderBy('transactions.transaction_date', 'asc')
            ->groupBy('transactions.id', 'sl.id', 'pl.id', 'rsl.id', 'rpl.id', 'al.id')
            ->get();

        $stock_history_array = [];
        $stock = 0;
        $business_details = $this->businessUtil->getDetails($business_id);

        foreach ($stock_history as $stock_line) {

            if ($stock_line->transaction_type == 'sell') {
                if ($stock_line->status != 'final') {
                    continue;
                }
                $quantity_change = -1 * $stock_line->sell_line_quantity;
                $stock += $quantity_change;
                $stock_history_array[] = [
                    'date' => $stock_line->transaction_date,
                    'quantity_change' => $quantity_change,
                    'stock' => $stock,
                    'type' => 'sell',
                    'type_label' => __('sale.sale'),
                    'ref_no' => $stock_line->invoice_no,
                    'transaction_id' => $stock_line->transaction_id,
                    'sell_price' => $this->num_f($stock_line->sell_price, true, $business_details),
                ];
            } elseif ($stock_line->transaction_type == 'purchase') {
                if ($stock_line->status != 'received') {
                    continue;
                }
                $quantity_change = $stock_line->purchase_line_quantity;
                $stock += $quantity_change;
                $stock_history_array[] = [
                    'date' => $stock_line->transaction_date,
                    'quantity_change' => $quantity_change,
                    'stock' => $stock,
                    'type' => 'purchase',
                    'type_label' => __('lang_v1.purchase'),
                    'ref_no' => $stock_line->ref_no,
                    'transaction_id' => $stock_line->transaction_id,
                    'sell_price' => (auth()->user()->can('purchase.view'))
                        ? $this->num_f($stock_line->purchase_price, true, $business_details)
                        : '-',
                ];
            } elseif ($stock_line->transaction_type == 'stock_adjustment') {
                $quantity_change = -1 * $stock_line->stock_adjusted;
                $stock += $quantity_change;
                $stock_history_array[] = [
                    'date' => $stock_line->transaction_date,
                    'quantity_change' => $quantity_change,
                    'stock' => $stock,
                    'type' => 'stock_adjustment',
                    'type_label' => __('stock_adjustment.stock_adjustment'),
                    'ref_no' => $stock_line->ref_no,
                    'transaction_id' => $stock_line->transaction_id,
                    'sell_price' => $this->num_f($stock_line->stock_adj_unit_price, true, $business_details),
                ];
            } elseif ($stock_line->transaction_type == 'opening_stock') {
                $quantity_change = $stock_line->purchase_line_quantity;
                $stock += $quantity_change;
                $stock_history_array[] = [
                    'date' => $stock_line->transaction_date,
                    'quantity_change' => $quantity_change,
                    'stock' => $stock,
                    'type' => 'opening_stock',
                    'type_label' => __('report.opening_stock'),
                    'ref_no' => $stock_line->ref_no ?? '',
                    'transaction_id' => $stock_line->transaction_id,
                    'sell_price' => (auth()->user()->can('purchase.view'))
                        ? $this->num_f($stock_line->purchase_price, true, $business_details)
                        : '-',
                ];
            } elseif ($stock_line->transaction_type == 'sell_transfer') {
                $quantity_change = -1 * $stock_line->sell_line_quantity;
                $stock += $quantity_change;
                if ($stock_line->status == 'in_transit') {
                    $stock_history_array_type_lable = __('lang_v1.stock_transfers').' (  In Transit '.__('lang_v1.out').')';
                } else {
                    $stock_history_array_type_lable = __('lang_v1.stock_transfers').' ('.__('lang_v1.out').')';
                }
                $stock_history_array[] = [
                    'date' => $stock_line->transaction_date,
                    'quantity_change' => $quantity_change,
                    'stock' => $stock,
                    'type' => 'sell_transfer',
                    'type_label' => $stock_history_array_type_lable,
                    'ref_no' => $stock_line->ref_no,
                    'transaction_id' => $stock_line->transaction_id,
                    'sell_price' => $this->num_f($stock_line->sell_price, true, $business_details),
                ];
            } elseif ($stock_line->transaction_type == 'purchase_transfer') {
                $quantity_change = $stock_line->purchase_line_quantity;
                $stock += $quantity_change;
                if ($stock_line->status == 'in_transit') {
                    $stock_history_array_type_lable = __('lang_v1.stock_transfers').' (  In Transit  '.__('lang_v1.in').')';
                } else {
                    $stock_history_array_type_lable = __('lang_v1.stock_transfers').' ('.__('lang_v1.in').')';
                }
                $stock_history_array[] = [
                    'date' => $stock_line->transaction_date,
                    'quantity_change' => $quantity_change,
                    'stock' => $stock,
                    'type' => 'purchase_transfer',
                    'type_label' => $stock_history_array_type_lable,
                    'ref_no' => $stock_line->ref_no,
                    'transaction_id' => $stock_line->transaction_id,
                    'sell_price' => (auth()->user()->can('purchase.view'))
                        ? $this->num_f($stock_line->purchase_price, true, $business_details)
                        : '-',
                ];
            } elseif ($stock_line->transaction_type == 'production_purchase') {
                $quantity_change = $stock_line->purchase_line_quantity;
                $stock += $quantity_change;
                $stock_history_array[] = [
                    'date' => $stock_line->transaction_date,
                    'quantity_change' => $quantity_change,
                    'stock' => $stock,
                    'type' => 'production_purchase',
                    'type_label' => __('manufacturing::lang.manufactured'),
                    'ref_no' => $stock_line->ref_no,
                    'transaction_id' => $stock_line->transaction_id,
                    'sell_price' => (auth()->user()->can('purchase.view'))
                        ? $this->num_f($stock_line->purchase_price, true, $business_details)
                        : '-',
                ];
            } elseif ($stock_line->transaction_type == 'production_sell') {
                $quantity_change = -1 * $stock_line->sell_line_quantity;
                $stock += $quantity_change;
                $stock_history_array[] = [
                    'date' => $stock_line->transaction_date,
                    'quantity_change' => $quantity_change,
                    'stock' => $stock,
                    'type' => 'production_sell',
                    'type_label' => __('manufacturing::lang.manufactured_used'),
                    'ref_no' => $stock_line->ref_no,
                    'transaction_id' => $stock_line->transaction_id,
                    'sell_price' => $this->num_f($stock_line->sell_price, true, $business_details),
                ];
            } elseif ($stock_line->transaction_type == 'purchase_return') {
                $change_stock = ($stock_line->purchase_return == null) ? $stock_line->purchase_return_direct : $stock_line->purchase_return;
                $quantity_change = -1 * $change_stock;
                $stock += $quantity_change;
                $stock_history_array[] = [
                    'date' => $stock_line->transaction_date,
                    'quantity_change' => $quantity_change,
                    'stock' => $stock,
                    'type' => 'purchase_return',
                    'type_label' => __('lang_v1.purchase_return'),
                    'ref_no' => $stock_line->ref_no,
                    'transaction_id' => $stock_line->transaction_id,
                    'sell_price' => (auth()->user()->can('purchase.view'))
                        ? ($stock_line->purchase_return == null)
                            ? $this->num_f($stock_line->return_purchase_unit_price2, true, $business_details)
                            : $this->num_f($stock_line->return_purchase_unit_price1, true, $business_details)
                        : '-',
                ];
            } elseif ($stock_line->transaction_type == 'sell_return') {
                $quantity_change = $stock_line->sell_return;
                $stock += $quantity_change;
                $stock_history_array[] = [
                    'date' => $stock_line->transaction_date,
                    'quantity_change' => $quantity_change,
                    'stock' => $stock,
                    'type' => 'purchase_transfer',
                    'type_label' => __('lang_v1.sell_return'),
                    'ref_no' => $stock_line->invoice_no,
                    'transaction_id' => $stock_line->transaction_id,
                    'sell_price' => (auth()->user()->can('purchase.view'))
                        ? $this->num_f($stock_line->return_sell_unit_price, true, $business_details)
                        : '-',
                ];
            }
        }

        return array_reverse($stock_history_array);
    }

    public function ProductMultiVariationsValue($variation_value_templates_id)
    {
        $business_id = request()->session()->get('user.business_id');
        try {
            $variation_ids = [];
            $variations = VariationTemplate::join('variation_value_templates', 'variation_templates.id', '=', 'variation_value_templates.variation_template_id')
                ->where('variation_templates.business_id', $business_id)
                ->where('variation_value_templates.id', $variation_value_templates_id)
                ->first();
            preg_match_all('!\d+!', $variations->multi_variation_values, $matches);
            foreach ($matches[0] as $key => $value) {
                $variation_ids[$key] = (int) $value;
            }
            $parent_variations = VariationTemplate::join('variation_value_templates', 'variation_templates.id', '=', 'variation_value_templates.variation_template_id')
                ->whereIn('variation_value_templates.id', $variation_ids)
                ->where('variation_templates.business_id', $business_id)
                ->select('variation_templates.woocommerce_attr_id', 'variation_value_templates.name')
                ->get()->toArray();

            return $parent_variations;

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'msg' => __('messages.something_went_wrong'),
            ]);
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
        }
    }

    public function ProductMultiVariationsValueForJob($variation_value_templates_id, $business_id): \Illuminate\Http\JsonResponse|array
    {
        try {
            $variation_ids = [];
            $variations = VariationTemplate::on('mysql_wo')->join('variation_value_templates', 'variation_templates.id', '=', 'variation_value_templates.variation_template_id')
                ->where('variation_templates.business_id', $business_id)
                ->where('variation_value_templates.id', $variation_value_templates_id)
                ->first();
            preg_match_all('!\d+!', $variations->multi_variation_values, $matches);
            foreach ($matches[0] as $key => $value) {
                $variation_ids[$key] = (int) $value;
            }
            $parent_variations = VariationTemplate::on('mysql_wo')->join('variation_value_templates', 'variation_templates.id', '=', 'variation_value_templates.variation_template_id')
                ->whereIn('variation_value_templates.id', $variation_ids)
                ->where('variation_templates.business_id', $business_id)
                ->select('variation_templates.woocommerce_attr_id', 'variation_value_templates.name')
                ->get()->toArray();

            return $parent_variations;

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'msg' => __('messages.something_went_wrong'),
            ]);
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
        }
    }

    /**
     * Developed By KasunRoczDev
     *
     * @param $lot_enabled
     * @return mixed
     */
    public function getProductVariationDetailsOfPurchasedStock(
        $variation_id,
        $location_id,
        $account_method,
        $check_product_has_available_lot_stock = false
    ): mixed
    {
        $query = VariationLocationDetails::from('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('product_variations AS pv', 'v.product_variation_id', '=', 'pv.id')
            ->join('units', 'p.unit_id', '=', 'units.id')
            ->join('purchase_lines as pl', function ($join) {
                $join->on('pl.variation_id', '=', 'vld.variation_id')
                    ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
                    ->where('t.status', 'received')
                    ->whereRaw('t.location_id = vld.location_id');
            })
            ->where('p.business_id', auth()->user()->business_id)
            ->where('vld.variation_id', $variation_id)
            ->where('vld.location_id', $location_id);

        if ($check_product_has_available_lot_stock) {
            $query->where(function ($query) {
                $query->where('pl.lot_number', '!=', null)
                    ->orWhere('pl.exp_date', '!=', null);
            });
            $query->whereRaw('(pl.quantity-(pl.quantity_sold+pl.quantity_adjusted+pl.quantity_returned+pl.mfg_quantity_used))>0');
        }

        if ($account_method == 'lifo') {
            $query->orderBy('pl.id', 'desc');
        } else {
            $query->orderBy('pl.id', 'asc');
        }

        return $query->select(
            DB::raw("IF(pv.is_dummy = 0, CONCAT(p.name,
                    ' (', pv.name, ':',v.name, ')'), p.name) AS product_name"),
            'p.id as product_id',
            'p.tax as tax_id',
            'p.enable_stock',
            'p.enable_sr_no',
            'p.type as product_type',
            'p.name as product_actual_name',
            'pv.name as product_variation_name',
            'pv.is_dummy as is_dummy',
            'v.name as variation_name',
            'v.sub_sku',
            'vld.qty_available',
            'v.default_purchase_price',
            'v.default_sell_price',
            'v.sell_price_inc_tax',
            'v.id as variation_id',
            'v.combo_variations',  //Used in combo products
            'units.short_name as unit',
            'units.id as unit_id',
            'units.allow_decimal as unit_allow_decimal',
            'pl.purchase_price_inc_tax as last_purchased_price',
            DB::raw('(pl.quantity-(pl.quantity_sold+pl.quantity_adjusted+pl.quantity_returned+pl.mfg_quantity_used)) as qty_available_lot')
        )->first();
    }

    /**
     * Check whether product is in the combo product
     */
    public function checkIsComboItem($business_id, $product_id): bool
    {
        $variation_ids = Variation::join('products as p', 'p.id', '=', 'variations.product_id')
            ->where('p.business_id', $business_id)
            ->where('p.id', $product_id)
            ->pluck('variations.id')
            ->toarray();

        $is_combo_item = false;
        foreach ($variation_ids as $variation_id) {
            $variation_value = '%"variation_id":"'.$variation_id.'"%';
            $is_combo_item = Variation::where('combo_variations', 'like', '%'.$variation_value.'')->exists();
            if ($is_combo_item) {
                break;
            }
        }

        return $is_combo_item;
    }

    public function checkProductHasAvailableLotStock($variation_id, $location_id)
    {
        return PurchaseLine::join('transactions as t', 't.id', '=', 'purchase_lines.transaction_id')
            ->where('variation_id', $variation_id)
            ->where('location_id', $location_id)
            ->where(function ($query) {
                $query->where('lot_number', '!=', null)
                    ->orWhere('exp_date', '!=', null);
            })
            ->havingRaw('purchase_lines.quantity-(purchase_lines.quantity_sold+purchase_lines.quantity_adjusted+purchase_lines.quantity_returned+purchase_lines.mfg_quantity_used) > 0')
            ->exists();
    }

    public function getSupplierWiseAlertReport($business_id, $location, $variation_id)
    {

        return VariationLocationDetails::from('variation_location_details as vld')
            ->join('variations as v', 'vld.variation_id', '=', 'v.id')
            ->join('products as products', 'v.product_id', '=', 'products.id')
            ->leftjoin('categories as c', 'products.category_id', '=', 'c.id')
            ->leftjoin('categories as sc', 'products.sub_category_id', '=', 'sc.id')
            ->join('units as u', 'products.unit_id', '=', 'u.id')
            ->join('business_locations as bl', 'vld.location_id', '=', 'bl.id')
            ->where('products.business_id', $business_id)
            ->where('products.enable_stock', 1)
            ->where('products.is_inactive', 0)
            ->havingRaw('current_stock <= alert_quantity')
            ->havingRaw('alert_quantity > 0')
            ->havingRaw('total_pl_avl_stock = current_stock')
            ->havingRaw('supplier_stock IS NOT NULL')
            ->when($location, function ($query, $location) {
                return $query->where('vld.location_id', $location);
            })
            ->when($variation_id, function ($query, $variation_id) {
                return $query->where('vld.variation_id', $variation_id);
            })
            ->select(
                'products.name as product',
                'v.name as variation',
                'v.sub_sku as sku',
                'u.short_name as unit',
                'products.alert_quantity as alert_quantity',
                'vld.qty_available as current_stock',
                'bl.name as location',
                DB::raw("
                COALESCE((
                SELECT
                    GROUP_CONCAT(temp.supplier,' - ',temp.stock SEPARATOR '|') as supplier_stock
                FROM (
                    SELECT
                        IF(t.contact_id IS NOT NULL,c.name,'Opening Stock') AS supplier,
                        ".$this->queryCommonFormatterUtil->sql_float_value_round($this->queryCommonFormatterUtil->purchase_lines_available_qty('pl',
                    true), 2).' AS stock
                    FROM purchase_lines pl
                    '.$this->queryCommonFormatterUtil->transactionJoinPurchaseLines('t', 'pl').'
                    '.$this->queryCommonFormatterUtil->contactsJoinTransactions('c', 't', 'left')."
                    WHERE t.location_id = vld.location_id
                    AND t.status = 'received'
                    AND pl.variation_id=vld.variation_id
                    GROUP BY pl.variation_id,t.contact_id
                ) temp
                )) as supplier_stock"),
                DB::raw(
                    'COALESCE((
                        SELECT
                            '.$this->queryCommonFormatterUtil->sql_float_value_round($this->queryCommonFormatterUtil->purchase_lines_available_qty('pl',
                        true), 2).' AS pl_total_avl_stock
                        FROM purchase_lines pl
                        '.$this->queryCommonFormatterUtil->transactionJoinPurchaseLines('t', 'pl')."
                        WHERE t.location_id = vld.location_id
                        AND t.status = 'received'
                        AND pl.variation_id=vld.variation_id
                        GROUP BY pl.variation_id)) as total_pl_avl_stock"
                ),
                'c.name as category',
                'sc.name as sub_category'
            )
            ->groupBy('vld.id')
            ->get();
    }
}
