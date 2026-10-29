<?php

declare(strict_types=1);

return [
    /*
    | A cash-on-delivery order starts "pending" and waits for a person (or, later, an automatic rule) to confirm it by phone.
    | Set true to confirm COD orders the moment they are placed.
    */
    'auto_confirm_cod' => (bool) env('ORDERS_AUTO_CONFIRM_COD', false),

    // How long the link on the confirmation page / in the confirmation email keeps working for a guest.
    'confirmation_link_days' => (int) env('ORDERS_CONFIRMATION_LINK_DAYS', 30),
];
