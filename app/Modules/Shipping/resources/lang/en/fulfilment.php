<?php

declare(strict_types=1);

return [
    'menu' => ['shipments' => 'Parcels', 'returns' => 'Returns', 'issues' => 'Delivery problems', 'statements' => 'Courier statements', 'settings' => 'Delivery rules'],
    'parcel_made' => 'The parcel was made.',
    'done' => ['booked' => 'The courier booking was tried. See the parcel for the result.', 'handed_over' => 'The parcel is marked as handed over.', 'cancelled' => 'The parcel was cancelled.', 'status_recorded' => 'The parcel’s status was recorded.'],
    'status' => [
        'created' => 'Being prepared', 'handed_over' => 'Handed to the courier', 'in_transit' => 'On the way', 'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered',
        'failed' => 'Delivery did not work', 'returned' => 'Coming back', 'cancelled' => 'Cancelled', 'unknown' => 'Not clear',
    ],
    'all' => 'All', 'filter' => 'Filter', 'search' => 'Tracking code or parcel number', 'back' => '← Back', 'order' => 'Order', 'none' => '—', 'yes' => 'Yes', 'no' => 'No',
    'shipments' => [
        'title' => 'Parcels', 'empty' => 'No parcels match.', 'courier' => 'Courier', 'status' => 'Status',
        'cols' => ['parcel' => 'Parcel', 'order' => 'Order', 'courier' => 'Courier', 'tracking' => 'Tracking code', 'status' => 'Status', 'cash' => 'Cash to collect', 'made' => 'Made'],
    ],
    'order_page' => [
        'title' => 'Parcels for order :number', 'to_send' => 'Still to send', 'nothing_left' => 'Everything in this order is in a parcel.', 'make' => 'Make a parcel', 'help' => 'Enter how many of each item go in this parcel. Stock leaves the warehouse for these quantities.',
        'item' => 'Item', 'ordered' => 'Ordered', 'sent' => 'Already in parcels', 'left' => 'Left', 'put_in' => 'In this parcel', 'courier' => 'Courier', 'default_courier' => 'Best available', 'note' => 'Note for the courier (optional)', 'make_btn' => 'Make parcel',
        'parcels' => 'Parcels', 'no_parcels' => 'No parcels yet.', 'not_ready' => 'Parcels cannot be made yet:',
    ],
    'show' => [
        'title' => 'Parcel :id', 'details' => 'Details', 'order' => 'Order', 'courier' => 'Courier', 'consignment' => 'Courier’s reference', 'tracking' => 'Tracking code', 'cash' => 'Cash to collect', 'attempts' => 'Delivery attempts that did not work',
        'handed_over_at' => 'Handed over', 'delivered_at' => 'Delivered', 'items' => 'In this parcel', 'quantity' => 'Quantity', 'history' => 'History', 'when' => 'Time', 'from' => 'From', 'courier_said' => 'Courier’s word', 'we_read' => 'We read it as', 'note' => 'Note',
        'actions' => 'What you can do', 'book' => 'Book with the courier', 'hand_over' => 'Mark as handed over', 'cancel' => 'Cancel parcel', 'cancel_help' => 'Only before it is handed over. The stock goes back.', 'reason' => 'Reason',
        'manual' => 'Record where the parcel is', 'manual_status' => 'Where is it?', 'record' => 'Record', 'source' => ['webhook' => 'Courier message', 'poll' => 'Courier check', 'manual' => 'Staff', 'system' => 'System'],
        'unbooked' => 'Not booked with the courier yet.',
    ],
    'returns' => [
        'title' => 'Returns', 'empty' => 'No returns match.', 'cols' => ['return' => 'Return', 'order' => 'Order', 'kind' => 'Kind', 'status' => 'Status', 'reason' => 'Why', 'asked' => 'Asked'],
        'kind' => ['customer' => 'Customer', 'origin' => 'Courier brought it back'], 'reason_code' => ['damaged' => 'Damaged', 'wrong_item' => 'Wrong item', 'not_as_described' => 'Not as described', 'changed_mind' => 'Changed their mind', 'other' => 'Other', 'undelivered' => 'Not delivered'],
        'status' => ['requested' => 'Waiting for a decision', 'authorized' => 'Allowed, waiting for the goods', 'rejected' => 'Refused', 'received' => 'Goods back, to be looked at', 'closed' => 'Done', 'cancelled' => 'Cancelled'],
        'outcome' => ['restock' => 'Back on the shelf', 'quarantine' => 'Keep apart to check', 'write_off' => 'Write off', 'not_received' => 'Never came back'],
        'show_title' => 'Return :id', 'items' => 'Items', 'item' => 'Item', 'asked_qty' => 'Asked', 'received_qty' => 'Came back', 'outcome_col' => 'Result', 'payer' => 'Who pays for sending it back', 'payer_value' => ['shop' => 'The shop', 'customer' => 'The customer', 'none' => 'Not decided'],
        'refund' => 'Suggested refund', 'refund_note' => 'This is only a suggestion. Nothing has been refunded; refunds are made in Payments.', 'decision_note' => 'Decision note',
        'allow' => 'Allow this return', 'refuse' => 'Refuse this return', 'cancel' => 'Cancel this return', 'reason' => 'Reason', 'receive' => 'Count the goods that came back', 'receive_help' => 'Leave a number empty to mean everything that was asked for.',
        'receive_btn' => 'Goods are back', 'inspect' => 'Look at this item', 'note' => 'Note (optional)', 'inspect_btn' => 'Save result',
        'done' => ['authorized' => 'The return was allowed.', 'rejected' => 'The return was refused.', 'cancelled' => 'The return was cancelled.', 'received' => 'The goods were counted as back.', 'inspected' => 'The result was saved.'],
    ],
    'issues' => [
        'title' => 'Delivery problems', 'help' => 'Things the system could not decide by itself. Close each one with a note about what was done.', 'empty' => 'Nothing here.', 'tabs' => ['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored'],
        'cols' => ['type' => 'Problem', 'parcel' => 'Parcel', 'details' => 'Details', 'raised' => 'Raised', 'closed' => 'Closed'],
        'type' => [
            'unknown_status' => 'The courier used a status we do not know', 'unknown_consignment' => 'The courier mentioned a parcel we do not have', 'attempts_exhausted' => 'Delivery failed too many times', 'returned_to_origin' => 'The courier is bringing a parcel back',
            'statement_unknown_parcel' => 'Statement: a parcel we do not have', 'statement_status_mismatch' => 'Statement: status differs from ours', 'statement_cod_mismatch' => 'Statement: cash differs from ours',
            'statement_missing_parcel' => 'Statement: one of our parcels is missing', 'statement_already_remitted' => 'Statement: cash was already paid over', 'statement_money_mismatch' => 'Statement: received plus fee differs from cash collected',
        ],
        'outcome' => 'Close as', 'resolved' => 'Resolved', 'ignored' => 'Ignored', 'note' => 'What was done', 'close' => 'Close', 'done' => 'Closed.',
    ],
    'statements' => [
        'title' => 'Courier statements', 'help' => 'Upload the courier’s statement as a CSV with the columns consignment_id, status, cod_amount, courier_fee, delivered_at. Each line is checked against our own parcels. Cash received plus the courier’s fee must equal the cash collected.',
        'import' => 'Check a statement', 'courier' => 'Courier', 'number' => 'Statement number', 'from' => 'From', 'to' => 'To', 'received' => 'Cash received (৳)', 'file' => 'Statement file (CSV)', 'import_btn' => 'Check statement',
        'cols' => ['number' => 'Statement', 'courier' => 'Courier', 'period' => 'Period', 'received' => 'Received', 'fee' => 'Courier charges', 'lines' => 'Lines', 'matched' => 'Matched', 'problems' => 'Problems'],
        'empty' => 'No statements yet.', 'show_title' => 'Statement :number', 'line_cols' => ['consignment' => 'Courier’s reference', 'status' => 'Status', 'cash' => 'Cash', 'fee' => 'Charge', 'delivered' => 'Delivered', 'result' => 'Result'],
        'result' => ['matched' => 'Matched', 'unknown_parcel' => 'Not our parcel', 'status_mismatch' => 'Status differs', 'cod_mismatch' => 'Cash differs', 'already_remitted' => 'Already paid over'],
        'imported' => 'The statement was checked.', 'not_utf8' => 'The file must be saved as UTF-8 text.', 'amount_invalid' => 'enter the cash received in taka, like 1500 or 1500.50', 'only_view' => 'You can look at statements, but importing needs the permission to reconcile payments.',
    ],
    'settings' => [
        'title' => 'Delivery rules', 'window' => 'Days to return goods', 'window_help' => 'Customers may ask to send goods back this many days after the parcel arrives. Staff can start a return at any time.',
        'attempts' => 'Delivery attempts', 'attempts_help' => 'A parcel that fails this many times (the first try counts) is raised to a person to be returned to the shop.', 'save' => 'Save', 'saved' => 'Saved.', 'only_view' => 'You can see these rules, but changing them needs the permission to manage settings.',
    ],
];
