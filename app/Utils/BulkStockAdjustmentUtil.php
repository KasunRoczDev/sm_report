<?php

namespace App\Utils;

use App\Import\Exel\BulkStockAdjustmentImport;
use App\PhysiclStockCount;
use App\Product;
use App\Rules\CurrentStockRule;
use App\Rules\LotNoExistsRule;
use App\Rules\StockDiffRule;
use Exception;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class BulkStockAdjustmentUtil extends Util implements WithHeadingRow
{
    public function __construct(
        ModuleUtil $moduleUtil,
        TransactionUtil $transactionUtil,
        ProductUtil $productUtil
    ) {
        $this->moduleUtil = $moduleUtil;
        $this->transactionUtil = $transactionUtil;
        $this->productUtil = $productUtil;
    }

    public function getImportedBulkStockAdjustments($type = 'summary', $batch_number = null)
    {
        $query = PhysiclStockCount::join('business_locations', 'business_locations.id', '=',
            'physicl_stock_counts.business_location_id')
            ->join('users as u', 'u.id', '=', 'physicl_stock_counts.user_id')
            ->leftjoin('transactions as t', 't.import_batch', '=', 'batch_number')
            ->where('physicl_stock_counts.business_id', $this->business_id());

        if (! empty($batch_number)) {
            $query = $query->where('batch_number', $batch_number);
        }

        if ($type == 'summary') {
            $query = $this->selectImportedBulkStockAdjustmentsSummary($query);
        } else {
            if ($type == 'details' && ! empty($batch_number)) {
                $query = $this->selectImportedBulkStockAdjustmentDetails($query);
            } elseif ($type == 'product-details' && ! empty($batch_number)) {
                $query = $this->selectImportedBulkStockAdjustmentProductDetails($query);
            }
        }

        return $query->get();
    }

    private function selectImportedBulkStockAdjustmentsSummary($query)
    {
        $query->select('physicl_stock_counts.created_at',
            'physicl_stock_counts.business_location_id',
            'business_locations.name as location_name',
            'u.first_name as uname',
            'batch_number as batch_number',
            't.id as transaction_id'
        )
            ->groupby('batch_number');

        return $query;
    }

    private function selectImportedBulkStockAdjustmentDetails($query)
    {
        $query->join('variations as v', 'v.id', '=', 'physicl_stock_counts.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('units as unit', 'unit.id', '=', 'p.unit_id')
            ->leftjoin('purchase_lines as pl', 'pl.id', '=', 'physicl_stock_counts.lot_no_line_id')
            ->where('physicl_stock_counts.business_id', $this->business_id())
            ->select('v.sub_sku as sku',
                DB::raw("IF(v.name = 'DUMMY', p.name, CONCAT(p.name,'-',v.name)) as product_name"),
                'business_locations.name as location_name', 'current_stock',
                'business_locations.id as location_id',
                'unit.short_name as unit',
                'unit.allow_decimal as allow_decimal',
                'physicl_stock_counts.lot_no_line_id as lot_no_line_id',
                'physical_Count', 'lot_number', 'v.id as variation_id')
            ->groupby('physicl_stock_counts.id');

        return $query;
    }

    private function selectImportedBulkStockAdjustmentProductDetails($query)
    {
        $query->join('variations as v', 'v.id', '=', 'physicl_stock_counts.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('units as unit', 'unit.id', '=', 'p.unit_id')
            ->where('physicl_stock_counts.business_id', $this->business_id())
            ->select(
                'physicl_stock_counts.id as psc_id',
                'current_stock',
                'physicl_stock_counts.business_location_id as location_id',
                'physical_Count',
                'lot_no_line_id',
                'unit.short_name as unit',
                'unit.allow_decimal as allow_decimal',
                'physicl_stock_counts.variation_id as variation_id');

        return $query;
    }

    public function bulkAdjustmentExelExport($request)
    {
        $export_data = $this->exelExportStructure($request);
        if (ob_get_contents()) {
            ob_end_clean();
        }
        ob_start();

        return collect($export_data)->downloadExcel(
            'Physical_Product_Stock.xlsx',
            null,
            true
        );
    }

    public function exelExportStructure($request): array
    {
        $system_stock = $this->getSystemCurrentStockOnLocation($request);
        $export_data = [];
        foreach ($system_stock as $product) {
            $temp = [];
            $temp[bulk_stock_adjustment_exel_columns['sku']] = $product->sub_sku;
            $temp[bulk_stock_adjustment_exel_columns['product_name']] = $product->product_name;
            $temp[bulk_stock_adjustment_exel_columns['location']] = $product->location;
            $temp[bulk_stock_adjustment_exel_columns['unit']] = $product->unit;
            $temp[bulk_stock_adjustment_exel_columns['current_stock']] = $product->current_stock;
            $temp[bulk_stock_adjustment_exel_columns['physical_stock']] = '';
            $temp[bulk_stock_adjustment_exel_columns['lot_no']] = '';
            $export_data[] = $temp;
        }

        return $export_data;
    }

    public function getSystemCurrentStockOnLocation($request)
    {
        $location_id = $request->input('location_id');

        return Product::join('variations as v', 'v.product_id', '=', 'products.id')
            ->join('variation_location_details as vld', 'vld.variation_id', '=', 'v.id')
            ->join('units', 'products.unit_id', '=', 'units.id')
            ->join('business_locations as bl', 'bl.id', '=', 'vld.location_id')
            ->where('products.business_id', $this->business_id())
            ->where('vld.location_id', $location_id)
            ->where('vld.qty_available', '>', 0)
            ->select('sub_sku', 'products.name as product_name', 'vld.qty_available as current_stock',
                'units.short_name as unit', 'bl.name as location')
            ->get();
    }

    public function bulkAdjustmentExelImportValidation($request): string
    {
        if (! $request->hasFile('Physical_Product_Stock')) {
            throw new Exception('Import Exel file does not exist,please upload it');
        }
        $file = $request->file('Physical_Product_Stock');
        $ref = $this->moduleUtil->setAndGetReferenceCount('physical_count_import');
        (new BulkStockAdjustmentImport($ref))->import($file);

        return $ref;
    }

    public function getStockAdjustmentRows($batch_no): string
    {
        $physical_stock_products_details = $this->getImportedBulkStockAdjustments('details', $batch_no);
        $physical_stock_products_rows = '';
        foreach ($physical_stock_products_details as $key => $physical_stock_products_detail) {
            $row_index = $key;

            $account_method = request()->session()->get('business.accounting_method');

            $check_lot = ! empty($physical_stock_products_detail->lot_no_line_id) ? true : false;

            $product = $this->productUtil->getProductVariationDetailsOfPurchasedStock($physical_stock_products_detail->variation_id,
                $physical_stock_products_detail->location_id, $account_method, $check_lot);
            $product->formatted_qty_available = $this->productUtil->num_f($product->qty_available);
            // Get lot number dropdown if enabled
            $lot_number = [];
            if (! empty($check_lot)) {
                $lot_number_obj = $this->transactionUtil->getLotNumbersFromVariationOnPurchaseline(
                    $physical_stock_products_detail->variation_id,
                    $physical_stock_products_detail->lot_no_line_id,
                    $this->business_id(), $physical_stock_products_detail->location_id
                );
                $lot_number_obj->qty_formated = $this->productUtil->num_f($lot_number_obj->qty_available);
                $lot_number[] = $lot_number_obj;

                $product->lot_numbers = $lot_number;
                $product->formatted_qty_available = $this->productUtil->num_f($lot_number_obj->qty_available);
                $qty_def = $lot_number_obj->qty_available - $lot_number_obj->flaged_qty - $physical_stock_products_detail->physical_Count;
            } else {
                $qty_def = $physical_stock_products_detail->current_stock - $physical_stock_products_detail->physical_Count;
            }
            $physical_stock_products_rows .= view('stock_adjustment.partials.bulk_stock_adjustment_row')
                ->with(compact('product', 'row_index', 'qty_def'));
        }

        return $physical_stock_products_rows;
    }

    /**
     * @throws Exception
     */
    public function validateImportedBulkStockAdjustments($id): array
    {
        $physical_stock_products_details = $this->getImportedBulkStockAdjustments('product-details', $id);
        $unvalidated_products = [];
        $physical_count = true;
        foreach ($physical_stock_products_details as $index => $detail) {
            $current_stock = (new CurrentStockRule())->passes('current-stock', $detail->current_stock,
                $detail->variation_id, $detail->location_id, $index);

            if (request()->session()->get('business.remove_minus_qty')) {
                $physical_count = (new StockDiffRule())->passes('stock-diff-rule', $detail->current_stock,
                    $detail->physical_Count, $index);
            }
            if (! $current_stock || ! $physical_count) {
                $unvalidated_products[] = $detail->psc_id;
            }

        }

        return $unvalidated_products;
    }

    public function imported_product_details($batch_no, $location_id, $unvalidated_products = [])
    {
        $stock_lines = PhysiclStockCount::where('physicl_stock_counts.batch_number', $batch_no)
            ->where('physicl_stock_counts.business_location_id', $location_id)
            ->where('physicl_stock_counts.business_id', $this->business_id());

        if (! empty($unvalidated_products)) {
            $stock_lines = $stock_lines->whereIn('physicl_stock_counts.id', $unvalidated_products);
        }

        return $stock_lines->join('variations as v', 'v.id', '=', 'physicl_stock_counts.variation_id')
            ->join('variation_location_details as vld', 'vld.variation_id', '=', 'v.id')
            ->join('business_locations as bl', 'bl.id', '=', 'physicl_stock_counts.business_location_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('units as u', 'p.unit_id', '=', 'u.id')
            ->leftjoin('purchase_lines as pl', 'pl.id', '=', 'physicl_stock_counts.lot_no_line_id')
            ->where('vld.location_id', $location_id)
            ->select(
                'physicl_stock_counts.id as psc_id',
                'vld.qty_available as current_stock_system',
                'physicl_stock_counts.current_stock as current_stock_exel',
                'physicl_stock_counts.physical_Count',
                'physicl_stock_counts.variation_id',
                'physicl_stock_counts.lot_no_line_id',
                'pl.lot_number as lot_no',
                'physicl_stock_counts.business_location_id as location_id',
                'v.sub_sku',
                'u.short_name as unit',
                'u.allow_decimal as allow_decimal',
                'bl.name as location_name')
            ->get();
    }

    public function bulk_adjustment_error_line_fix($input)
    {
        foreach ($input as $key => $value) {
            $condition_1 = false;
            $condition_2 = false;
            $physical_stock_products_details = PhysiclStockCount::find($key);

            if ($physical_stock_products_details->current_stock != $value['current_stock']) {
                $condition_1 = true;
            }
            $input_current_stock = filter_var($value['current_stock'], FILTER_SANITIZE_NUMBER_FLOAT,
                FILTER_FLAG_ALLOW_FRACTION);
            $physical_stock_products_details->current_stock = (new CurrentStockRule())->passes('current-stock',
                $input_current_stock, $physical_stock_products_details->variation_id,
                $physical_stock_products_details->business_location_id, $key);

            $physical_stock = filter_var($value['physical_stock'], FILTER_SANITIZE_NUMBER_FLOAT,
                FILTER_FLAG_ALLOW_FRACTION);
            if ($physical_stock_products_details->physical_Count != $physical_stock) {
                $condition_2 = true;
                $physical_stock_products_details->physical_Count = (request()->session()->get('business.remove_minus_qty')
                    ? (new StockDiffRule())->passes('stock-diff-rule', $value['current_stock'], $physical_stock, $key)
                    : $physical_stock);
            }

            if (! empty($value['lot_number'])) {
                $lot_number = (new LotNoExistsRule())->passes('lot-no-exists', $value['lot_number'],
                    $physical_stock_products_details->variation_id,
                    $physical_stock_products_details->business_location_id,
                    $key);
                $physical_stock_products_details->lot_no_line_id = $lot_number->id;
            }

            if ($condition_1 || $condition_2) {
                $physical_stock_products_details->save();
            }
        }
    }

    public function getAdjustmentLocationId($id)
    {
        return PhysiclStockCount::where('batch_number', $id)
            ->where('business_id', $this->business_id())
            ->first()->business_location_id;
    }

    public function deleteBulkAdjustment($id)
    {
        PhysiclStockCount::where('batch_number', $id)
            ->where('business_id', $this->business_id())
            ->delete();
    }
}
