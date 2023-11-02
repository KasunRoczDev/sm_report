<?php

namespace App\Utils;

use App\Business;
use App\Notifications\AdminRequestApproveNotification;
use App\Notifications\ApproveRequestNotification;
use App\Notifications\B2BPurchasesNotification;
use App\Notifications\B2BSalesNotification;
use App\Notifications\CustomerNotification;
use App\Notifications\KitchenNotification;
use App\Notifications\RecurringExpenseNotification;
use App\Notifications\RecurringInvoiceNotification;
use App\Notifications\ReminderInvoiceNotification;
use App\Notifications\StaffNotification;
use App\Notifications\SupplierNotification;
use App\Notifications\TempServerSetupNotification;
use App\NotificationTemplate;
use App\Restaurant\Booking;
use App\System;
use App\Transaction;
use App\User;
use Config;
use Illuminate\Support\Facades\DB;
use Notification;

class NotificationUtil extends Util
{
    /**
     * Automatically send notification to customer/supplier if enabled in the template setting
     *
     * @param  int  $business_id
     * @param  string  $notification_type
     * @param  obj  $transaction
     * @param  obj  $contact
     * @return string
     */
    public function autoSendNotification($business_id, $notification_type, $transaction, $contact)
    {
        $notification_template = NotificationTemplate::where('business_id', $business_id)
            ->where('template_for', $notification_type)
            ->first();

        $business = Business::findOrFail($business_id);
        $data['email_settings'] = $business->email_settings;
        $data['sms_settings'] = $business->sms_settings;

        if (! empty($notification_template)) {
            if (! empty($notification_template->auto_send) || ! empty($notification_template->auto_send_sms)) {
                $orig_data = [
                    'email_body' => $notification_template->email_body,
                    'sms_body' => $notification_template->sms_body,
                    'subject' => $notification_template->subject,
                ];
                $tag_replaced_data = $this->replaceTags($business_id, $orig_data, $transaction);

                $data['email_body'] = $tag_replaced_data['email_body'];
                $data['sms_body'] = $tag_replaced_data['sms_body'];

                //Auto send email
                if (! empty($notification_template->auto_send) && ! empty($contact->email)) {
                    $data['subject'] = $tag_replaced_data['subject'];
                    $data['to_email'] = $contact->email;

                    $customer_notifications = NotificationTemplate::customerNotifications();
                    $supplier_notifications = NotificationTemplate::supplierNotifications();
                    $staff_notifications = NotificationTemplate::staffNotifications();
                    if (array_key_exists($notification_type, $customer_notifications)) {
                        Notification::route('mail', $data['to_email'])
                            ->notify(new CustomerNotification($data));
                    } elseif (array_key_exists($notification_type, $supplier_notifications)) {
                        Notification::route('mail', $data['to_email'])
                            ->notify(new SupplierNotification($data));
                    } elseif (array_key_exists($notification_type, $staff_notifications)) {
                        Notification::route('mail', $data['to_email'])
                            ->notify(new StaffNotification($data));
                    }
                }

                try {
                    //Auto send sms
                    if (! empty($notification_template->auto_send_sms)) {
                        $data['mobile_number'] = $contact->mobile;
                        if (! empty($contact->mobile)) {
                            $this->sendSms($data);
                        }
                    }
                } catch (\Exception $e) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Automatically send Staff notification to User if enabled in the template setting
     *
     * @param  int  $business_id
     * @param  string  $notification_type
     * @param  obj  $transaction
     * @param  obj  $user_mail
     * @param  obj  $user_mobile
     * @return void
     */
    public function autoSendStaffNotification($business_id, $notification_type, $transaction, $user_mail, $user_mobile)
    {
        $notification_template = NotificationTemplate::where('business_id', $business_id)
            ->where('template_for', $notification_type)
            ->first();

        $business = Business::findOrFail($business_id);
        $data['email_settings'] = $business->email_settings;
        $data['sms_settings'] = $business->sms_settings;

        if (! empty($notification_template)) {
            if (! empty($notification_template->auto_send) || ! empty($notification_template->auto_send_sms)) {
                $orig_data = [
                    'email_body' => $notification_template->email_body,
                    'sms_body' => $notification_template->sms_body,
                    'subject' => $notification_template->subject,
                ];
                $tag_replaced_data = $this->replaceCashRegisterTags($business_id, $orig_data, $transaction);

                $data['email_body'] = $tag_replaced_data['email_body'];
                $data['sms_body'] = $tag_replaced_data['sms_body'];

                //Auto send email
                if (! empty($notification_template->auto_send) && ! empty($user_mail)) {
                    $data['subject'] = $tag_replaced_data['subject'];
                    $data['to_email'] = $user_mail;

                    $staff_notifications = NotificationTemplate::staffNotifications();
                    if (array_key_exists($notification_type, $staff_notifications)) {
                        Notification::route('mail', $data['to_email'])
                            ->notify(new StaffNotification($data));
                    }
                }

                //Auto send sms
                if (! empty($notification_template->auto_send_sms)) {
                    $data['mobile_number'] = $user_mobile;
                    if (! empty($user_mobile)) {
                        $this->sendSms($data);
                    }
                }
            }
        }
    }

    /**
     * Automatically send Daily Sale Notification to User if enabled in the template setting
     *
     * @param  int  $business_id
     * @param  string  $notification_type
     * @param  obj  $transaction
     * @param  obj  $user_mail
     * @param  obj  $user_mobile
     * @return void
     */
    public function autoSendDailySaleNotification($business_id, $notification_type)
    {
        $notification_template = NotificationTemplate::where('business_id', $business_id)
            ->where('template_for', $notification_type)
            ->first();

        $business = Business::findOrFail($business_id);
        $data['email_settings'] = $business->email_settings;
        $data['sms_settings'] = $business->sms_settings;

        if (! empty($notification_template)) {
            if (! empty($notification_template->auto_send) || ! empty($notification_template->auto_send_sms)) {
                $orig_data = [
                    'email_body' => $notification_template->email_body,
                    'sms_body' => $notification_template->sms_body,
                    'subject' => $notification_template->subject,
                ];
                $tag_replaced_data = $this->replaceDailySaleTags($business_id, $orig_data);

                $data['email_body'] = $tag_replaced_data['email_body'];
                $data['sms_body'] = $tag_replaced_data['sms_body'];

                //Auto send email
                if (! empty($notification_template->auto_send)) {
                    $data['subject'] = $tag_replaced_data['subject'];
                    $users = User::where('business_id', $business_id)
                        ->select('email')
                        ->get();
                    foreach ($users as $user) {
                        $user_mail = $user['email'];
                        $data['to_email'] = $user_mail;
                        $staff_notifications = NotificationTemplate::staffNotifications();
                        if (array_key_exists($notification_type, $staff_notifications)) {
                            Notification::route('mail', $data['to_email'])
                                ->notify(new StaffNotification($data));
                        }
                    }
                }

                //Auto send sms
                if (! empty($notification_template->auto_send_sms)) {
                    $users = User::where('business_id', $business_id)
                        ->select('contact_number')
                        ->get();
                    foreach ($users as $user) {
                        $user_mobile = $user['contact_number'];
                        $data['mobile_number'] = $user_mobile;
                        if (! empty($user_mobile)) {
                            $this->sendSms($data);
                        }
                    }
                }

            }
        }
    }

    /**
     * Replaces tags from notification body with original value
     *
     * @param  text  $body
     * @param  int  $booking_id
     * @return array
     */
    public function replaceBookingTags($business_id, $data, $booking_id)
    {
        $business = Business::findOrFail($business_id);
        $booking = Booking::where('business_id', $business_id)
            ->with(['customer', 'table', 'correspondent', 'waiter', 'location', 'business'])
            ->findOrFail($booking_id);
        foreach ($data as $key => $value) {
            //Replace contact name
            if (strpos($value, '{contact_name}') !== false) {
                $contact_name = $booking->customer->name;

                $data[$key] = str_replace('{contact_name}', $contact_name, $data[$key]);
            }

            //Replace table
            if (strpos($value, '{table}') !== false) {
                $table = ! empty($booking->table->name) ? $booking->table->name : '';

                $data[$key] = str_replace('{table}', $table, $data[$key]);
            }

            //Replace start_time
            if (strpos($value, '{start_time}') !== false) {
                $start_time = $this->format_date($booking->booking_start, true);

                $data[$key] = str_replace('{start_time}', $start_time, $data[$key]);
            }

            //Replace end_time
            if (strpos($value, '{end_time}') !== false) {
                $end_time = $this->format_date($booking->booking_end, true);

                $data[$key] = str_replace('{end_time}', $end_time, $data[$key]);
            }
            //Replace location
            if (strpos($value, '{location}') !== false) {
                $location = $booking->location->name;

                $data[$key] = str_replace('{location}', $location, $data[$key]);
            }

            if (strpos($value, '{location_name}') !== false) {
                $location = $booking->location->name;

                $data[$key] = str_replace('{location_name}', $location, $data[$key]);
            }

            if (strpos($value, '{location_address}') !== false) {
                $location_address = $booking->location->location_address;

                $data[$key] = str_replace('{location_address}', $location_address, $data[$key]);
            }

            if (strpos($value, '{location_email}') !== false) {
                $location_email = $booking->location->email;

                $data[$key] = str_replace('{location_email}', $location_email, $data[$key]);
            }

            if (strpos($value, '{location_phone}') !== false) {
                $location_phone = $booking->location->mobile;

                $data[$key] = str_replace('{location_phone}', $location_phone, $data[$key]);
            }

            if (strpos($value, '{location_custom_field_1}') !== false) {
                $location_custom_field_1 = $booking->location->custom_field1;

                $data[$key] = str_replace('{location_custom_field_1}', $location_custom_field_1, $data[$key]);
            }

            if (strpos($value, '{location_custom_field_2}') !== false) {
                $location_custom_field_2 = $booking->location->custom_field2;

                $data[$key] = str_replace('{location_custom_field_2}', $location_custom_field_2, $data[$key]);
            }

            if (strpos($value, '{location_custom_field_3}') !== false) {
                $location_custom_field_3 = $booking->location->custom_field3;

                $data[$key] = str_replace('{location_custom_field_3}', $location_custom_field_3, $data[$key]);
            }

            if (strpos($value, '{location_custom_field_4}') !== false) {
                $location_custom_field_4 = $booking->location->custom_field4;

                $data[$key] = str_replace('{location_custom_field_4}', $location_custom_field_4, $data[$key]);
            }

            //Replace service_staff
            if (strpos($value, '{service_staff}') !== false) {
                $service_staff = ! empty($booking->waiter) ? $booking->waiter->user_full_name : '';

                $data[$key] = str_replace('{service_staff}', $service_staff, $data[$key]);
            }

            //Replace service_staff
            if (strpos($value, '{correspondent}') !== false) {
                $correspondent = ! empty($booking->correspondent) ? $booking->correspondent->user_full_name : '';

                $data[$key] = str_replace('{correspondent}', $correspondent, $data[$key]);
            }

            //Replace business_name
            if (strpos($value, '{business_name}') !== false) {
                $business_name = $business->name;
                $data[$key] = str_replace('{business_name}', $business_name, $data[$key]);
            }

            //Replace business_logo
            if (strpos($value, '{business_logo}') !== false) {
                $logo_name = $business->logo;
                $business_logo = ! empty($logo_name) ? '<img src="'.url('storage/business_logos/'.$logo_name).'" alt="Business Logo" >' : '';

                $data[$key] = str_replace('{business_logo}', $business_logo, $data[$key]);
            }
        }

        return $data;
    }

    /**
     * Replaces Staff Notification tags from notification body with original value
     *
     * @param  text  $data
     * @param  int  $business_id
     * @return array
     */
    public function replaceStaffTags($business_id, $data, $transaction, $contact = null)
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
                $invoice_number = $transaction->type == 'sell_return' ? $transaction->invoice_no : '';

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
                    } elseif ($payment->is_return != 0) {
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
            if (! empty($transaction) && strpos($value, '{invoice_url}') !== false && $transaction->type == 'sell_return') {
                $invoice_url = $this->getInvoiceUrl($transaction->id, $transaction->business_id);
                $data[$key] = str_replace('{invoice_url}', $invoice_url, $data[$key]);
            }

            if (! empty($transaction) && strpos($value, '{quote_url}') !== false && $transaction->type == 'sell_return') {
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
     * Replaces Daily Sale Notification tags from notification body with original value
     *
     * @param  text  $data
     * @param  int  $business_id
     * @return array
     */
    public function replaceDailySaleTags($business_id, $data)
    {
        $end_date = Carbon::now()->toDateTimeString();
        $start_date = Carbon::now()->subday()->toDateTimeString();

        $business = Business::findOrFail($business_id);
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

        if (! empty($start_date) && ! empty($end_date)) {
            $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
        }

        $sell_details = $query->get();

        $output['total_sell_inc_tax'] = $sell_details->sum('final_total');

        foreach ($data as $key => $value) {
            //Replace business_name
            if (strpos($value, '{business_name}') !== false) {
                $business_name = $business->name;
                $data[$key] = str_replace('{business_name}', $business_name, $data[$key]);
            }

            //Replace total_amount
            if (strpos($value, '{total_amount}') !== false) {
                $total_amount = $output['total_sell_inc_tax'];

                $data[$key] = str_replace('{total_amount}', $total_amount, $data[$key]);
            }

            //Replace business_logo
            if (strpos($value, '{business_logo}') !== false) {
                $logo_name = $business->logo;
                $business_logo = ! empty($logo_name) ? '<img src="'.url('uploads_new/business_logos/'.$logo_name).'" alt="Business Logo" >' : '';

                $data[$key] = str_replace('{business_logo}', $business_logo, $data[$key]);
            }
        }

        return $data;
    }

    public function recurringInvoiceNotification($user, $invoice)
    {
        $user->notify(new RecurringInvoiceNotification($invoice));
    }

    public function reminderInvoiceNotification($user, $invoice)
    {
        $user->notify(new ReminderInvoiceNotification($invoice));
    }

    public function recurringExpenseNotification($user, $expense)
    {
        $user->notify(new RecurringExpenseNotification($expense));
    }

    public function kitchenNotification($user, $kitchen)
    {
        $user->notify(new KitchenNotification($kitchen));
    }

    public function configureEmail($notificationInfo, $check_superadmin = true)
    {
        $email_settings = $notificationInfo['email_settings'];

        $is_superadmin_settings_allowed = System::getProperty('allow_email_settings_to_businesses');

        //Check if prefered email setting is superadmin email settings
        if (! empty($is_superadmin_settings_allowed) && ! empty($email_settings['use_superadmin_settings']) && $check_superadmin) {
            $email_settings['mail_driver'] = config('mail.driver');
            $email_settings['mail_host'] = config('mail.host');
            $email_settings['mail_port'] = config('mail.port');
            $email_settings['mail_username'] = config('mail.username');
            $email_settings['mail_password'] = config('mail.password');
            $email_settings['mail_encryption'] = config('mail.encryption');
            $email_settings['mail_from_address'] = config('mail.from.address');
        }

        $mail_driver = ! empty($email_settings['mail_driver']) ? $email_settings['mail_driver'] : 'smtp';
        Config::set('mail.driver', $mail_driver);
        Config::set('mail.host', $email_settings['mail_host']);
        Config::set('mail.port', $email_settings['mail_port']);
        Config::set('mail.username', $email_settings['mail_username']);
        Config::set('mail.password', $email_settings['mail_password']);
        Config::set('mail.encryption', $email_settings['mail_encryption']);

        Config::set('mail.from.address', $email_settings['mail_from_address']);
        Config::set('mail.from.name', $email_settings['mail_from_name']);
    }

    public function adminRequestApproveNotification($user, $transaction_id)
    {
        $user->notify(new AdminRequestApproveNotification($transaction_id));
    }

    public function b2bSalesNotification($user, $invoice)
    {
        $user->notify(new B2BSalesNotification($invoice));
    }

    public function b2bPurchasesNotification($user, $invoice)
    {
        $user->notify(new B2BPurchasesNotification($invoice));
    }

    public function approveNotificationRequestedUser($user, $notification_data)
    {
        $user->notify(new ApproveRequestNotification($notification_data));
    }

    public function sendTempServerSetupNotification($user, string $string)
    {
        $user->notify(new TempServerSetupNotification($string));
    }
}
