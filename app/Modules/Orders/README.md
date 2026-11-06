
* Phase 9, slice 5: customers' cancellation requests (`order_cancellation_requests`; `Actions/RequestOrderCancellation`, `WithdrawCancellationRequest`, `DecideCancellationRequest`; rules in `Services/CancellationRequestRules`). Approving records the agreement and tells the customer; it never cancels or refunds. Staff queue at `/admin/orders/cancellation-requests` (`orders.cancel`).
