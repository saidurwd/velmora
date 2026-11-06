

Slice 6 adds support:

* Tables: `support_tickets`, `support_messages`, `support_attachments`, `canned_replies` (+ translations). Cms owns `faq_entries` (+ translations).
* Customer pages `/account/support` (open, follow, reply, close; files at `/account/support/files/{file}`); public `/contact`; staff `/admin/support` (queue, ticket, canned replies, contact details; `support.view`, `support.reply`, `support.manage`).
* Actions: `OpenSupportTicket`, `ReplyToSupportTicket`, `CloseOwnSupportTicket`, `StaffReplyToSupportTicket`, `AssignSupportTicket`, `ChangeSupportTicketStatus`, `SaveCannedReply`, `CheckSupportHealth` (hourly). `Services/SupportUploads` checks and keeps files; `SupportMessages` tells the customer (one message, reference and link only).
* `Privacy/SupportPersonalData`: the data copy (no internal notes), an open ticket blocks erasure, erasure deletes tickets, messages and files.
* Settings: `support.first_response_hours` (24), `support.reopen_days` (14), contact phone, email and hours; limits in `config/engagement.php` (`support`).

