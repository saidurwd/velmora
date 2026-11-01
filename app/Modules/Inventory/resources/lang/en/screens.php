<?php

declare(strict_types=1);

return [
    'menu' => ['transfers' => 'Stock transfers', 'counts' => 'Stock-takes', 'low' => 'Low stock', 'aging' => 'Stock age'],
    'back' => '← Back', 'sku' => 'SKU', 'product' => 'Product', 'quantity' => 'Quantity', 'note' => 'Note (optional)', 'reason' => 'Reason', 'location' => 'Place', 'none' => '—', 'all' => 'All places', 'filter' => 'Filter',
    'transfer_made' => 'The transfer was made. The stock is now on its way.',
    'transfer_done' => ['received' => 'The transfer was received.', 'cancelled' => 'The transfer was cancelled and the stock went back.'],
    'count_opened' => 'The stock-take was opened.',
    'count_done' => ['recorded' => 'The counts were saved.', 'submitted' => 'The stock-take was handed in for approval.', 'approved' => 'The stock-take was approved and stock was corrected.', 'rejected' => 'The stock-take was rejected. Stock did not change.', 'cancelled' => 'The stock-take was cancelled.'],
    'transfers' => [
        'title' => 'Stock transfers', 'new' => 'New transfer', 'empty' => 'No transfers yet.', 'help' => 'Moves stock from one place to another. Only stock that is not promised to orders can be moved. Type the SKU and how many; leave unused rows empty.',
        'cols' => ['transfer' => 'Transfer', 'from' => 'From', 'to' => 'To', 'status' => 'Status', 'made' => 'Made'], 'status' => ['in_transit' => 'On its way', 'received' => 'Received', 'cancelled' => 'Cancelled'],
        'items' => 'Items', 'send' => 'Send stock', 'show_title' => 'Transfer :id', 'sent' => 'Sent', 'arrived' => 'Arrived', 'lost' => 'Lost on the way', 'receive' => 'Stock has arrived', 'receive_help' => 'Leave a number empty to mean everything arrived. Anything that did not arrive is recorded as lost.',
        'cancel' => 'Cancel transfer', 'cancel_help' => 'Only while it is on its way. The stock goes back to where it left from.',
    ],
    'counts' => [
        'title' => 'Stock-takes', 'new' => 'Start a stock-take', 'help' => 'Count what is really on the shelf. A different person must approve it before stock is corrected. Leave the SKU box empty to count everything the books say is there.', 'only' => 'Only these SKUs (separated by spaces or commas)',
        'open_btn' => 'Start stock-take', 'empty' => 'No stock-takes yet.', 'cols' => ['count' => 'Stock-take', 'place' => 'Place', 'status' => 'Status', 'opened' => 'Opened', 'sku' => 'SKU', 'product' => 'Product', 'expected' => 'Books say', 'counted' => 'Counted', 'variance' => 'Difference'],
        'status' => ['open' => 'Being counted', 'submitted' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'], 'show_title' => 'Stock-take :id', 'lines' => 'Products', 'save' => 'Save counts', 'submit' => 'Hand in for approval', 'cancel' => 'Cancel stock-take',
        'approve' => 'Approve and correct stock', 'approve_help' => 'Stock is corrected by each difference.', 'reject' => 'Reject', 'waiting' => 'Waiting for a different person to approve or reject this stock-take.',
    ],
    'low' => [
        'title' => 'Low stock', 'help' => 'Products at or below the level set as low. Stock set aside in quarantine is not counted.', 'empty' => 'Nothing is running low.',
        'cols' => ['sku' => 'SKU', 'product' => 'Product', 'place' => 'Place', 'on_hand' => 'On hand', 'reserved' => 'Reserved', 'available' => 'Available', 'threshold' => 'Low level'],
    ],
    'aging' => [
        'title' => 'Stock age', 'help' => 'How long units have been waiting since they last sold, or since they arrived if they never sold.', 'buckets' => 'By age', 'days' => 'Days', 'lines' => 'Product lines', 'units' => 'Units', 'oldest' => 'Oldest', 'empty' => 'No stock.',
        'cols' => ['sku' => 'SKU', 'product' => 'Product', 'place' => 'Place', 'units' => 'Units', 'days' => 'Days'],
    ],
];
