<?php

namespace App\Utils;

class QueryCommonFormatterUtil
{
    public function purchase_lines_available_qty(
        $table_name = 'pl',
        $total_qty = false
    ): string {
        $qty_qry = "($table_name.quantity - ($table_name.quantity_sold + $table_name.quantity_adjusted + $table_name.quantity_returned + $table_name.mfg_quantity_used))";
        if ($total_qty) {
            return "SUM($qty_qry)";
        }

        return $qty_qry;
    }

    public function sql_float_value_round(
        $value,
        $decimal_places = 2
    ): string {
        return "ROUND($value, $decimal_places)";
    }

    public function transactionJoinPurchaseLines(
        string $transaction_table_alise,
        string $purchase_line_table_alise,
        string $join_type = 'inner'
    ): string {
        $join = "transactions $transaction_table_alise ON $purchase_line_table_alise.transaction_id = $transaction_table_alise.id";
        if ($join_type == 'left') {
            return "LEFT JOIN $join";
        } elseif ($join_type == 'right') {
            return "RIGHT JOIN $join";
        } elseif ($join_type == 'inner') {
            return "INNER JOIN $join";
        }
    }

    public function contactsJoinTransactions(
        string $contact_table_alise,
        string $transaction_table_alise,
        string $join_type = 'inner'
    ): string {
        $join = "contacts $contact_table_alise ON $transaction_table_alise.contact_id = $contact_table_alise.id";
        if ($join_type == 'left') {
            return "LEFT JOIN $join";
        } elseif ($join_type == 'right') {
            return "RIGHT JOIN $join";
        } elseif ($join_type == 'inner') {
            return "INNER JOIN $join";
        }
    }
}
