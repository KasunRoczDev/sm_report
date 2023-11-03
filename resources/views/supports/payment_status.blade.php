@php
    $is_approved = (isset($approved))? $approved : null;
     $is_overide_permission = (isset($overide_permission))? $overide_permission : null;
     $is_admin_login = (isset($is_admin))? $is_admin : null
@endphp

@if(isset($is_admin_login) && $is_admin_login )
    <a href="{{ remote_url('payments/'.$id)}}"
       class="view_payment_modal payment-status-label" data-orig-value="{{$payment_status}}"
       data-status-name="{{__('lang_v1.' . $payment_status)}}">
        <span class="label @payment_status($payment_status)">{{__('lang_v1.' . $payment_status)}}
        </span>
    </a>
@elseif(isset($is_overide_permission) && $is_overide_permission )
    <a href="{{ remote_url('payments/'.$id)}}"
       class="view_payment_modal payment-status-label" data-orig-value="{{$payment_status}}"
       data-status-name="{{__('lang_v1.' . $payment_status)}}">
        <span class="label @payment_status($payment_status)">{{__('lang_v1.' . $payment_status)}}
        </span>
    </a>
@elseif(!isset($is_overide_permission) )
    <a href="{{ remote_url('payments/'.$id)}}"
       class="view_payment_modal payment-status-label" data-orig-value="{{$payment_status}}"
       data-status-name="{{__('lang_v1.' . $payment_status)}}">
        <span class="label @payment_status($payment_status)">{{__('lang_v1.' . $payment_status)}}
        </span>
    </a>

@elseif( isset($overide_permission) && isset($is_suspend) && !isset($is_approved) && !(!$overide_permission && $is_suspend  ) || $is_approved)
    <a href="{{ remote_url('payments/'.$id)}}"
       class="view_payment_modal payment-status-label" data-orig-value="{{$payment_status}}"
       data-status-name="{{__('lang_v1.' . $payment_status)}}">
        <span class="label @payment_status($payment_status)">{{__('lang_v1.' . $payment_status)}}
        </span>
    </a>
@else
    <a class="payment-status-label" data-orig-value="{{$payment_status}}"
       data-status-name="{{__('lang_v1.' . $payment_status)}}">
        <span class="label @payment_status($payment_status)">{{__('lang_v1.' . $payment_status)}}
        </span>
    </a>
@endif
