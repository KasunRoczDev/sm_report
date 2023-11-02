<?php

namespace App\Utils;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CashRegister;
use App\Models\Contact;
use App\Models\PremiumPakagesTrials;
use App\Models\Product;
use App\Models\ReferenceCount;
use App\Models\Transaction;
use App\Models\TransactionSellLine;
use App\Models\Unit;
use App\Models\User;
use App\Models\VariationLocationDetails;
use Carbon\Carbon;
use DateInterval;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class Util
{
    /**
     * This function unformats a number and returns them in plain eng format
     *
     * @param  int  $input_number
     * @return float
     */
    public static function num_uf($input_number, $currency_details = null)
    {
        $thousand_separator = '';
        $decimal_separator = '';

        if (! empty($currency_details)) {
            $thousand_separator = $currency_details->thousand_separator;
            $decimal_separator = $currency_details->decimal_separator;
        } else {

            $thousand_separator = ! empty(auth()->user()->business->currency->thousand_separator)
                ? auth()->user()->business->currency->thousand_separator
                : '';
            $decimal_separator = ! empty(auth()->user()->business->currency->decimal_separator)
                ? auth()->user()->business->currency->decimal_separator
                : '';
        }

        $num = str_replace($thousand_separator, '', $input_number);
        $num = str_replace($decimal_separator, '.', $num);

        return (float) $num;
    }

    /**
     * Calculates base value on which percentage is calculated
     *
     * @param  int  $number
     * @param  int  $percent
     * @return float
     */
    public function calc_percentage_base($number, $percent)
    {
        return ($number * 100) / (100 + $percent);
    }

    public function orderStatuses()
    {
        return ['received' => __('lang_v1.received'), 'pending' => __('lang_v1.pending'), 'ordered' => __('lang_v1.ordered'), 'cancelled' => __('lang_v1.cancelled')];
    }

    /**
     * Defines available Payment Types
     *
     * @return array
     */
    public function payment_types($location = null, $show_advance = false, $business_id = null)
    {
        if (! empty($location)) {
            $location = is_object($location) ? $location : BusinessLocation::find($location);

            //Get custom label from business settings
            $custom_labels = Business::find($location->business_id)->custom_labels;
            $custom_labels = json_decode($custom_labels, true);
        } else {
            if (! empty($business_id)) {
                $custom_labels = Business::find($business_id)->custom_labels;
                $custom_labels = json_decode($custom_labels, true);
            } else {
                $custom_labels = [];
            }
        }

        $payment_types = ['cash' => __('lang_v1.cash'), 'card' => __('lang_v1.card'), 'cheque' => __('lang_v1.cheque'), 'bank_transfer' => __('lang_v1.bank_transfer'), 'other' => __('lang_v1.other')];

        $payment_types['custom_pay_1'] = ! empty($custom_labels['payments']['custom_pay_1']) ? $custom_labels['payments']['custom_pay_1'] : __('lang_v1.custom_payment_1');
        $payment_types['custom_pay_2'] = ! empty($custom_labels['payments']['custom_pay_2']) ? $custom_labels['payments']['custom_pay_2'] : __('lang_v1.custom_payment_2');
        $payment_types['custom_pay_3'] = ! empty($custom_labels['payments']['custom_pay_3']) ? $custom_labels['payments']['custom_pay_3'] : __('lang_v1.custom_payment_3');
        $payment_types['custom_pay_4'] = ! empty($custom_labels['payments']['custom_pay_4']) ? $custom_labels['payments']['custom_pay_4'] : __('lang_v1.custom_payment_4');
        $payment_types['custom_pay_5'] = ! empty($custom_labels['payments']['custom_pay_5']) ? $custom_labels['payments']['custom_pay_5'] : __('lang_v1.custom_payment_5');

        //Unset payment types if not enabled in business location
        if (! empty($location)) {
            $location_account_settings = ! empty($location->default_payment_accounts) ? json_decode($location->default_payment_accounts, true) : [];
            $enabled_accounts = [];
            foreach ($location_account_settings as $key => $value) {
                if (! empty($value['is_enabled'])) {
                    $enabled_accounts[] = $key;
                }
            }
            foreach ($payment_types as $key => $value) {
                if (! in_array($key, $enabled_accounts)) {
                    unset($payment_types[$key]);
                }
            }
        }

        if ($show_advance) {
            $payment_types = ['advance' => __('lang_v1.advance')] + ['exchange' => __('lang_v1.exchange')] + $payment_types;
        }

        return $payment_types;
    }

    /**
     * Returns the list of modules enabled
     *
     * @return array
     */
    public function isModuleEnabled($module)
    {
        $enabled_modules = $this->allModulesEnabled();

        if (in_array($module, $enabled_modules)) {
            return true;
        } else {
            return false;
        }
    }

    //Returns all avilable purchase statuses

    /**
     * Returns the list of modules enabled
     *
     * @return array
     */
    public function allModulesEnabled()
    {
        $enabled_modules = session()->has('business') ? session('business')['enabled_modules'] : null;
        $enabled_modules = (! empty($enabled_modules) && $enabled_modules != 'null') ? $enabled_modules : [];

        return $enabled_modules;
        //Module::has('Restaurant');
    }

    /**
     * Converts date in business format to mysql format
     *
     * @param  string  $date
     * @param  bool  $time (default = false)
     * @return strin
     */
    public function uf_date($date, $time = false)
    {
        $date_format = ! empty(session('business.date_format')) ? session('business.date_format') : Auth::user()->business->date_format;
        $mysql_format = 'Y-m-d';

        if ($time) {
            if (session('business.time_format') == 12) {
                $date_format = $date_format.' h:i A';
            } else {
                $date_format = $date_format.' H:i';
            }
            $mysql_format = 'Y-m-d H:i:s';
        }

        return ! empty($date_format) ? Carbon::createFromFormat($date_format, $date)->format($mysql_format) : null;
    }

    /**
     * Converts time in business format to mysql format
     *
     * @param  string  $time
     * @return strin
     */
    public function uf_time($time)
    {
        $time_format = 'H:i';
        if (session('business.time_format') == 12) {
            $time_format = 'h:i A';
        }

        return ! empty($time_format) ? Carbon::createFromFormat($time_format, $time)->format('H:i') : null;
    }

    /**
     * Converts time in business format to mysql format
     *
     * @param  string  $time
     * @return strin
     */
    public function format_time($time)
    {
        $time_format = 'H:i';
        if (session('business.time_format') == 12) {
            $time_format = 'h:i A';
        }

        return ! empty($time) ? Carbon::createFromFormat('H:i:s', $time)->format($time_format) : null;
    }

    /**
     * Increments reference count for a given type and given business
     * and gives the updated reference count
     *
     * @param  string  $type
     * @param  int  $business_id
     * @return int
     */
    public function setAndGetReferenceCount($type, $business_id = null)
    {
        if (empty($business_id)) {
            $business_id = request()->session()->get('user.business_id');
        }

        $ref = ReferenceCount::where('ref_type', $type)
            ->where('business_id', $business_id)
            ->first();
        if (! empty($ref)) {
            $ref->ref_count += 1;
            $ref->save();

            return $ref->ref_count;
        } else {
            $new_ref = ReferenceCount::create([
                'ref_type' => $type,
                'business_id' => $business_id,
                'ref_count' => 1,
            ]);

            return $new_ref->ref_count;
        }
    }

    /**
     * Generates reference number
     *
     * @param  string  $type
     * @param  int  $business_id
     * @return int
     */
    public function generateReferenceNumber($type, $ref_count, $business_id = null, $default_prefix = null, $isBulk = null)
    {
        $prefix = '';

        if (session()->has('business') && ! empty(request()->session()->get('business.ref_no_prefixes')[$type])) {
            $prefix = request()->session()->get('business.ref_no_prefixes')[$type];
        }
        if (! empty($business_id)) {
            $business = Business::find($business_id);
            $prefixes = $business->ref_no_prefixes;
            $prefix = ! empty($prefixes[$type]) ? $prefixes[$type] : '';
        }

        $prefix = $isBulk ? $isBulk.'/'.$prefix.' - ' : $prefix;
        if (! empty($default_prefix)) {
            $prefix = $default_prefix;
        }

        $ref_digits = str_pad($ref_count, 4, 0, STR_PAD_LEFT);

        if (! in_array($type, ['contacts', 'business_location', 'username', 'bulk_payment'])) {
            $ref_year = Carbon::now()->year;
            $ref_number = $prefix.$ref_year.'/'.$ref_digits;
        } else {
            $ref_number = $prefix.$ref_digits;
        }

        return $ref_number;
    }

    /**
     * Checks if the feature is allowed in demo
     *
     * @return mixed
     */
    public function notAllowedInDemo()
    {
        //Disable in demo
        if (config('app.env') == 'demo') {
            $output = ['success' => 0,
                'msg' => __('lang_v1.disabled_in_demo'),
            ];
            if (request()->ajax()) {
                return $output;
            } else {
                return back()->with('status', $output);
            }
        }
    }

    /**
     * Sends SMS notification.
     *
     * @param  array  $data
     * @return void
     */
    public function sendSms($data)
    {

        $sms_settings = $data['sms_settings'];
        $sms_service = isset($sms_settings['sms_service']) ? $sms_settings['sms_service'] : 'other';

        if ($sms_service == 'nexmo') {
            return $this->sendSmsViaNexmo($data);
        }

        if ($sms_service == 'twilio') {
            return $this->sendSmsViaTwilio($data);
        }
        if ($sms_service == 'dialog') {
            return $this->sendSmsViaDialog($data);
        }

        $request_data = [
            $sms_settings['send_to_param_name'] => $data['mobile_number'],
            $sms_settings['msg_param_name'] => $data['sms_body'],
        ];

        if (! empty($sms_settings['param_1'])) {
            $request_data[$sms_settings['param_1']] = $sms_settings['param_val_1'];
        }
        if (! empty($sms_settings['param_2'])) {
            $request_data[$sms_settings['param_2']] = $sms_settings['param_val_2'];
        }
        if (! empty($sms_settings['param_3'])) {
            $request_data[$sms_settings['param_3']] = $sms_settings['param_val_3'];
        }
        if (! empty($sms_settings['param_4'])) {
            $request_data[$sms_settings['param_4']] = $sms_settings['param_val_4'];
        }
        if (! empty($sms_settings['param_5'])) {
            $request_data[$sms_settings['param_5']] = $sms_settings['param_val_5'];
        }
        if (! empty($sms_settings['param_6'])) {
            $request_data[$sms_settings['param_6']] = $sms_settings['param_val_6'];
        }
        if (! empty($sms_settings['param_7'])) {
            $request_data[$sms_settings['param_7']] = $sms_settings['param_val_7'];
        }
        if (! empty($sms_settings['param_8'])) {
            $request_data[$sms_settings['param_8']] = $sms_settings['param_val_8'];
        }
        if (! empty($sms_settings['param_9'])) {
            $request_data[$sms_settings['param_9']] = $sms_settings['param_val_9'];
        }
        if (! empty($sms_settings['param_10'])) {
            $request_data[$sms_settings['param_10']] = $sms_settings['param_val_10'];
        }

        $client = new Client();

        $headers = [];
        if (! empty($sms_settings['header_1'])) {
            $headers[$sms_settings['header_1']] = $sms_settings['header_val_1'];
        }
        if (! empty($sms_settings['header_2'])) {
            $headers[$sms_settings['header_2']] = $sms_settings['header_val_2'];
        }
        if (! empty($sms_settings['header_3'])) {
            $headers[$sms_settings['header_3']] = $sms_settings['header_val_3'];
        }

        $options = [];
        if (! empty($headers)) {
            $options['headers'] = $headers;
        }

        if (empty($sms_settings['url'])) {
            return false;
        }

        if ($sms_settings['request_method'] == 'get') {
            $response = $client->get($sms_settings['url'].'?'.http_build_query($request_data), $options);
        } else {
            $options['form_params'] = $request_data;

            $response = $client->post($sms_settings['url'], $options);
        }

        return $response;
    }

    private function sendSmsViaNexmo($data)
    {
        $sms_settings = $data['sms_settings'];

        if (empty($sms_settings['nexmo_key']) || empty($sms_settings['nexmo_secret'])) {
            return false;
        }

        Config::set('nexmo.api_key', $sms_settings['nexmo_key']);
        Config::set('nexmo.api_secret', $sms_settings['nexmo_secret']);

        $nexmo = app('Nexmo\Client');
        $numbers = explode(',', trim($data['mobile_number']));

        foreach ($numbers as $number) {
            $nexmo->message()->send([
                'to' => $number,
                'from' => $sms_settings['nexmo_from'],
                'text' => $data['sms_body'],
            ]);
        }
    }

    private function sendSmsViaTwilio($data)
    {
        $sms_settings = $data['sms_settings'];

        if (empty($sms_settings['twilio_sid']) || empty($sms_settings['twilio_token'])) {
            return false;
        }

        $twilio = new Twilio($sms_settings['twilio_sid'], $sms_settings['twilio_token'], $sms_settings['twilio_from']);

        $numbers = explode(',', trim($data['mobile_number']));
        foreach ($numbers as $number) {
            $twilio->message($number, $data['sms_body']);
        }

    }

    private function sendSmsViaDialog($data)
    {
        $sms_settings = $data['sms_settings'];

        if (empty($sms_settings['dialog_key'])) {
            return false;
        }
        $request_data['esmsqk'] = $sms_settings['dialog_key'];
        $request_data['list'] = $data['mobile_number'];
        $request_data['message'] = $data['sms_body'];
        $sms_settings['url'] = 'https://e-sms.dialog.lk/api/v1/message-via-url/create/url-campaign';
        if (! empty($sms_settings['dialog_mask'])) {
            $request_data['source_address'] = $sms_settings['dialog_mask'];
        }

        $client = new Client();
        try {
            $client->get($sms_settings['url'].'?'.http_build_query($request_data));
        } catch (Exception $e) {
            Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
        }
    }

    /**
     * Retrieves sub units of a base unit
     *
     * @param  int  $business_id
     * @param  int  $unit_id
     * @param  bool  $return_main_unit_if_empty = false
     * @param  int  $product_id = null
     * @return array
     */
    public function getSubUnits($business_id, $unit_id, $return_main_unit_if_empty = false, $product_id = null)
    {
        $unit = Unit::where('business_id', $business_id)
            ->with(['sub_units'])
            ->findOrFail($unit_id);

        //Find related subunits for the product.
        $related_sub_units = [];
        if (! empty($product_id)) {
            $product = Product::where('business_id', $business_id)->findOrFail($product_id);
            $related_sub_units = $product->sub_unit_ids;
        }

        $sub_units = [];

        //Add main unit as per given parameter or conditions.
        if (($return_main_unit_if_empty && count($unit->sub_units) == 0)) {
            $sub_units[$unit->id] = [
                'name' => $unit->actual_name,
                'multiplier' => 1,
                'allow_decimal' => $unit->allow_decimal,
            ];
        } elseif (empty($related_sub_units) || in_array($unit->id, $related_sub_units)) {
            $sub_units[$unit->id] = [
                'name' => $unit->actual_name,
                'multiplier' => 1,
                'allow_decimal' => $unit->allow_decimal,
            ];
        }

        if (count($unit->sub_units) > 0) {
            foreach ($unit->sub_units as $sub_unit) {
                //Check if subunit is related to the product or not.
                if (empty($related_sub_units) || in_array($sub_unit->id, $related_sub_units)) {
                    $sub_units[$sub_unit->id] = [
                        'name' => $sub_unit->actual_name,
                        'multiplier' => $sub_unit->base_unit_multiplier,
                        'allow_decimal' => $sub_unit->allow_decimal,
                    ];
                }
            }
        }

        return $sub_units;
    }

    public function getMultiplierOf2Units($base_unit_id, $unit_id, array $db_connection = [])
    {
        if ($base_unit_id == $unit_id || is_null($base_unit_id) || is_null($unit_id)) {
            return 1;
        }
        if (empty($db_connection)) {
            $unit = Unit::where('base_unit_id', $base_unit_id)
                ->where('id', $unit_id)
                ->first();
        }
        if (! empty($db_connection)) {
            // Perform actions using $db_connection array
            $database = $db_connection['database'];
            $reports_landlord = $db_connection['reports_landlord'];
            $this->databaseConnectionConfig($database);

            $unit = Unit::on($reports_landlord)->where('base_unit_id', $base_unit_id)
                ->where('id', $unit_id)
                ->first();
        }

        if (empty($unit)) {
            return 1;
        } else {
            return $unit->base_unit_multiplier;
        }
    }

    public function databaseConnectionConfig($database): void
    {
        config(['database.connections.reports_landlord.database' => $database]);
    }

    /**
     * Uploads document to the server if present in the request
     *
     * @param  obj  $request , string $file_name, string dir_name
     * @return string
     */
    public function uploadFile($request, $file_name, $dir_name, $file_type = 'document')
    {
        //If app environment is demo return null
        if (config('app.env') == 'demo') {
            return null;
        }

        $uploaded_file_name = null;
        if ($request->hasFile($file_name) && $request->file($file_name)->isValid()) {

            //Check if mime type is image
            if ($file_type == 'image') {
                if (strpos($request->$file_name->getClientMimeType(), 'image/') === false) {
                    throw new Exception('Invalid image file');
                }
            }

            if ($file_type == 'document') {
                if (! in_array($request->$file_name->getClientMimeType(), array_keys(config('constants.document_upload_mimes_types')))) {
                    throw new Exception('Invalid document file');
                }
            }

            if ($request->$file_name->getSize() <= config('constants.document_size_limit')) {
                $new_file_name = time().'_'.$request->$file_name->getClientOriginalName();
                if ($request->$file_name->storeAs($dir_name, $new_file_name)) {
                    $uploaded_file_name = $new_file_name;
                }
            }
        }

        return $uploaded_file_name;
    }

    public function serviceStaffDropdown($business_id, $location_id = null)
    {
        return $this->getServiceStaff($business_id, $location_id, true);
    }

    public function getServiceStaff($business_id, $location_id = null, $for_dropdown = false)
    {
        $all_staff = [];
        $waiters = [];

        //Get all service staff roles
        $service_staff_roles = Role::where('business_id', $business_id)
            ->where('is_service_staff', 1)
            ->pluck('name')
            ->toArray();

        $get_service_staff_user_table = User::where('business_id', $business_id)
            ->where('is_service_staff', 1)
            ->select(['id',
                DB::raw("CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as full_name")])
            ->pluck('full_name', 'id')
            ->toArray();

        //Get all users of service staff roles
        if (! empty($service_staff_roles)) {
            $waiters = User::where('business_id', $business_id)
                ->role($service_staff_roles);

            if (! empty($location_id)) {
                $waiters->permission(['location.'.$location_id, 'access_all_locations']);
            }

            if ($for_dropdown) {
                $waiters = $waiters->select('id', DB::raw('CONCAT(COALESCE(first_name, ""), " ", COALESCE(last_name, "")) as full_name'))->get()->pluck('full_name', 'id')->toArray();
            } else {
                $waiters = $waiters->get();
            }
        }
        $all_staff = $waiters + $get_service_staff_user_table;

        return $all_staff;
    }

    /**
     * Replaces tags from notification body with original value
     *
     * @param  text  $body
     * @param  int  $transaction_id
     * @return array
     */
    public function replaceTags($business_id, $data, $transaction, $contact = null)
    {
        if (! empty($transaction) && ! is_object($transaction)) {
            $transaction = Transaction::where('business_id', $business_id)
                ->with(['contact', 'payment_lines'])
                ->findOrFail($transaction);
        }

        $business = Business::findOrFail($business_id);

        foreach ($data as $key => $value) {
            //Replace contact name
            if (strpos($value, '{contact_name}') !== false) {
                $contact_name = empty($contact) ? $transaction->contact->name : $contact->name;

                $data[$key] = str_replace('{contact_name}', $contact_name, $data[$key]);
            }

            //Replace invoice number
            if (strpos($value, '{invoice_number}') !== false) {
                $invoice_number = $transaction->type == 'sell' ? $transaction->invoice_no : '';

                $data[$key] = str_replace('{invoice_number}', $invoice_number, $data[$key]);
            }

            //Replace ref number
            if (strpos($value, '{order_ref_number}') !== false) {
                $order_ref_number = $transaction->ref_no;

                $data[$key] = str_replace('{order_ref_number}', $order_ref_number, $data[$key]);
            }
            //Replace total_amount
            if (strpos($value, '{total_amount}') !== false) {
                $total_amount = $this->num_f($transaction->final_total, true);

                $data[$key] = str_replace('{total_amount}', $total_amount, $data[$key]);
            }

            $total_paid = 0;
            $payment_ref_number = [];
            if (! empty($transaction)) {
                foreach ($transaction->payment_lines as $payment) {
                    if ($payment->is_return != 1) {
                        $total_paid += $payment->amount;
                        $payment_ref_number[] = $payment->payment_ref_no;
                    }
                }
            }

            $paid_amount = $this->num_f($total_paid, true);

            //Replace paid_amount
            if (strpos($value, '{paid_amount}') !== false) {
                $data[$key] = str_replace('{paid_amount}', $paid_amount, $data[$key]);
            }

            //Replace received_amount
            if (strpos($value, '{received_amount}') !== false) {
                $data[$key] = str_replace('{received_amount}', $paid_amount, $data[$key]);
            }

            //Replace payment_ref_number
            if (strpos($value, '{payment_ref_number}') !== false) {
                $data[$key] = str_replace('{payment_ref_number}', implode(', ', $payment_ref_number), $data[$key]);
            }

            //Replace due_amount
            if (strpos($value, '{due_amount}') !== false) {
                $due = $transaction->final_total - $total_paid;
                $due_amount = $this->num_f($due, true);

                $data[$key] = str_replace('{due_amount}', $due_amount, $data[$key]);
            }

            //Replace business_name
            if (strpos($value, '{business_name}') !== false) {
                $business_name = $business->name;
                $data[$key] = str_replace('{business_name}', $business_name, $data[$key]);
            }

            //Replace business_logo
            if (strpos($value, '{business_logo}') !== false) {
                $logo_name = $business->logo;
                $business_logo = ! empty($logo_name) ? '<img src="'.url('uploads_new/business_logos/'.$logo_name).'" alt="Business Logo" >' : '';

                $data[$key] = str_replace('{business_logo}', $business_logo, $data[$key]);
            }

            //Replace invoice_url
            if (! empty($transaction) && strpos($value, '{invoice_url}') !== false && $transaction->type == 'sell') {
                $invoice_url = $this->getInvoiceUrl($transaction->id, $transaction->business_id);
                $data[$key] = str_replace('{invoice_url}', $invoice_url, $data[$key]);
            }

            if (! empty($transaction) && strpos($value, '{quote_url}') !== false && $transaction->type == 'sell') {
                $invoice_url = $this->getInvoiceUrl($transaction->id, $transaction->business_id);
                $data[$key] = str_replace('{quote_url}', $invoice_url, $data[$key]);
            }

            if (strpos($value, '{cumulative_due_amount}') !== false) {
                $due = $this->getContactDue($transaction->contact_id);
                $data[$key] = str_replace('{cumulative_due_amount}', $due, $data[$key]);
            }

            if (strpos($value, '{due_date}') !== false) {
                $due_date = $transaction->due_date;
                if (! empty($due_date)) {
                    $due_date = $this->format_date($due_date->toDateTimeString(), true);
                }
                $data[$key] = str_replace('{due_date}', $due_date, $data[$key]);
            }

            if (strpos($value, '{contact_business_name}') !== false) {
                $contact_business_name = ! empty($transaction->contact->supplier_business_name) ? $transaction->contact->supplier_business_name : '';
                $data[$key] = str_replace('{contact_business_name}', $contact_business_name, $data[$key]);
            }
            if (! empty($transaction->location)) {
                if (strpos($value, '{location_name}') !== false) {
                    $location = $transaction->location->name;

                    $data[$key] = str_replace('{location_name}', $location, $data[$key]);
                }

                if (strpos($value, '{location_address}') !== false) {
                    $location_address = $transaction->location->location_address;

                    $data[$key] = str_replace('{location_address}', $location_address, $data[$key]);
                }

                if (strpos($value, '{location_email}') !== false) {
                    $location_email = $transaction->location->email;

                    $data[$key] = str_replace('{location_email}', $location_email, $data[$key]);
                }

                if (strpos($value, '{location_phone}') !== false) {
                    $location_phone = $transaction->location->mobile;

                    $data[$key] = str_replace('{location_phone}', $location_phone, $data[$key]);
                }

                if (strpos($value, '{location_custom_field_1}') !== false) {
                    $location_custom_field_1 = $transaction->location->custom_field1;

                    $data[$key] = str_replace('{location_custom_field_1}', $location_custom_field_1, $data[$key]);
                }

                if (strpos($value, '{location_custom_field_2}') !== false) {
                    $location_custom_field_2 = $transaction->location->custom_field2;

                    $data[$key] = str_replace('{location_custom_field_2}', $location_custom_field_2, $data[$key]);
                }

                if (strpos($value, '{location_custom_field_3}') !== false) {
                    $location_custom_field_3 = $transaction->location->custom_field3;

                    $data[$key] = str_replace('{location_custom_field_3}', $location_custom_field_3, $data[$key]);
                }

                if (strpos($value, '{location_custom_field_4}') !== false) {
                    $location_custom_field_4 = $transaction->location->custom_field4;

                    $data[$key] = str_replace('{location_custom_field_4}', $location_custom_field_4, $data[$key]);
                }
            }
        }

        return $data;
    }

    /**
     * This function formats a number and returns them in specified format
     *
     * @param  int  $input_number
     * @param  bool  $add_symbol = false
     * @param  array  $business_details = null
     * @param  bool  $is_quantity = false; If number represents quantity
     * @return string
     */
    public function num_f($input_number, $add_symbol = false, $business_details = null, $is_quantity = false)
    {

        $thousand_separator = ! empty($business_details) ? $business_details->thousand_separator
            : auth()->user()->business->currency->thousand_separator;
        $decimal_separator = ! empty($business_details) ? $business_details->decimal_separator
            : auth()->user()->business->currency->decimal_separator;

        $currency_precision = config('constants.currency_precision', 2);

        if ($is_quantity) {
            $currency_precision = config('constants.quantity_precision', 2);
        }

        $formatted = number_format($input_number, $currency_precision, $decimal_separator, $thousand_separator);

        if ($add_symbol) {
            $currency_symbol_placement = ! empty($business_details) ? $business_details->currency_symbol_placement
                : auth()->user()->business->currency_symbol_placement;
            $symbol = ! empty($business_details) ? $business_details->currency_symbol
                : auth()->user()->business->currency->symbol;

            if ($currency_symbol_placement == 'after') {
                $formatted = $formatted.' '.$symbol;
            } else {
                $formatted = $symbol.' '.$formatted;
            }
        }

        return $formatted;
    }

    /**
     * Generates invoice url for the transaction
     *
     * @param  int  $transaction_id , int $business_id
     * @return string
     */
    public function getInvoiceUrl($transaction_id, $business_id, $invoice_layout_id = null)
    {
        $transaction = Transaction::where('business_id', $business_id)
            ->findOrFail($transaction_id);

        if (empty($transaction->invoice_token)) {
            $transaction->invoice_token = $this->generateToken();
            $transaction->save();
        }

        if ($transaction->is_quotation == 1) {
            if (! empty($invoice_layout_id)) {
                return route('show_quote_with_layout', ['token' => $transaction->invoice_token, 'invoice_layout_id' => $invoice_layout_id]);
            } else {
                return route('show_quote', ['token' => $transaction->invoice_token]);
            }
        }
        if (! empty($invoice_layout_id)) {
            return route('show_invoice_with_layout', ['token' => $transaction->invoice_token, 'invoice_layout_id' => $invoice_layout_id]);
        } else {
            return route('show_invoice', ['token' => $transaction->invoice_token]);
        }
    }

    /**
     * Generates unique token
     *
     * @param void
     * @return string
     */
    public function generateToken()
    {
        return md5(rand(1, 10).microtime());
    }

    /**
     * Retrieves sum of due amount of a contact
     *
     * @param  int  $contact_id
     * @return mixed
     */
    public function getContactDue($contact_id)
    {
        $contact_payments = Contact::where('contacts.id', $contact_id)
            ->join('transactions AS t', 'contacts.id', '=', 't.contact_id')
            ->whereIn('t.type', ['sell', 'opening_balance', 'purchase'])
            ->select(
                DB::raw("SUM(IF(t.status = 'final' AND t.type = 'sell', final_total, 0)) as total_invoice"),
                DB::raw("SUM(IF(t.type = 'purchase', final_total, 0)) as total_purchase"),
                DB::raw("SUM(IF(t.status = 'final' AND t.type = 'sell', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as total_paid"),
                DB::raw("SUM(IF(t.type = 'purchase', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as purchase_paid"),
                DB::raw("SUM(IF(t.type = 'opening_balance', final_total, 0)) as opening_balance"),
                DB::raw("SUM(IF(t.type = 'opening_balance', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as opening_balance_paid")
            )->first();
        $due = $contact_payments->total_invoice + $contact_payments->total_purchase - $contact_payments->total_paid - $contact_payments->purchase_paid + $contact_payments->opening_balance - $contact_payments->opening_balance_paid;

        return $due;
    }

    /**
     * Converts date in mysql format to business format
     *
     * @param  string  $date
     * @param  bool  $time (default = false)
     * @return strin
     */
    public function format_date($date, $show_time = false, $business_details = null)
    {
        $format = ! empty($business_details) ? $business_details->date_format : session('business.date_format');
        if (! empty($show_time)) {
            $time_format = ! empty($business_details) ? $business_details->time_format : session('business.time_format');
            if ($time_format == 12) {
                $format .= ' h:i A';
            } else {
                $format .= ' H:i';
            }
        }

        return ! empty($date) ? Carbon::createFromTimestamp(strtotime($date))->format($format) : null;
    }

    public function replaceCashRegisterTags($business_id, $data, $transaction, $contact = null)
    {
        if (! empty($transaction) && ! is_object($transaction)) {
            $transaction = Transaction::where('business_id', $business_id)
                ->with(['contact', 'payment_lines'])
                ->findOrFail($transaction);
        }
        $business = Business::findOrFail($business_id);
        $register_id = CashRegister::where('cash_registers.business_id', $business_id)
            ->latest()
            ->pluck('cash_registers.id')
            ->first();

        $sell = $this->getCashRegisterDetails($register_id);

        foreach ($data as $key => $value) {
            //Replace contact name
            if (strpos($value, '{contact_name}') !== false) {
                $contact_name = empty($contact) ? $transaction->contact->name : $contact->name;

                $data[$key] = str_replace('{contact_name}', $contact_name, $data[$key]);
            }
            //Replace total_amount
            if (strpos($value, '{total_amount}') !== false) {
                $total_amount = $this->num_f($sell->total_cash_inflow, true);
                $data[$key] = str_replace('{total_amount}', $total_amount, $data[$key]);
            }

            //replace total_cash
            if (strpos($value, '{total_cash}') !== false) {
                $total_amount = $this->num_f($sell->total_cash, true);
                $data[$key] = str_replace('{total_cash}', $total_amount, $data[$key]);
            }

            //replace cash_in_hand amount
            if (strpos($value, '{paid_amount}') !== false) {
                $total_amount = $this->num_f($sell->cash_in_hand, true);
                $data[$key] = str_replace('{cash_in_hand}', $total_amount, $data[$key]);
            }

            //replace total_cash_inflow amount
            if (strpos($value, '{total_cash_inflow}') !== false) {
                $total_amount = $this->num_f($sell->total_cash_inflow, true);
                $data[$key] = str_replace('{total_cash_inflow}', $total_amount, $data[$key]);
            }

            //replace due_sells_final_total amount
            if (strpos($value, '{due_sells_final_total}') !== false) {
                $total_amount = $this->num_f($sell->due_sells_final_total, true);
                $data[$key] = str_replace('{due_sells_final_total}', $total_amount, $data[$key]);
            }

            //replace net_cash_amount amount
            if (strpos($value, '{net_cash_amount}') !== false) {
                $total_amount = $this->num_f($sell->net_cash_amount, true);
                $data[$key] = str_replace('{net_cash_amount}', $total_amount, $data[$key]);
            }

            //replace total_sales amount
            if (strpos($value, '{total_sales}') !== false) {
                $total_amount = $this->num_f($sell->net_cash_amount, true);
                $data[$key] = str_replace('{total_sales}', $total_amount, $data[$key]);
            }

            //Replace received_amount
            if (strpos($value, '{received_amount}') !== false) {
                $data[$key] = str_replace('{received_amount}', $sell->payment_received, $data[$key]);
            }

            //Replace due_amount
            if (strpos($value, ' {due_amount}') !== false) {
                $data[$key] = str_replace(' {due_amount}', $sell->due_sells_final_total, $data[$key]);
            }

            //Replace business_name
            if (strpos($value, '{business_name}') !== false) {
                $business_name = $business->name;
                $data[$key] = str_replace('{business_name}', $business_name, $data[$key]);
            }

            //Replace business_logo
            if (strpos($value, '{business_logo}') !== false) {
                $logo_name = $business->logo;
                $business_logo = ! empty($logo_name) ? '<img src="'.url('uploads_new/business_logos/'.$logo_name).'" alt="Business Logo" >' : '';

                $data[$key] = str_replace('{business_logo}', $business_logo, $data[$key]);
            }

            if (! empty($transaction->location)) {
                if (strpos($value, '{location_name}') !== false) {
                    $location = $transaction->location->name;

                    $data[$key] = str_replace('{location_name}', $location, $data[$key]);
                }

                if (strpos($value, '{location_address}') !== false) {
                    $location_address = $transaction->location->location_address;

                    $data[$key] = str_replace('{location_address}', $location_address, $data[$key]);
                }

                if (strpos($value, '{location_email}') !== false) {
                    $location_email = $transaction->location->email;

                    $data[$key] = str_replace('{location_email}', $location_email, $data[$key]);
                }

                if (strpos($value, '{location_phone}') !== false) {
                    $location_phone = $transaction->location->mobile;

                    $data[$key] = str_replace('{location_phone}', $location_phone, $data[$key]);
                }

                if (strpos($value, '{location_custom_field_1}') !== false) {
                    $location_custom_field_1 = $transaction->location->custom_field1;

                    $data[$key] = str_replace('{location_custom_field_1}', $location_custom_field_1, $data[$key]);
                }

                if (strpos($value, '{location_custom_field_2}') !== false) {
                    $location_custom_field_2 = $transaction->location->custom_field2;

                    $data[$key] = str_replace('{location_custom_field_2}', $location_custom_field_2, $data[$key]);
                }

                if (strpos($value, '{location_custom_field_3}') !== false) {
                    $location_custom_field_3 = $transaction->location->custom_field3;

                    $data[$key] = str_replace('{location_custom_field_3}', $location_custom_field_3, $data[$key]);
                }

                if (strpos($value, '{location_custom_field_4}') !== false) {
                    $location_custom_field_4 = $transaction->location->custom_field4;

                    $data[$key] = str_replace('{location_custom_field_4}', $location_custom_field_4, $data[$key]);
                }
            }
        }

        return $data;
    }

    public function getCashRegisterDetails($register_id = null)
    {

        $business_id = request()->session()->get('user.business_id');
        $module_util = new ModuleUtil();
        $is_admin = $module_util->is_admin(auth()->user(), $business_id);

        $query = CashRegister::leftjoin(
            'cash_register_transactions as ct',
            'ct.cash_register_id',
            '=',
            'cash_registers.id'
        )
            ->join(
                'users as u',
                'u.id',
                '=',
                'cash_registers.user_id'
            )
            ->leftJoin(
                'business_locations as bl',
                'bl.id',
                '=',
                'cash_registers.location_id'
            );
        if (empty($register_id)) {
            $user_id = auth()->user()->id;
            $query->where('user_id', $user_id)
                ->where('cash_registers.status', 'open');
        } else {
            $query->where('cash_registers.id', $register_id);
        }

        $register_details = $query->select(
            'cash_registers.created_at as open_time',
            'cash_registers.closed_at as closed_at',
            'cash_registers.user_id',
            'cash_registers.closing_note',
            'cash_registers.location_id',
            DB::raw("SUM(IF(transaction_type='initial', amount, 0)) as cash_in_hand"),
            DB::raw("SUM(IF(transaction_type='sell', amount, IF(transaction_type='refund', -1 * amount, 0))) as total_sale"),
            DB::raw("SUM(IF(pay_method='cash', IF(transaction_type='sell', amount, 0), 0)) as total_cash"),
            DB::raw("SUM(IF(pay_method='cheque', IF(transaction_type='sell', amount, 0), 0)) as total_cheque"),
            DB::raw("SUM(IF(pay_method='card', IF(transaction_type='sell', amount, 0), 0)) as total_card"),
            DB::raw("SUM(IF(pay_method='bank_transfer', IF(transaction_type='sell', amount, 0), 0)) as total_bank_transfer"),
            DB::raw("SUM(IF(pay_method='other', IF(transaction_type='sell', amount, 0), 0)) as total_other"),
            DB::raw("SUM(IF(pay_method='advance', IF(transaction_type='sell', amount, 0), 0)) as total_advance"),
            DB::raw("SUM(IF(pay_method='custom_pay_1', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_1"),
            DB::raw("SUM(IF(pay_method='custom_pay_2', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_2"),
            DB::raw("SUM(IF(pay_method='custom_pay_3', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_3"),
            DB::raw("SUM(IF(pay_method='custom_pay_4', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_4"),
            DB::raw("SUM(IF(pay_method='custom_pay_5', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_5"),
            DB::raw("SUM(IF(transaction_type='refund', amount, 0)) as total_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='cash', amount, 0), 0)) as total_cash_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='cheque', amount, 0), 0)) as total_cheque_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='card', amount, 0), 0)) as total_card_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='bank_transfer', amount, 0), 0)) as total_bank_transfer_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='other', amount, 0), 0)) as total_other_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='advance', amount, 0), 0)) as total_advance_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='custom_pay_1', amount, 0), 0)) as total_custom_pay_1_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='custom_pay_2', amount, 0), 0)) as total_custom_pay_2_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='custom_pay_3', amount, 0), 0)) as total_custom_pay_3_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='custom_pay_4', amount, 0), 0)) as total_custom_pay_4_refund"),
            DB::raw("SUM(IF(transaction_type='refund', IF(pay_method='custom_pay_5', amount, 0), 0)) as total_custom_pay_5_refund"),
            DB::raw("SUM(IF(pay_method='cheque', 1, 0)) as total_cheques"),
            DB::raw("SUM(IF(pay_method='card', 1, 0)) as total_card_slips"),
            DB::raw("CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as user_name"),
            DB::raw("SUM(IF(transaction_type='credit', amount, 0)) as credit_sale"),
            'u.email',
            'bl.name as location_name',
            'cash_registers.card_count_enter_by_user as card_count_enter_by_user',
            'cash_registers.cheque_count_enter_by_user as cheque_count_enter_by_user',
            'cash_registers.cash_amount_enter_by_user as cash_amount_enter_by_user',
            'cash_registers.card_amount_enter_by_user as card_amount_enter_by_user',
            'cash_registers.cheque_amount_enter_by_user as cheque_amount_enter_by_user'
        )->first();
        $user_id = $register_details->user_id;
        $close_time = null;
        if (! empty($register_id) && isset($register_details->closed_at)) {
            $close_time = $register_details->closed_at;
        }

        $sells = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->join(
                'transaction_payments AS TP',
                'transactions.id',
                '=',
                'TP.transaction_id'
            )
            ->where('transactions.business_id', $business_id)
            ->where('transactions.created_by', $user_id)
            ->where('TP.created_by', $user_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->where('transactions.is_direct_sale', 0)
            ->where('TP.is_direct_payment', 0)
            ->where('transactions.created_at', '>=', $register_details->open_time)
            ->where('TP.created_at', '>=', $register_details->open_time)
            ->where(function ($query) use ($close_time) {
                if (isset($close_time)) {
                    $query->where('transactions.created_at', '<=', $close_time);
                }
            })
            ->select(
                DB::raw("SUM(IF(TP.is_return ='0', amount, -1*amount)) as sell_total"),
                DB::raw("SUM(IF(TP.method='cash', IF( TP.is_return ='0', amount, -1*amount), 0)) as total_cash"),
                DB::raw("SUM(IF(TP.method='cheque', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_cheque"),
                DB::raw("SUM(IF(TP.method='card', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_card"),
                DB::raw("SUM(IF(TP.method='bank_transfer', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_bank_transfer"),
                DB::raw("SUM(IF(TP.method='other', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_other"),
                DB::raw("SUM(IF(TP.method='advance', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_advance"),
                DB::raw("SUM(IF(TP.method='custom_pay_1', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_1"),
                DB::raw("SUM(IF(TP.method='custom_pay_2', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_2"),
                DB::raw("SUM(IF(TP.method='custom_pay_3', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_3"),
                DB::raw("SUM(IF(TP.method='custom_pay_4', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_4"),
                DB::raw("SUM(IF(TP.method='custom_pay_5', IF(TP.is_return ='0', amount, -1*amount), 0)) as total_custom_pay_5")
                /*   comment for testing purpose*/
                //                'transactions.id',
                //                'transactions.transaction_date',
                //                'transactions.invoice_no',
                //                'transactions.final_total',
                //                'transactions.payment_status',
                //                'transactions.type',
                //                'TP.method',
                //                'TP.amount'

            )
            ->first();

        $all_sells = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->where('transactions.business_id', $business_id)
            ->where('transactions.created_by', $user_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->where('transactions.is_direct_sale', 0)
            ->where('transactions.created_at', '>=', $register_details->open_time)
            ->where(function ($query) use ($close_time) {
                if (isset($close_time)) {
                    $query->where('transactions.created_at', '<=', $close_time);
                }
            })
            ->select(
                DB::raw(" SUM(IF(transactions.type='sell', transactions.final_total, 0)) as final_total")
                /*   comment for testing purpose*/
                //                'transactions.id',
                //                'transactions.transaction_date',
                //                'transactions.invoice_no',
                //                'transactions.final_total',
                //                'transactions.payment_status',
                //                'transactions.type',
                //                'transactions.final_total',
                //                'TP.amount',
                ////                'transactions.created_by',
                //                'TP.created_by'

            )->first();

        $all_sells_payment = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->leftjoin(
                'transaction_payments AS TP',
                'transactions.id',
                '=',
                'TP.transaction_id'
            )
            ->where('transactions.business_id', $business_id)
            ->where('transactions.created_by', $user_id)
//          ->where('TP.created_by', $user_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->where('transactions.is_direct_sale', 0)
            ->where('TP.is_direct_payment', 0)
            ->where('TP.created_by', $user_id)
            ->where('transactions.created_at', '>=', $register_details->open_time)
            ->where(function ($query) use ($close_time) {
                if (isset($close_time)) {
                    $query->where('transactions.created_at', '<=', $close_time);
                }
            })
//            ->where('TP.created_at','>=',$register_details->open_time)
            ->select(
                DB::raw("SUM( IF(TP.is_return ='0', amount, -1*amount)) as final_total")

                /*   comment for testing purpose*/
                //                'transactions.id',
                //                'transactions.transaction_date',
                //                'transactions.invoice_no',
                //                'transactions.final_total',
                //                'transactions.payment_status',
                //                'transactions.type',
                //                'TP.method',
                //                'TP.amount',
                ////                'transactions.created_by',
                //                'TP.created_by'

            )
            ->first();

        $open_time = $register_details->open_time;
        $credit_sells = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->join(
                'transaction_payments AS TP',
                'transactions.id',
                '=',
                'TP.transaction_id'
            )
            ->where('transactions.business_id', $business_id)
            ->where('TP.created_by', $user_id)
            ->where('TP.is_direct_payment', 0)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            /*    need direct sale ane pos sale*/
//            ->where('transactions.is_direct_sale', 0)
            ->where(function ($query) use ($user_id, $open_time) {
                $query->where('transactions.created_at', '<=', $open_time)
                    ->orWhere([['transactions.created_at', '>=', $open_time], ['transactions.created_by', '!=', $user_id]])
                    ->orWhere([['transactions.created_at', '>=', $open_time], ['transactions.created_by', '=', $user_id], ['transactions.is_direct_sale', 1]]);
            })
            ->where(function ($query) use ($close_time) {
                if (isset($close_time)) {
                    $query->where('TP.created_at', '<=', $close_time);
                }
            })
            ->where('TP.created_at', '>=', $register_details->open_time)
            ->select(
                DB::raw("SUM(IF(transactions.type='sell', TP.amount, 0)) as credit_total"),
                DB::raw("SUM(IF(TP.method='cash', IF(transactions.type='sell', amount, 0), 0)) as total_cash"),
                DB::raw("SUM(IF(TP.method='cheque', IF(transactions.type='sell', amount, 0), 0)) as total_cheque"),
                DB::raw("SUM(IF(TP.method='card', IF(transactions.type='sell', amount, 0), 0)) as total_card"),
                DB::raw("SUM(IF(TP.method='bank_transfer', IF(transactions.type='sell', amount, 0), 0)) as total_bank_transfer"),
                DB::raw("SUM(IF(TP.method='other', IF(transactions.type='sell', amount, 0), 0)) as total_other"),
                DB::raw("SUM(IF(TP.method='advance', IF(transactions.type='sell', amount, 0), 0)) as total_advance"),
                DB::raw("SUM(IF(TP.method='custom_pay_1', IF(transactions.type='sell', amount, 0), 0)) as total_custom_pay_1"),
                DB::raw("SUM(IF(TP.method='custom_pay_2', IF(transactions.type='sell', amount, 0), 0)) as total_custom_pay_2"),
                DB::raw("SUM(IF(TP.method='custom_pay_3', IF(transactions.type='sell', amount, 0), 0)) as total_custom_pay_3"),
                DB::raw("SUM(IF(TP.method='custom_pay_4', IF(transactions.type='sell', amount, 0), 0)) as total_custom_pay_4"),
                DB::raw("SUM(IF(TP.method='custom_pay_5', IF(transactions.type='sell', amount, 0), 0)) as total_custom_pay_5")
                /*   comment for testing purpose*/
                //                'transactions.id',
                //                'transactions.transaction_date',
                //                'transactions.invoice_no',
                //                'transactions.final_total',
                //                'transactions.payment_status',
                //                'transactions.type',
                //                'TP.method',
                //                'TP.amount'

            )
            ->first();

        $sells_return = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->join(
                'transaction_payments AS TP',
                'transactions.id',
                '=',
                'TP.transaction_id'
            )
            ->where('transactions.business_id', $business_id)
            ->where('TP.created_by', $user_id)
            ->where('transactions.type', 'sell_return')
            ->where('transactions.status', 'final')
            /*old transaction*/
//            ->where('transactions.created_at','<=',$register_details->open_time)
            ->where('TP.created_at', '>=', $register_details->open_time)
            ->where(function ($query) use ($close_time) {
                if (isset($close_time)) {
                    $query->where('TP.created_at', '<=', $close_time);
                }
            })
            ->select(
                DB::raw("SUM(IF(transactions.type='sell_return', amount, 0)) as sells_return_total"),
                DB::raw("SUM(IF(TP.method='cash', IF(transactions.type='sell_return', amount, 0), 0)) as total_cash"),
                DB::raw("SUM(IF(TP.method='cheque', IF(transactions.type='sell_return', amount, 0), 0)) as total_cheque"),
                DB::raw("SUM(IF(TP.method='card', IF(transactions.type='sell_return', amount, 0), 0)) as total_card"),
                DB::raw("SUM(IF(TP.method='bank_transfer', IF(transactions.type='sell_return', amount, 0), 0)) as total_bank_transfer"),
                DB::raw("SUM(IF(TP.method='other', IF(transactions.type='sell_return', amount, 0), 0)) as total_other"),
                DB::raw("SUM(IF(TP.method='custom_pay_1', IF(transactions.type='sell_return', amount, 0), 0)) as total_custom_pay_1"),
                DB::raw("SUM(IF(TP.method='custom_pay_2', IF(transactions.type='sell_return', amount, 0), 0)) as total_custom_pay_2"),
                DB::raw("SUM(IF(TP.method='custom_pay_3', IF(transactions.type='sell_return', amount, 0), 0)) as total_custom_pay_3"),
                DB::raw("SUM(IF(TP.method='custom_pay_4', IF(transactions.type='sell_return', amount, 0), 0)) as total_custom_pay_4"),
                DB::raw("SUM(IF(TP.method='custom_pay_5', IF(transactions.type='sell_return', amount, 0), 0)) as total_custom_pay_5")
                /*   comment for testing purpose*/
                //                'transactions.id',
                //                'transactions.transaction_date',
                //                'transactions.invoice_no',
                //                'transactions.final_total',
                //                'transactions.payment_status',
                //                'transactions.type',
                //                'TP.method',
                //                'TP.amount'

            )
            ->first();

        $expenses = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->join(
                'transaction_payments AS TP',
                'transactions.id',
                '=',
                'TP.transaction_id'
            )
            ->where('transactions.business_id', $business_id)
            ->where('transactions.isexpensefrompos', '1')
//            ->where('TP.is_direct_payment', 0)
            ->where('TP.created_by', $user_id)
            ->where('transactions.created_at', '>=', $register_details->open_time)
            ->where(function ($query) use ($close_time) {
                if (isset($close_time)) {
                    $query->where('transactions.created_at', '<=', $close_time);
                }
            })
            ->select(
                DB::raw("SUM(IF(transactions.type='expense', TP.amount, 0)) as expense_total"),
                DB::raw("SUM(IF(TP.method='cash', IF(transactions.type='expense', TP.amount, 0), 0)) as total_cash"),
                DB::raw("SUM(IF(TP.method='cheque', IF(transactions.type='expense', TP.amount, 0), 0)) as total_cheque"),
                DB::raw("SUM(IF(TP.method='card', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_card"),
                DB::raw("SUM(IF(TP.method='bank_transfer', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_bank_transfer"),
                DB::raw("SUM(IF(TP.method='other', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_other"),
                DB::raw("SUM(IF(TP.method='custom_pay_1', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_custom_pay_1"),
                DB::raw("SUM(IF(TP.method='custom_pay_2', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_custom_pay_2"),
                DB::raw("SUM(IF(TP.method='custom_pay_3', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_custom_pay_3"),
                DB::raw("SUM(IF(TP.method='custom_pay_4', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_custom_pay_4"),
                DB::raw("SUM(IF(TP.method='custom_pay_5', IF(transactions.type='expense', TP.amount, 0), 0)) as  total_custom_pay_5")
                /*   comment for testing purpose*/
                //                'transactions.id',
                //                'transactions.transaction_date',
                //                'transactions.invoice_no',
                //                'transactions.final_total',
                //                'transactions.isexpensefrompos',
                //                'transactions.payment_status',
                //                'transactions.type',
                //                'TP.method',
                //                'TP.amount'

            )
            ->first();

        /*assign expence detailes to register detailed*/
        $register_details['sells'] = $sells;
        $register_details['credit_sells'] = $credit_sells;
        $register_details['sells_return'] = $sells_return;
        $register_details['expense'] = $expenses;
        $register_details['total_cash_inflow'] = $register_details->cash_in_hand + $register_details->sells->total_cash + $register_details->credit_sells->total_cash;
        $register_details['due_sells_final_total'] = $all_sells['final_total'] - $all_sells_payment['final_total'];
        $register_details['net_cash_amount'] = $register_details->cash_in_hand
            + $register_details->sells->total_cash + $register_details->credit_sells->total_cash
            - $register_details->total_cash_refund - $register_details->sells_return->total_cash
            - $register_details->expense->total_cash;
        $register_details['total_sales'] = $register_details->sells->sell_total + $register_details->due_sells_final_total;
        $register_details['payment_received'] = $register_details->sells->sell_total; //+$register_details->total_advance;
        $register_details['total_cash'] = $register_details->cash_in_hand
            + $register_details->sells->total_cash + $register_details->credit_sells->total_cash
            - $register_details->total_cash_refund - $register_details->sells_return->total_cash
            - $register_details->expense->total_cash;
        $register_details['total_card_amount'] = $register_details->sells->total_card + $register_details->credit_sells->total_card
            - $register_details->total_card_refund - $register_details->sells_return->total_card
            - $register_details->expense->total_card;
        $register_details['total_cheque_amount'] = $register_details->sells->total_cheque + $register_details->credit_sells->total_cheque
            - $register_details->total_cheque_refund - $register_details->sells_return->total_cheque
            - $register_details->expense->total_cheque;
        $register_details['is_admin'] = $is_admin;

        return $register_details;
    }

    //Get all Cash Register Details

    /**
     * Checks if the given user is admin
     *
     * @param  obj  $user
     * @param  int  $business_id
     * @return bool
     */
    public function is_admin($user, $business_id)
    {
        return $user->hasRole('Admin#'.$business_id) ? true : false;
    }

    public function getCronJobCommand()
    {
        $php_binary_path = empty(PHP_BINARY) ? 'php' : PHP_BINARY;

        $command = '* * * * * '.$php_binary_path.' '.base_path('artisan').' schedule:run >> /dev/null 2>&1';

        if (config('app.env') == 'demo') {
            $command = '';
        }

        return $command;
    }

    /**
     * Checks whether mail is configured or not
     *
     * @return bool
     */
    public function IsMailConfigured()
    {
        $is_mail_configured = false;

        if (! empty(env('MAIL_DRIVER')) &&
            ! empty(env('MAIL_HOST')) &&
            ! empty(env('MAIL_PORT')) &&
            ! empty(env('MAIL_USERNAME')) &&
            ! empty(env('MAIL_PASSWORD')) &&
            ! empty(env('MAIL_FROM_ADDRESS'))
        ) {
            $is_mail_configured = true;
        }

        return $is_mail_configured;
    }

    /**
     * Returns the list of barcode types
     *
     * @return array
     */
    public function barcode_types()
    {
        $types = ['C128' => 'Code 128 (C128)', 'C39' => 'Code 39 (C39)', 'EAN13' => 'EAN-13', 'EAN8' => 'EAN-8', 'UPCA' => 'UPC-A', 'UPCE' => 'UPC-E'];

        return $types;
    }

    /**
     * Returns the default barcode.
     *
     * @return string
     */
    public function barcode_default()
    {
        return 'C128';
    }

    /**
     * Retrieves user role name.
     *
     * @return string
     */
    public function getUserRoleName($user_id)
    {
        $user = User::findOrFail($user_id);

        $roles = $user->getRoleNames();

        $role_name = '';

        if (! empty($roles[0])) {
            $array = explode('#', $roles[0], 2);
            $role_name = ! empty($array[0]) ? $array[0] : '';
        }

        return $role_name;
    }

    /**
     * Retrieves all admins of a business
     *
     * @param  int  $business_id
     * @return obj
     */
    public function get_admins($business_id)
    {
        $admins = User::role('Admin#'.$business_id)->get();

        return $admins;
    }

    /**
     * Retrieves IP address of the user
     *
     * @return string
     */
    public function getUserIpAddr()
    {
        if (! empty($_SERVER['HTTP_CLIENT_IP'])) {
            //ip from share internet
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            //ip pass from proxy
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return $ip;
    }

    /**
     * This function updates the stock of products present in combo product and also updates transaction sell line.
     *
     * @param  array  $lines
     * @param  int  $location_id
     * @param  bool  $adjust_stock = true
     * @return void
     */
    public function updateEditedSellLineCombo($lines, $location_id, $adjust_stock = true)
    {
        if (empty($lines)) {
            return true;
        }

        $change_percent = null;
        $price = 0;

        foreach ($lines as $key => $line) {
            $prev_line = TransactionSellLine::find($line['transaction_sell_lines_id']);

            $difference = $prev_line->quantity - $line['quantity'];
            if ($difference != 0) {
                //Update stock in variation location details table.
                //Adjust Quantity in variations location table
                if ($adjust_stock) {
                    VariationLocationDetails::where('variation_id', $line['variation_id'])
                        ->where('product_id', $line['product_id'])
                        ->where('location_id', $location_id)
                        ->increment('qty_available', $difference);
                }

                //Update the child line quantity
                $prev_line->quantity = $line['quantity'];
            }

            //Recalculate the price.
            if (is_null($change_percent)) {
                $parent = TransactionSellLine::findOrFail($prev_line->parent_sell_line_id);
                $child_sum = TransactionSellLine::where('parent_sell_line_id', $prev_line->parent_sell_line_id)
                    ->select(DB::raw('SUM(unit_price_inc_tax * quantity) as total_price'))
                    ->first()
                    ->total_price;
                $change_percent = $this->get_percent($child_sum, $parent->unit_price_inc_tax * $parent->quantity);
            }

            $price = $this->calc_percentage($prev_line->unit_price_inc_tax, $change_percent, $prev_line->unit_price_inc_tax);
            //  profit get crazy when this happen
            //            $prev_line->unit_price_before_discount = $price;
            //           $prev_line->unit_price = $price;
            //           $prev_line->unit_price_inc_tax = $price;

            $prev_line->save();
        }
    }

    /**
     * Calculates percentage
     *
     * @param  int  $base
     * @param  int  $number
     * @return float
     */
    public function get_percent($base, $number)
    {
        if ($base == 0) {
            return 0;
        }

        $diff = $number - $base;

        return ($diff / $base) * 100;
    }

    /**
     * Calculates percentage for a given number
     *
     * @param  int  $number
     * @param  int  $percent
     * @param  int  $addition default = 0
     * @return float
     */
    public function calc_percentage($number, $percent, $addition = 0)
    {
        return $addition + ($number * ($percent / 100));
    }

    /**
     * Generates string to calculate sum of purchase line quantity used
     */
    public function get_pl_quantity_sum_string($table_name = '')
    {
        $table_name = ! empty($table_name) ? $table_name.'.' : '';
        $string = $table_name.'quantity_sold + '.$table_name.'quantity_adjusted + '.$table_name.'quantity_returned + '.$table_name.'mfg_quantity_used';

        return $string;
    }

    public function shipping_statuses()
    {
        $statuses = [
            'ordered' => __('lang_v1.ordered'),
            'packed' => __('lang_v1.packed'),
            'shipped' => __('lang_v1.shipped'),
            'inprogress' => __('lang_v1.inprogress'),
            'delivered' => __('lang_v1.delivered'),
            'returned' => __('lang_v1.returned'),
            'cancelled' => __('restaurant.cancelled'),
            'return_received' => __('lang_v1.return_received'),
        ];

        return $statuses;
    }

    /**
     * Retrieves sum of due amount of a contact
     *
     * @param  int  $contact_id
     * @return mixed
     */
    public function getInvoiceContactDue($contact_id)
    {
        $contact_payments = Contact::where('contacts.id', $contact_id)
            ->join('transactions AS t', 'contacts.id', '=', 't.contact_id')
            ->whereIn('t.type', ['sell', 'opening_balance', 'purchase'])
            ->select(
                DB::raw("SUM(IF(t.status = 'final' AND t.type = 'sell', final_total, 0)) as total_invoice"),
                DB::raw("SUM(IF(t.status = 'final' AND t.type = 'sell', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as total_paid"),
                DB::raw("SUM(IF(t.type = 'opening_balance', final_total, 0)) as opening_balance"),
                DB::raw("SUM(IF(t.type = 'opening_balance', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as opening_balance_paid")
            )->first();
        $due = $contact_payments->total_invoice - $contact_payments->total_paid + $contact_payments->opening_balance - $contact_payments->opening_balance_paid;

        return $due;
    }

    public function getDays()
    {
        return [
            'sunday' => __('lang_v1.sunday'),
            'monday' => __('lang_v1.monday'),
            'tuesday' => __('lang_v1.tuesday'),
            'wednesday' => __('lang_v1.wednesday'),
            'thursday' => __('lang_v1.thursday'),
            'friday' => __('lang_v1.friday'),
            'saturday' => __('lang_v1.saturday'),
        ];
    }

    //    public function parseNotifications($notifications)
    //    {
    //        $notifications_data = [];
    //        foreach ($notifications as $notification) {
    //            $data = $notification->data;
    //            if (in_array($notification->type, [
    //                RecurringInvoiceNotification::class,
    //                RecurringExpenseNotification::class,
    //                AdminRequestApproveNotification::class,
    //                B2BSalesNotification::class,
    //                ReminderInvoiceNotification::class,
    //                B2BPurchasesNotification::class,
    //                KitchenNotification::class,
    //                ApproveRequestNotification::class,
    //                ReportQueueNotification::class,
    //                TempServerSetupNotification::class,
    //                ExcelImportNotification::class,
    //                GeneratePdfNotification::class,
    //                QueueJobFailedNotification::class
    //            ])) {
    //                $msg = '';
    //                $icon_class = '';
    //                $link = '';
    //                $data_href = '';
    //                if ($notification->type ==
    //                    RecurringInvoiceNotification::class) {
    //                    $msg = !empty($data['invoice_status']) && $data['invoice_status'] == 'draft' ?
    //                        __(
    //                            'lang_v1.recurring_invoice_error_message',
    //                            [
    //                                'product_name' => $data['out_of_stock_product'],
    //                                'subscription_no' => !empty($data['subscription_no']) ? $data['subscription_no'] : ''
    //                            ]
    //                        ) :
    //                        __(
    //                            'lang_v1.recurring_invoice_message',
    //                            ['invoice_no' => !empty($data['invoice_no']) ? $data['invoice_no'] : '', 'subscription_no' => !empty($data['subscription_no']) ? $data['subscription_no'] : '']
    //                        );
    //                    $icon_class = !empty($data['invoice_status']) && $data['invoice_status'] == 'draft' ? "fas fa-exclamation-triangle bg-yellow" : "fas fa-recycle bg-green";
    //                    $link = action('SellPosController@listSubscriptions');
    //                } else if ($notification->type ==
    //                    RecurringExpenseNotification::class) {
    //                    $msg = __(
    //                        'lang_v1.recurring_expense_message',
    //                        ['ref_no' => $data['ref_no']]
    //                    );
    //                    $icon_class = "fas fa-recycle bg-green";
    //                    $link = action('ExpenseController@index');
    //                } else if ($notification->type == AdminRequestApproveNotification::class) {
    //                    $msg = __(
    //                        'lang_v1.minimin_sale_price_override'
    //                    );
    //                    $icon_class = "fas fa-exclamation bg-orange";
    //                    $link = action('AdminAproveActionController@requestView', [$data['transaction_id']]);
    //
    //                } else if ($notification->type == B2BSalesNotification::class) {
    //                    $msg = __('internalintegration::lang.clinet_business_made_sles_under', ['invoice_no' => $data['invoice_no']]);
    //                    $icon_class = "fas fa-arrow-circle-up bg-green";
    //                    $link = url('/inter-connect/b2b/connection-host');
    //
    //                } elseif ($notification->type == B2BPurchasesNotification::class) {
    //                    $msg = __('internalintegration::lang.host_business_made_purchase_under',
    //                        ['ref_no' => $data['ref_no']]);
    //                    $icon_class = "fas fa-arrow-circle-down bg-green";
    //                    $link = url('/inter-connect/home');
    //                } elseif ($notification->type == KitchenNotification::class) {
    //                    if (!empty($data['table_no'])) {
    //                        $msg = __($data['invoice_no'] . ' is market as Cooked' . ' ' . '(' . 'TB' . ' ' . $data['table_no'] . ')',
    //                            ['ref_no' => $data['invoice_no']]);
    //                    } else {
    //                        $msg = __($data['invoice_no'] . ' is market as Cooked', ['ref_no' => $data['invoice_no']]);
    //                    }
    //                    $icon_class = "fas fa-utensils bg-yellow";
    //                    $data_href = 'SellController@kitchenReport';
    //
    //                } elseif ($notification->type == ReminderInvoiceNotification::class) {
    //
    //                    $msg = !empty($data['invoice_status']) && $data['invoice_status'] == 'draft' ?
    //                        __(
    //                            'lang_v1.recurring_invoice_error_message',
    //                            ['subscription_no' => !empty($data['subscription_no']) ? $data['subscription_no'] : '']
    //                        ) :
    //                        __(
    //                            'lang_v1.reminder_invoice_message',
    //                            [
    //                                'invoice_no' => !empty($data['invoice_no']) ? $data['invoice_no'] : '',
    //                                'subscription_no' => !empty($data['subscription_no']) ? $data['subscription_no'] : ''
    //                            ]
    //                        );
    //                    $icon_class = !empty($data['invoice_status']) && $data['invoice_status'] == 'draft' ? "fas fa-bell bg-yellow" : "fas fa-bell bg-green";
    //                    $link = action('SellPosController@listReminders');
    //
    //                } elseif ($notification->type == ApproveRequestNotification::class) {
    //                    $msg = __('lang_v1.requested_selling_price_is',
    //                        ['ref_no' => $data['ref_no'], 'status' => $data['status'], 'product' => $data['product']]);
    //                    if ($data['status'] == 'approved') {
    //                        $icon_class = "fas fa-thumbs-up bg-green";
    //                    } else {
    //                        $icon_class = "fas fa-thumbs-down bg-red";
    //                    }
    //                    $link = action('SellPosController@edit', [$data['transaction_id']]);
    //
    //                } elseif ($notification->type == ReportQueueNotification::class) {
    //                    $msg = __('lang_v1.report_generated', ['report_name' => $data['report_name']]);
    //                    $icon_class = "fas fa-file bg-green";
    //                    //$link = action('ReportController@getReport', ['type' => $data['report_type']]);
    //
    //                } elseif ($notification->type == ExcelImportNotification::class) {
    //                    $msg = $data['message'];
    //                    $icon_class = "fas fa-file-excel bg-green";
    //                } elseif ($notification->type == GeneratePdfNotification::class) {
    //                    $msg = $data['message'];
    //                    $icon_class = "fas fa-file-excel bg-green";
    //                } elseif ($notification->type == TempServerSetupNotification::class) {
    //                    $msg = $data['msg'];
    //                    $link = !empty($data['link']) ? $data['link'] : '';
    //                    $icon_class = "fas fa-exclamation bg-orange";
    //                } elseif ($notification->type == QueueJobFailedNotification::class) {
    //                    $msg = 'Action You Taken ' . $data['job'] . ' Failed ' . $data['message'];
    //                    $icon_class = "fas fa-exclamation bg-red";
    //                }
    //
    //                $notifications_data[] = [
    //                    'msg' => $msg,
    //                    'icon_class' => $icon_class,
    //                    'link' => $link,
    //                    'data_href' => $data_href,
    //                    'read_at' => $notification->read_at,
    //                    'created_at' => $notification->created_at->diffForHumans()
    //                ];
    //            } else {
    //                $moduleUtil = new ModuleUtil;
    //                $module_notification_data = $moduleUtil->getModuleData('parse_notification', $notification);
    //                if (!empty($module_notification_data)) {
    //                    foreach ($module_notification_data as $module_data) {
    //                        if (!empty($module_data)) {
    //                            $notifications_data[] = $module_data;
    //                        }
    //                    }
    //                }
    //            }
    //        }
    //        return $notifications_data;
    //    }

    /**
     * Formats number to words
     * Requires php-intl extension
     *
     * @return string
     */
    public function numToWord($number, $lang = null)
    {
        if (! extension_loaded('intl')) {
            return '';
        }

        $lang = empty($lang) ? auth()->user()->language : $lang;
        $f = new NumberFormatter($lang, NumberFormatter::SPELLOUT);

        return $f->format($number);
    }

    public function getcities()
    {
        $cities = City::get(['name'])->map(function ($item) {
            return array_values($item->toArray());
        });

        return $cities;
    }

    public function premiumpakagestrials($business_id, $pakage_name)
    {
        $trial_active = PremiumPakagesTrials::where('business_id', $business_id)->where('premium_pakages_trials',
            $pakage_name)->wherenull('trials_end_date')->exists();
        if ($trial_active) {
            $trial_active = PremiumPakagesTrials::where('business_id', $business_id)->first();
            $start_date = $trial_active->trials_start_date;
            $today = Carbon::now();
            $start_date = Carbon::parse($start_date);
            $end_date = Carbon::parse($start_date)->addMonths(1);
            $interval = $today->diff($start_date);
            if ($interval->m >= 1) {
                $trial_active['trials_end_date'] = Carbon::now();
                //                    abort(403, 'Unauthorized action.');
            }

        } else {
            PremiumPakagesTrials::create([
                'business_id' => $business_id,
                'premium_pakages_trials' => $pakage_name,
                'trials_start_date' => Carbon::now(),
            ]);
        }
        $trial_active = PremiumPakagesTrials::where('business_id', $business_id)->where('premium_pakages_trials',
            $pakage_name)->first();

        $start_date = $trial_active->trials_start_date;
        $today = Carbon::now();
        $start_date = Carbon::parse($start_date);
        $end_date = Carbon::parse($start_date)->addMonths(1);
        $trial_end_counter = $today->diff($end_date);

        return $trial_end_counter;
    }

    public function business_id()
    {
        return auth()->user()->business_id;
    }

    /**
     * Developed by: Kasun Bandara
     * Date: 2023-02-02
     *
     * @return float
     * use for convert number to float and remove a thousand separator
     */
    public function remove_thousand_separator(string $number)
    {
        return filter_var($number, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }

    public function get_date_range_diff($start_date, $end_date): DateInterval
    {
        return Carbon::parse(request()->start_date)->diff(Carbon::parse(request()->end_date));
    }

    public function array_to_collection(array $items): Collection
    {
        $collection = new Collection();
        foreach ($items as $item) {
            $collection->push((object) $item);
        }

        foreach ($collection as $item) {
            foreach ($item as $key => $value) {
                if (is_array($value)) {
                    $item->{$key} = $this->array_to_collection($value);
                }
            }
        }

        return $collection;
    }

    public function getKeyMatchingArray(array $inputs, string $patternToMatch): array
    {
        return Arr::where($inputs, function ($value, $key) use ($patternToMatch) {
            return preg_match($patternToMatch, $key);
        });
    }

    public function dynamicDatabaseConnection($connection, $database)
    {
        if ($connection == 'reports_landlord') {
            config(['database.connections.reports_landlord.database' => $database]);
        }
    }

    public function reportAPIServer(string $url)
    {
        $base_route = env('REPORT_API_SERVER').'/api/remote-api';
        if ($url == 'sells') {
            return $base_route.'/sells';
        }
    }
}
