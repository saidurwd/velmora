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
    'errors' => [
        'cart_empty' => 'Your cart is empty.', 'cart_problem' => 'Something in your cart changed. Please check it first.', 'address_missing' => 'Please choose or enter a delivery address.',
        'contact_required' => 'Please enter your name and a valid mobile number.', 'phone_invalid' => 'Enter a valid Bangladesh mobile number, like 01712 345678.',
        'cannot_deliver' => 'Sorry, we cannot deliver to this place yet.', 'shipping_method_invalid' => 'Please choose a delivery option.',
        'payment_method_invalid' => 'Cash on delivery is not available for this order. Please choose a different delivery option or address.',
        'no_online_payment' => 'Online payment is coming soon, so downloads and services cannot be ordered yet. Please check back later.',
        'totals_changed' => 'A price or discount changed while you were checking out. The new total is shown below. Please review it and place your order again.',
        'insufficient_stock' => 'Sorry, something in your cart just went out of stock. Please review your cart.', 'coupon_failed' => 'A discount code could not be used, so your order was not placed. Please review your cart.',
    ],
];
