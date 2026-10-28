# API Endpoint Catalog v1 (Batch 5 draft)

Columns: Auth (— public, G guest-cart token, U user token) · Perm = Policy/permission · Tier = rate tier (API_ARCHITECTURE §2) · I = Idempotency-Key required. Errors listed are the notable stable `code`s besides `validation.failed`, `auth.unauthenticated`, `rate.limited`. All 200/201 bodies follow the envelope; Actions are in the owning module.

## Config & reference
| Method Path | Auth | Tier | Action | Response | Errors |
|---|---|---|---|---|---|
| GET /config | — | T0 | GetAppConfig | languages, currency, payment methods, policies, min app version, tax display mode | — |
| GET /geo/divisions · /geo/districts?division= · /geo/upazilas?district= | — | T0 | ListGeography | bn/en names | — |
| GET /pages/{slug} | — | T0 | GetCmsPage | page, policy version | `page.not_found` |

## Auth & profile
| Method Path | Auth | Tier | I | Action | Notes / errors |
|---|---|---|---|---|---|
| POST /auth/register | — | T2 | | RegisterCustomer | email/phone+password; `auth.identifier_taken` returned generically |
| POST /auth/login | — | T2 | | LoginCustomer | `auth.invalid_credentials`, `auth.locked` |
| POST /auth/otp/request | — | T3 | | RequestOtp | always 202 |
| POST /auth/otp/verify | — | T3 | | VerifyOtp | `auth.otp_invalid`, `auth.otp_locked` |
| POST /auth/refresh | — | T2 | | RotateRefreshToken | `auth.refresh_reused` (family revoked) |
| POST /auth/logout · /auth/logout-all | U | T4 | | RevokeTokens | |
| POST /auth/password/forgot · /reset | — | T2 | | RequestReset / ResetPassword | generic |
| GET /me · PATCH /me | U | T4/T5 | | GetProfile / UpdateProfile | |
| POST /me/verify/email · /phone | U | T3 | | StartVerification | |
| GET /me/tokens · DELETE /me/tokens/{id} | U | T4 | | ListTokens / RevokeToken | |
| POST /me/privacy/export · /delete | U | T5 | | RequestDataExport / RequestDeletion | recent re-auth |
| POST /devices · DELETE /devices/{id} | U | T5 | | RegisterDevice | push token |

## Addresses
| GET /me/addresses · POST · PATCH /{id} · DELETE /{id} | U | T4/T5 | ListAddresses / SaveAddress / DeleteAddress | `address.invalid_area` |

## Catalog & search
| Method Path | Auth | Tier | Action | Notes |
|---|---|---|---|---|
| GET /categories · /categories/{slug} | — | T0 | ListCategories | tree, ETag |
| GET /brands | — | T0 | ListBrands | |
| GET /products | — | T0 | ListProducts | filter category, brand, price, attrs, type, availability; sort relevance/newest/price/popularity |
| GET /products/{slug} | — | T0 | GetProduct | variants, media, price quote, availability, policies; `product.not_found` |
| GET /products/{slug}/related | — | T0 | GetRelated | |
| GET /search?q= | — | T1 | SearchCatalog | page-based |
| GET /search/autocomplete?q= | — | T1 | SuggestSearchTerms | ≤ 10 suggestions |
| GET /collections/{slug} | — | T0 | GetCollection | |

## Cart
| GET /cart | G/U | T5 | GetCart | recomputed totals, notices |
| POST /cart/items | G/U | T5 | AddCartItem | `stock.insufficient`, `cart.quantity_limit` |
| PATCH /cart/items/{id} · DELETE | G/U | T5 | UpdateCartItem / RemoveCartItem | |
| POST /cart/coupon · DELETE /cart/coupon | G/U | T5 | ApplyCoupon | `coupon.invalid`, `coupon.exhausted`, `coupon.not_applicable` |
| POST /cart/shipping-quote | G/U | T5 | QuoteShipping | `shipping.unavailable_area` |
| POST /cart/merge | U | T5 | MergeCart | after login |

## Checkout & orders
| Method Path | Auth | Tier | I | Action | Errors |
|---|---|---|---|---|---|
| POST /checkout/quote | G/U | T6 | | QuoteCheckout | totals incl. tax per tax settings, COD eligibility + reasons |
| POST /checkout/orders | G/U | T6 | ✔ | PlaceOrder | `stock.insufficient`, `price.changed` (with diff), `coupon.exhausted`, `cod.not_eligible`, `idempotency.conflict` |
| GET /orders · GET /orders/{id} | U | T4 | | ListOrders / GetOrder | includes `available_actions` |
| GET /orders/{id}/tracking | U or signed | T4 | | GetTracking | |
| POST /orders/{id}/cancel | U | T6 | | CancelOrder | `order.not_cancellable` |
| POST /orders/{id}/returns | U | T5 | | RequestReturn | `return.not_eligible` |
| GET /orders/{id}/invoice | U | T4 | | GetInvoiceUrl | signed short-lived URL |
| GET /guest/orders/{number}?phone=&token= | — | T1 | | LookupGuestOrder | generic 404 |

## Payments
| POST /orders/{id}/payments | G/U | T6 | ✔ | InitiatePayment | `payment.method_unavailable`, `order.already_paid` |
| GET /orders/{id}/payment | G/U | T4 | | GetPaymentStatus | server-verified only |
| POST /payments/verify (return handler) | G/U | T6 | | VerifyPayment | never trusts client |
| Webhooks (not public API): POST /webhooks/payments/{provider}, /webhooks/couriers/{provider} | provider | T7 | | StorePaymentEvent / StoreCourierEvent | 200 always after store |

## Digital & bookings
| GET /library | U | T4 | ListEntitlements | |
| POST /library/{id}/download | U | T6 | CreateDownload | signed URL; `entitlement.limit_reached`, `entitlement.expired` |
| GET /services/{slug}/slots?date_from&date_to&package= | — | T1 | FindSlots | |
| POST /bookings/hold | G/U | T6 ✔ | HoldBooking | `booking.slot_taken` |
| POST /bookings/{id}/confirm | U | T6 ✔ | ConfirmBooking | `booking.hold_expired` |
| GET /bookings · /bookings/{id} | U | T4 | ListBookings | |
| POST /bookings/{id}/cancel · /reschedule | U | T6 | CancelBooking | fee/refund preview in response |

## Engagement & support
| GET/POST/DELETE /wishlist | U | T5 | ListWishlist / AddWishlistItem / RemoveWishlistItem | |
| POST /products/{slug}/reviews · GET list | U / — | T5/T0 | SubmitReview | `review.not_eligible`, `review.duplicate` |
| GET /notifications · POST /notifications/read · GET/PUT /notifications/preferences | U | T4/T5 | ListNotifications / MarkNotificationsRead / GetPreferences / UpdatePreferences | |
| GET/POST /support/tickets · POST /support/tickets/{id}/messages | U | T4/T5 | ListTickets / OpenTicket / ReplyToTicket | |

## Coverage check
Every row maps to an Action, a Policy (ownership via scoped query) and a tier. Not in v1: staff APIs, bulk import, marketplace, subscriptions.
