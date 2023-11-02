<?php

namespace App\Models;

use DB;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;

class Contact extends Authenticatable
{
    use Notifiable;
    use SoftDeletes;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    protected $appends = ['full_name'];

    /**
     * @var float|int|mixed
     */
    private $exchange_balance;

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    /**
     * Get the products image.
     *
     * @return string
     */
    public function getImageUrlAttribute()
    {
        if (! empty($this->image)) {
            $image_url = asset('/uploads_new/img/'.rawurlencode($this->image));
        } else {
            $image_url = asset('/img/default.png');
        }

        return $image_url;
    }

    /**
     * Get the products image path.
     *
     * @return string
     */
    public function getImagePathAttribute()
    {
        if (! empty($this->image)) {
            $image_path = public_path('uploads_new').'/'.config('constants.product_img_path').'/'.$this->image;
        } else {
            $image_path = null;
        }

        return $image_path;
    }

    /**
     * Get the business that owns the user.
     */
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeActive($query)
    {
        return $query->where('contacts.contact_status', 'active');
    }

    public function scopeOnlySuppliers($query)
    {
        $query->whereIn('contacts.type', ['supplier', 'both']);

        if (! auth()->user()->can('supplier.view') && auth()->user()->can('supplier.view_own')) {
            $query->where('contacts.created_by', auth()->user()->id);
        }

        return $query;
    }

    public function scopeOnlyCustomers($query)
    {
        $query->whereIn('contacts.type', ['customer', 'both']);
        if (Auth::check()) {
            if (! auth()->user()->can('customer.view') && auth()->user()->can('customer.view_own')) {
                $query->where('contacts.created_by', auth()->user()->id);
            }
        }

        return $query;
    }

    /**
     * Get all of the contacts's notes & documents.
     */
    public function documentsAndnote()
    {
        return $this->morphMany('App\DocumentAndNote', 'notable');
    }

    /**
     * Return list of contact dropdown for a business
     *
     * @param $business_id int
     * @param $exclude_default = false (boolean)
     * @param $prepend_none = true (boolean)
     * @return array users
     */
    public static function contactDropdown($business_id, $exclude_default = false, $prepend_none = true, $append_id = true)
    {
        $query = Contact::where('business_id', $business_id)
            ->where('type', '!=', 'lead')
            ->active();

        if ($exclude_default) {
            $query->where('is_default', 0);
        }

        if ($append_id) {
            $query->select(
                DB::raw("IF(contact_id IS NULL OR contact_id='', name, CONCAT(name, ' - ', COALESCE(supplier_business_name, ''), '(', contact_id, ')')) AS supplier"),
                'id'
            );
        } else {
            $query->select(
                'id',
                DB::raw("IF (supplier_business_name IS not null, CONCAT(name, ' (', supplier_business_name, ')'), name) as supplier")
            );
        }

        if (! auth()->user()->can('supplier.view') && auth()->user()->can('supplier.view_own')) {
            $query->where('contacts.created_by', auth()->user()->id);
        }

        $contacts = $query->pluck('supplier', 'id');

        //Prepend none
        if ($prepend_none) {
            $contacts = $contacts->prepend(__('lang_v1.none'), '');
        }

        return $contacts;
    }

    /**
     * Return list of contact dropdown for a business
     *
     * @param $business_id int
     * @param $exclude_default = false (boolean)
     * @param $prepend_none = true (boolean)
     * @return array users
     */
    public static function contactDropdownForProduction($business_id, $prepend_none = true)
    {
        $contact_details = Contact::where('business_id', $business_id)
            ->where('type', '=', 'supplier')
            ->active();

        $contact_details->select(
            DB::raw("IF(contact_id IS NULL OR contact_id='', name, CONCAT(name, ' - ', COALESCE(supplier_business_name, ''), '(', contact_id, ')')) AS supplier"),
            'id'
        );

        if (! auth()->user()->can('supplier.view') && auth()->user()->can('supplier.view_own')) {
            $contact_details->where('contacts.created_by', auth()->user()->id);
        }

        $contacts = $contact_details->pluck('supplier', 'id');

        //Prepend none
        if ($prepend_none) {
            $contacts = $contacts->prepend(__('lang_v1.none'), '');
        }

        return $contacts;
    }

    /**
     * Return list of suppliers dropdown for a business
     *
     * @param $business_id int
     * @param $prepend_none = true (boolean)
     * @return array users
     */
    public static function suppliersDropdown($business_id, $prepend_none = true, $append_id = true)
    {
        $all_contacts = Contact::where('business_id', $business_id)
            ->whereIn('type', ['supplier', 'both'])
            ->active();

        if ($append_id) {
            $all_contacts->select(
                DB::raw("IF(contact_id IS NULL OR contact_id='', name, CONCAT(name, ' - ', COALESCE(supplier_business_name, ''), '(', contact_id, ')')) AS supplier"),
                'id'
            );
        } else {
            $all_contacts->select(
                'id',
                DB::raw("CONCAT(name, ' (', supplier_business_name, ')') as supplier")
            );
        }

        if (! auth()->user()->can('supplier.view') && auth()->user()->can('supplier.view_own')) {
            $all_contacts->where('contacts.created_by', auth()->user()->id);
        }

        $suppliers = $all_contacts->pluck('supplier', 'id');

        //Prepend none
        if ($prepend_none) {
            $suppliers = $suppliers->prepend(__('lang_v1.none'), '');
        }

        return $suppliers;
    }

    /**
     * Return list of customers dropdown for a business
     *
     * @param $business_id int
     * @param $prepend_none = true (boolean)
     * @return array users
     */
    public static function customersDropdown($business_id, $prepend_none = true, $append_id = true)
    {
        $all_contacts = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->active();

        if ($append_id) {
            $all_contacts->select(
                DB::raw("IF(contact_id IS NULL OR contact_id='', name, CONCAT(name, ' (', contact_id, ')')) AS customer"),
                'id'
            );
        } else {
            $all_contacts->select('id', DB::raw('name as customer'));
        }

        if (! auth()->user()->can('customer.view') && auth()->user()->can('customer.view_own')) {
            $all_contacts->where('contacts.created_by', auth()->user()->id);
        }

        $customers = $all_contacts->pluck('customer', 'id');

        //Prepend none
        if ($prepend_none) {
            $customers = $customers->prepend(__('lang_v1.none'), '');
        }

        return $customers;
    }

    public static function customersJsonDropdown($business_id, $wallking_customer)
    {
        $all_contacts = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->active();
        if (! $wallking_customer) {
            $all_contacts->whereNotIn('name', ['Walk-In Customer']);
        }

        $contact = $all_contacts->select(
            'id',
            DB::raw("IF(contact_id IS NULL OR contact_id='', name, CONCAT(name, ' (', contact_id, ')')) AS customer")
        )->get();

        return $contact;
    }

    /**
     * Return list of contact type.
     *
     * @param $prepend_all = false (boolean)
     * @return array
     */
    public static function typeDropdown($prepend_all = false)
    {
        $types = [];

        if ($prepend_all) {
            $types[''] = __('lang_v1.all');
        }

        $types['customer'] = __('report.customer');
        $types['supplier'] = __('report.supplier');
        $types['both'] = __('lang_v1.both_supplier_customer');

        return $types;
    }

    /**
     * Return list of contact type by permissions.
     *
     * @return array
     */
    public static function getContactTypes()
    {
        $types = [];
        if (auth()->user()->can('supplier.create')) {
            $types['supplier'] = __('report.supplier');
        }
        if (auth()->user()->can('customer.create')) {
            $types['customer'] = __('report.customer');
        }
        if (auth()->user()->can('supplier.create') && auth()->user()->can('customer.create')) {
            $types['both'] = __('lang_v1.both_supplier_customer');
        }

        return $types;
    }

    public function getContactAddressAttribute()
    {
        $address_array = [];
        if (! empty($this->address_line_1)) {
            $address_array[] = $this->address_line_1;
        }
        if (! empty($this->address_line_2)) {
            $address_array[] = $this->address_line_2;
        }
        if (! empty($this->city)) {
            $address_array[] = $this->city;
        }
        if (! empty($this->state)) {
            $address_array[] = $this->state;
        }
        if (! empty($this->country)) {
            $address_array[] = $this->country;
        }

        $address = '';
        if (! empty($address_array)) {
            $address = implode(', ', $address_array);
        }
        if (! empty($this->zip_code)) {
            $address .= ',<br>'.$this->zip_code;
        }

        return $address;
    }

    public static function promoCustomersDropdown($business_id, $promo_contacts, $prepend_none = true, $append_id = true)
    {

        $all_contacts = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->whereNotin('name', ['Walk-In Customer'])
            ->active();

        if (! empty($promo_contacts)) {
            foreach ($promo_contacts as $promo_contact) {
                $all_contacts->whereNotIn('id', [$promo_contact]);
            }
        }

        if ($append_id) {
            $all_contacts->select(
                DB::raw("IF(contact_id IS NULL OR contact_id='', name, CONCAT(name, ' (', contact_id, ')')) AS customer"),
                'id'
            );
        } else {
            $all_contacts->select('id', DB::raw('name as customer'));
        }

        if (! auth()->user()->can('customer.view') && auth()->user()->can('customer.view_own')) {
            $all_contacts->where('contacts.created_by', auth()->user()->id);
        }

        $customers = $all_contacts->pluck('customer', 'id');

        //Prepend none
        if ($prepend_none) {
            $customers = $customers->prepend(__('lang_v1.none'), '');
        }

        return $customers;
    }

    public function scopeWithoutWallkingCustomer($query)
    {
        $query->whereIn('contacts.type', ['customer', 'both'])
            ->where('contacts.is_default', 0);
        if (Auth::check()) {
            if (! auth()->user()->can('customer.view') && auth()->user()->can('customer.view_own')) {
                $query->where('contacts.created_by', auth()->user()->id);
            }
        }

        return $query;
    }

    /**
     * @dev Prabhath Wijewardhana
     *
     * @var array
     */
    /**
     * Get the Full Name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        return $this->prefix.' '.$this->first_name.' '.$this->last_name;
    }
}
