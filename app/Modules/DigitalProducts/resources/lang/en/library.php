<?php

declare(strict_types=1);

return [
    'title' => 'My downloads', 'empty' => 'You have no digital downloads yet. After you pay for a digital product, it appears here.', 'order' => 'Order', 'version' => 'version :n',
    'state' => ['available' => 'Ready', 'not_ready' => 'Not ready yet', 'revoked' => 'No longer available', 'expired' => 'Expired', 'limit_reached' => 'No downloads left'],
    'unlimited' => 'Download as often as you need.', 'left' => ':n downloads left.', 'until' => 'Available until :date', 'download' => 'Download', 'not_ready' => 'The file is not ready yet. We will have it here soon; nothing has been used up.',
    'errors' => [
        'download_no_file' => 'The file is not ready yet. Nothing was used up; please try again later.', 'download_revoked' => 'This download is no longer available. Please contact us if you think this is a mistake.',
        'download_expired' => 'The time to download this has passed. Please contact us.', 'download_limit_reached' => 'You have used all the downloads for this product. Please contact us if you need another.',
    ],
    'guest_intro' => 'Order :number. To open your downloads, we will text a code to the phone you ordered with (ending in :end).', 'send_code' => 'Send me a code', 'code_label' => 'Code from the SMS', 'open' => 'Open my downloads',
    'code' => [
        'sent' => 'We sent you a code. It works for a few minutes.', 'invalid' => 'That code did not work. Check it, or ask for a new code.',
        'send_cooling_down' => 'Please wait a minute before asking for another code.', 'send_target_limited' => 'Too many codes were sent to this number. Please try again later.', 'send_ip_limited' => 'Too many requests. Please try again later.',
        'send_daily_cap_reached' => 'We cannot send codes right now. Please try again later or contact us.', 'send_delivery_failed' => 'We could not send the SMS. Please try again later.',
    ],
    'order_link' => 'Your downloads',
];
