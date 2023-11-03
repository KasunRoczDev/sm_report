<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('health',function (){
    return response()->json(['success'=>true],200);
});

//Separate Report Server API
Route::middleware([
    'api', 'auth:api',
])->prefix('remote-api')->
controller(\App\Http\Controllers\ReportController::class)->group(function () {
    Route::get('sells', 'sells_datatables');
    Route::get('lot-report', 'getLotReport');
    Route::get('products', 'product_datatable');
    Route::get('pos-stock-report', 'getStockPosReport');
    Route::get('lot-summary-report','lotSummaryReport');
    Route::get('return-sales-datatable', 'return_sales_popup_datatable');
});
