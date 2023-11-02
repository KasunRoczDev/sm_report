<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffNotificationTemplate extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    protected $table = 'notification_templates';

    /**
     * Retrives notification template from database
     *
     * @param  int  $business_id
     * @param  string  $template_for
     * @return array $template
     */
    public static function getTemplate($business_id, $template_for)
    {
        $notif_template = NotificationTemplate::where('business_id', $business_id)
            ->where('template_for', $template_for)
            ->first();
        $subject = '';
        $def_templete = collect(NotificationTemplate::defaultNotificationTemplates())->where('template_for', $template_for)->first();
        if (isset($def_templete['sms_body'])) {
            $subject = $def_templete['sms_body'];
        }
        $template = [
            'subject' => ! empty($notif_template->subject) ? $notif_template->subject : '',
            'sms_body' => ! empty($notif_template->sms_body) ? $notif_template->sms_body : '',
            'email_body' => ! empty($notif_template->email_body) ? $notif_template->email_body
                : $subject,
            'template_for' => $template_for,
            'cc' => ! empty($notif_template->cc) ? $notif_template->cc : '',
            'bcc' => ! empty($notif_template->bcc) ? $notif_template->bcc : '',
            'auto_send' => ! empty($notif_template->auto_send) ? 1
                : 0,
            'auto_send_sms' => ! empty($notif_template->auto_send_sms) ? 1
                : 0,
        ];

        return $template;
    }

    public static function staffNotifications()
    {
        return [
            'return_sale_return' => [
                'name' => __('lang_v1.return_sale_return'),
                'extra_tags' => ['{business_name}', '{business_logo}', '{contact_name}', '{invoice_number}', '{invoice_url}', '{total_amount}', '{paid_amount}', '{due_amount}', '{cumulative_due_amount}', '{due_date}', '{location_name}', '{location_address}', '{location_email}', '{location_phone}', '{location_custom_field_1}', '{location_custom_field_2}', '{location_custom_field_3}', '{location_custom_field_4}'],
            ],
            'close_register' => [
                'name' => __('lang_v1.close_register'),
                'extra_tags' => ['{business_name}', '{business_logo}', '{cash_in_hand}', '{contact_name}', '{invoice_number}', '{invoice_url}', '{total_amount}', '{total_cash_inflow}', '{due_sells_final_total}', '{net_cash_amount}', '{total_cash}', '{total_sales}', '{due_amount}', '{due_date}', '{location_name}', '{location_address}', '{location_email}', '{location_phone}', '{location_custom_field_1}', '{location_custom_field_2}', '{location_custom_field_3}', '{location_custom_field_4}'],
            ],
            'daily_sale' => [
                'name' => __('lang_v1.daily_sale'),
                'extra_tags' => ['{business_name}', '{business_logo}', '{contact_name}',  '{closing_amount}', '{invoice_number}', '{payment_ref_number}', '{received_amount}'],
            ],

        ];
    }

    public static function defaultNotificationTemplates($business_id = null)
    {
        $notification_template_data = [
            [
                'business_id' => $business_id,
                'template_for' => 'return_sale_return',
                'email_body' => '<p>Dear {contact_name},</p>

                    <p>Your invoice number is {invoice_number}<br />
                    Total amount: {total_amount}<br />
                    Paid amount: {received_amount}</p>

                    <p>Thank you for shopping with us.</p>

                    <p>{business_logo}</p>

                    <p>&nbsp;</p>',
                'sms_body' => 'Dear {contact_name}, Thank you for shopping with us. {business_name}',
                'subject' => 'Thank you from {business_name}',
                'auto_send' => '0',
            ],

            [
                'business_id' => $business_id,
                'template_for' => 'close_register',
                'email_body' => '<p>Dear {contact_name},</p>

                <p>We have received a payment of {received_amount}</p>

                <p>{business_logo}</p>',
                'sms_body' => 'Dear {contact_name}, We have received a payment of {received_amount}. {business_name}',
                'subject' => 'Payment Received, from {business_name}',
                'auto_send' => '0',
            ],
            [
                'business_id' => $business_id,
                'template_for' => 'daily_sale',
                'email_body' => '<p>Dear {contact_name},</p>

                    <p>This is to remind you that you have pending payment of {due_amount}. Kindly pay it as soon as possible.</p>

                    <p>{business_logo}</p>',
                'sms_body' => 'Dear {contact_name}, You have pending payment of {due_amount}. Kindly pay it as soon as possible. {business_name}',
                'subject' => 'Payment Reminder, from {business_name}',
                'auto_send' => '0',
            ],
        ];

        return $notification_template_data;
    }
}
