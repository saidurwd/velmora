<?php

declare(strict_types=1);

return [
    'steps' => 'Checkout steps', 'step_details' => 'Details', 'step_review' => 'Review', 'step_done' => 'Done',
    'details_title' => 'Checkout', 'review_title' => 'Review your order', 'thanks_title' => 'Thank you, your order is placed',
    'deliver_to' => 'Deliver to', 'contact' => 'Your details', 'name' => 'Full name', 'phone' => 'Mobile number', 'email' => 'Email (optional)', 'email_help' => 'We email your receipt here. Leave blank to get an SMS only.',
    'use_another_address' => 'Use a different address', 'use_saved_address' => 'Use one of my saved addresses', 'save_address' => 'Save this address to my account',
    'continue' => 'Continue to review', 'change' => 'Change', 'edit_cart' => 'Edit cart', 'your_order' => 'Your order',
    'delivery' => 'Delivery', 'free' => 'Free', 'payment' => 'Payment', 'methods' => ['cod' => 'Cash on delivery'], 'methods_help' => ['cod' => 'Pay in cash to the courier when your order arrives.'],
    'update_totals' => 'Update totals', 'place_note' => 'If you changed the delivery or payment choice above, press “Update totals” first.', 'place_order' => 'Place order · :total',
    'cod_fee' => 'Cash-on-delivery fee', 'tax' => 'Tax', 'tax_included' => 'Tax included', 'total' => 'Total',
    'order_number' => 'Order number', 'keep_link' => 'Keep this page or the link in your message: it is how you can look at this order.', 'received' => 'We have received your order.',
    'cod_message' => 'Please keep :total ready to pay in cash when your order is delivered.', 'we_will_confirm' => 'We will confirm your order shortly, often by a phone call or SMS.', 'confirmed' => 'Your order is confirmed.',
    'code' => [
        'title' => 'Confirm your phone number', 'text' => 'Because this is a large cash-on-delivery order, we need to confirm it is you. We will text a code to :phone.', 'send' => 'Send me the code', 'label' => 'Code from the SMS', 'verify' => 'Confirm',
        'sent' => 'We sent you a code. It works for a few minutes.', 'confirmed' => 'Thank you, your phone number is confirmed.', 'done' => 'Your phone number :phone is confirmed.', 'invalid' => 'That code did not work. Check it, or ask for a new one.',
        'send_cooling_down' => 'Please wait a minute before asking for another code.', 'send_target_limited' => 'Too many codes were sent to this number. Please try again later.', 'send_ip_limited' => 'Too many requests. Please try again later.',
        'send_daily_cap_reached' => 'We cannot send codes right now. Please try again later or contact us.', 'send_delivery_failed' => 'We could not send the SMS. Please check your number or try again later.',
    ],
    'errors' => [
        'cart_empty' => 'Your cart is empty.', 'cart_problem' => 'Something in your cart changed. Please check it first.', 'address_missing' => 'Please choose or enter a delivery address.',
        'contact_required' => 'Please enter your name and a valid mobile number.', 'phone_invalid' => 'Enter a valid Bangladesh mobile number, like 01712 345678.',
        'cannot_deliver' => 'Sorry, we cannot deliver to this place yet.', 'shipping_method_invalid' => 'Please choose a delivery option.',
        'payment_method_invalid' => 'Cash on delivery is not available for this order. Please choose a different delivery option or address.',
        'no_online_payment' => 'Online payment is coming soon, so downloads and services cannot be ordered yet. Please check back later.',
        'totals_changed' => 'A price or discount changed while you were checking out. The new total is shown below. Please review it and place your order again.',
        'not_accepted' => 'Sorry, we cannot accept this order online. Please contact us and we will help.', 'too_many_open_cod' => 'You already have :max cash-on-delivery orders on the way. Please wait for them to arrive, or cancel one, before ordering again.',
        'phone_confirmation_required' => 'For a large cash order we need to confirm your phone number first. Please enter the code we send you.', 'busy' => 'We are finishing another order for this phone number. Please try again in a moment.',
        'insufficient_stock' => 'Sorry, something in your cart just went out of stock. Please review your cart.', 'coupon_failed' => 'A discount code could not be used, so your order was not placed. Please review your cart.',
    ],
];
