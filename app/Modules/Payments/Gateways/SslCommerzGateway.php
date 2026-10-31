<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\DTOs\CallbackEvent;
use App\Modules\Payments\DTOs\Capabilities;
use App\Modules\Payments\DTOs\InitiationResult;
use App\Modules\Payments\DTOs\PaymentIntent;
use App\Modules\Payments\DTOs\RefundRequest;
use App\Modules\Payments\DTOs\RefundResult;
use App\Modules\Payments\DTOs\RefundStatusResult;
use App\Modules\Payments\DTOs\VerificationRequest;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Support\ProviderAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SSLCommerz, written only from its saved official documentation (docs/vendor/sslcommerz/, saved 2026-10-30).
 *
 *  - start a payment: POST /gwprocess/v4/api.php (form), redirect the customer to `GatewayPageURL`
 *  - ask about a payment: GET /validator/api/validationserverAPI.php (by `val_id`) and GET /validator/api/merchantTransIDvalidationAPI.php (by `tran_id`)
 *  - refund and refund status: GET /validator/api/merchantTransIDvalidationAPI.php
 *
 * What the documentation does NOT give us is not used: how `verify_sign` is computed is not documented, so a notification's signature is
 * never relied on; authenticity comes from asking the gateway ourselves. Partial refunds are not stated, so they are not declared.
 *
 * The store password travels in the query string of the GET calls (that is how the API is defined). So an exception from the HTTP client,
 * which can contain the full address, is NEVER logged or passed on as is: only the kind of failure is.
 */
final class SslCommerzGateway implements PaymentGateway
{
    public const NAME = 'sslcommerz';

    private const TRAN_ID_MAX = 30;      // `tran_id` string (30)

    private const URL_MAX = 255;         // success/fail/cancel/ipn url string (255)

    private const REMARKS_MAX = 255;     // refund_remarks string (255)

    private const PAYLOAD_KEYS_MAX = 80;

    public function __construct(
        private readonly string $storeId,
        private readonly string $storePassword,
        private readonly bool $sandbox = true,
        private readonly string $fallbackEmail = 'noreply@example.com',
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            refund: true, partialRefund: false, // `refund_amount` is a parameter, but the documentation never says a partial refund is supported: unproven, so not declared
            refundStatus: true, statusQuery: true, signedCallback: false, sandbox: $this->sandbox,
            minAmountMinor: 1000, maxAmountMinor: 50_000_000, // 10.00 to 500000.00 BDT (Transaction Initiation, total_amount)
        );
    }

    public function initiate(PaymentIntent $intent): InitiationResult
    {
        if ($intent->amount->currency !== 'BDT') {
            throw new PaymentException('currency_unsupported');
        }
        if ($intent->amount->amountMinor < 1000 || $intent->amount->amountMinor > 50_000_000) {
            throw new PaymentException('amount_out_of_range');
        }
        if (mb_strlen($intent->merchantRef) > self::TRAN_ID_MAX) {
            throw new PaymentException('reference_too_long');
        }
        foreach ([$intent->successUrl, $intent->failUrl, $intent->cancelUrl, $intent->notifyUrl] as $url) {
            if (mb_strlen($url) > self::URL_MAX) {
                throw new PaymentException('callback_url_too_long');
            }
        }

        $form = [
            'store_id' => $this->storeId, 'store_passwd' => $this->storePassword,
            'total_amount' => ProviderAmount::toDecimal($intent->amount), 'currency' => 'BDT', 'tran_id' => $intent->merchantRef,
            'success_url' => $intent->successUrl, 'fail_url' => $intent->failUrl, 'cancel_url' => $intent->cancelUrl, 'ipn_url' => $intent->notifyUrl,
            'cus_name' => $this->clip($intent->customerName, 50), 'cus_email' => $intent->customerEmail ?? $this->fallbackEmail,
            'cus_add1' => $this->clip($intent->addressLine, 50), 'cus_city' => $this->clip($intent->city, 50), 'cus_postcode' => $intent->postcode ?? '0000',
            'cus_country' => 'Bangladesh', 'cus_phone' => $intent->customerPhone,
            'shipping_method' => $intent->needsShipping ? 'YES' : 'NO', 'num_of_item' => (string) max(1, $intent->itemCount),
            'product_name' => $this->clip($intent->productSummary, 255), 'product_category' => $intent->needsShipping ? 'goods' : 'digital',
            'product_profile' => $intent->needsShipping ? 'physical-goods' : 'non-physical-goods',
            'value_a' => $intent->meta['payment'] ?? '',
        ];
        if ($intent->needsShipping) {
            $form += ['ship_name' => $form['cus_name'], 'ship_add1' => $form['cus_add1'], 'ship_city' => $form['cus_city'], 'ship_postcode' => $form['cus_postcode'], 'ship_country' => 'Bangladesh'];
        }

        $reply = $this->send('POST', '/gwprocess/v4/api.php', $form);

        $url = $reply['GatewayPageURL'] ?? null;
        if (($reply['status'] ?? null) !== 'SUCCESS' || ! is_string($url) || $url === '') {
            Log::warning('payments.sslcommerz.initiation_refused', ['reason' => mb_substr((string) ($reply['failedreason'] ?? 'unknown'), 0, 120)]);

            throw new PaymentException('provider_rejected');
        }
        if (! $this->isGatewayUrl($url)) {
            Log::error('payments.sslcommerz.unexpected_gateway_url', ['host' => (string) parse_url($url, PHP_URL_HOST)]);

            throw new PaymentException('provider_bad_response'); // never send a customer to an address the gateway's own domain does not own
        }

        return new InitiationResult($url, is_string($reply['sessionkey'] ?? null) ? $reply['sessionkey'] : null, ['gateway' => self::NAME, 'status' => 'SUCCESS']);
    }

    public function parseCallback(Request $request): CallbackEvent
    {
        $payload = [];
        foreach (array_slice($request->all(), 0, self::PAYLOAD_KEYS_MAX, true) as $key => $value) {
            if (! is_string($key) || preg_match('/pass(wd|word)?|secret|token/i', $key) === 1) {
                continue; // never keep a credential, even one that was echoed back
            }
            $payload[$key] = is_scalar($value) || $value === null ? (is_string($value) ? mb_substr($value, 0, 500) : $value) : null;
        }

        $ref = is_string($payload['tran_id'] ?? null) && $payload['tran_id'] !== '' ? $payload['tran_id'] : null;
        $validation = is_string($payload['val_id'] ?? null) && $payload['val_id'] !== '' ? $payload['val_id'] : null;
        if ($ref === null && $validation === null) {
            throw new PaymentException('callback_unreadable');
        }

        // SSLCommerz sends no event id, so one is derived: the same notification (same transaction, status, amount, validation id, time) is the same event
        $id = hash('sha256', implode('|', [self::NAME, $ref, $payload['status'] ?? '', $payload['amount'] ?? '', $validation, $payload['tran_date'] ?? '', $payload['bank_tran_id'] ?? '']));

        return new CallbackEvent($id, $ref, is_string($payload['status'] ?? null) ? $payload['status'] : null, $validation, $payload);
    }

    public function verify(VerificationRequest $request): VerifiedPayment
    {
        $hint = $request->providerValidationId;
        if ($hint !== null && preg_match('/^[A-Za-z0-9_-]{1,50}$/', $hint) === 1) {
            $byId = $this->validateById($hint);
            if ($byId !== null && $byId->merchantRef === $request->merchantRef) {
                return $byId;
            }
            // The validation id belongs to some other transaction (or to none): it is only a hint, so it is dropped and we ask about OUR reference.
        }

        return $this->status($request->merchantRef);
    }

    public function status(string $merchantRef): VerifiedPayment
    {
        $reply = $this->send('GET', '/validator/api/merchantTransIDvalidationAPI.php', ['tran_id' => $merchantRef, 'store_id' => $this->storeId, 'store_passwd' => $this->storePassword, 'format' => 'json']);
        $this->assertConnected($reply);

        $elements = array_values(array_filter((array) ($reply['element'] ?? []), fn ($e) => is_array($e) && ($e['tran_id'] ?? null) === $merchantRef));
        if ($elements === []) {
            return new VerifiedPayment(VerifiedStatus::Unknown, $merchantRef, null, raw: ['no_of_trans_found' => (int) ($reply['no_of_trans_found'] ?? 0)]);
        }

        $by = fn (VerifiedStatus $s): array => array_values(array_filter($elements, fn (array $e) => $this->mapStatus((string) ($e['status'] ?? '')) === $s));
        $valid = $by(VerifiedStatus::Valid);
        $chosen = $valid[0] ?? $by(VerifiedStatus::Pending)[0] ?? $by(VerifiedStatus::Failed)[0] ?? $elements[0];

        return $this->toVerified($chosen, ['valid_count' => count($valid), 'no_of_trans_found' => (int) ($reply['no_of_trans_found'] ?? count($elements))]);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        if (mb_strlen($request->refundRef) > self::TRAN_ID_MAX) {
            throw new PaymentException('reference_too_long'); // refund_trans_id string (30)
        }

        $reply = $this->send('GET', '/validator/api/merchantTransIDvalidationAPI.php', [
            'bank_tran_id' => $request->providerTxnId, 'refund_trans_id' => $request->refundRef, 'refund_amount' => ProviderAmount::toDecimal($request->amount),
            'refund_remarks' => $this->clip($request->reason, self::REMARKS_MAX), 'refe_id' => $request->reference ?? '', 'store_id' => $this->storeId, 'store_passwd' => $this->storePassword, 'format' => 'json',
        ]);
        $this->assertConnected($reply);

        $id = is_string($reply['refund_ref_id'] ?? null) && $reply['refund_ref_id'] !== '' ? $reply['refund_ref_id'] : null;

        return match ($reply['status'] ?? null) {
            'success' => new RefundResult('accepted', $id),
            'processing' => new RefundResult('processing', $id),
            default => new RefundResult('failed', null, mb_substr((string) ($reply['errorReason'] ?? 'refund_refused'), 0, 255)),
        };
    }

    public function refundStatus(string $providerRefundId): RefundStatusResult
    {
        $reply = $this->send('GET', '/validator/api/merchantTransIDvalidationAPI.php', ['refund_ref_id' => $providerRefundId, 'store_id' => $this->storeId, 'store_passwd' => $this->storePassword, 'format' => 'json']);
        $this->assertConnected($reply);

        $state = $reply['status'] ?? null;
        if (! in_array($state, ['refunded', 'processing', 'cancelled'], true)) {
            throw new PaymentException('provider_bad_response');
        }

        return new RefundStatusResult($state, is_string($reply['refunded_on'] ?? null) ? $reply['refunded_on'] : null);
    }

    /** Order Validation API. Null when the gateway says it is not a valid validation id. @throws PaymentException */
    private function validateById(string $valId): ?VerifiedPayment
    {
        $reply = $this->send('GET', '/validator/api/validationserverAPI.php', ['val_id' => $valId, 'store_id' => $this->storeId, 'store_passwd' => $this->storePassword, 'format' => 'json']);

        $status = $reply['status'] ?? null;
        if ($status === 'INVALID_TRANSACTION' || ! in_array($status, ['VALID', 'VALIDATED'], true)) {
            return null;
        }
        if (($reply['APIConnect'] ?? null) !== 'DONE' || ! is_string($reply['tran_id'] ?? null)) {
            throw new PaymentException('provider_bad_response'); // a "success" that is not marked as a completed API call is not believed
        }

        return $this->toVerified($reply, []);
    }

    /**
     * One transaction as the gateway describes it. For BDT the two amount fields (`amount`, `currency_amount`) must agree; if they do not, or
     * the text is not an exact amount, no amount is returned and the payment is held for a person instead of guessed at.
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, scalar|null>  $extra
     */
    private function toVerified(array $d, array $extra): VerifiedPayment
    {
        $status = $this->mapStatus((string) ($d['status'] ?? ''));
        $ref = is_string($d['tran_id'] ?? null) ? $d['tran_id'] : '';
        $type = strtoupper(is_string($d['currency_type'] ?? null) ? $d['currency_type'] : (is_string($d['currency'] ?? null) ? $d['currency'] : 'BDT'));

        $money = null;
        try {
            $sent = is_string($d['currency_amount'] ?? null) && $d['currency_amount'] !== '' ? ProviderAmount::toMoney($d['currency_amount'], $type) : null;
            $settled = is_string($d['amount'] ?? null) && $d['amount'] !== '' ? ProviderAmount::toMoney($d['amount'], 'BDT') : null;
            $money = $type === 'BDT'
                ? (($sent !== null && $settled !== null && $sent->amountMinor !== $settled->amountMinor) ? null : ($sent ?? $settled))
                : $sent;
        } catch (PaymentException) {
            $money = null; // not an exact amount in a currency we know: never rounded, never guessed
        }

        $raw = array_filter([
            'status' => $d['status'] ?? null, 'tran_id' => $ref, 'val_id' => $d['val_id'] ?? null, 'amount' => $d['amount'] ?? null, 'currency_type' => $d['currency_type'] ?? null,
            'currency_amount' => $d['currency_amount'] ?? null, 'card_type' => $d['card_type'] ?? null, 'risk_level' => $d['risk_level'] ?? null, 'risk_title' => $d['risk_title'] ?? null,
        ] + $extra, fn ($v) => $v !== null && $v !== '' && is_scalar($v));

        return new VerifiedPayment(
            $status, $ref, $money,
            is_string($d['bank_tran_id'] ?? null) && $d['bank_tran_id'] !== '' ? $d['bank_tran_id'] : null,
            is_string($d['val_id'] ?? null) && $d['val_id'] !== '' ? $d['val_id'] : null,
            (string) ($d['risk_level'] ?? '0') === '1', $raw,
        );
    }

    private function mapStatus(string $status): VerifiedStatus
    {
        return match (strtoupper($status)) {
            'VALID', 'VALIDATED' => VerifiedStatus::Valid,
            'PENDING' => VerifiedStatus::Pending,
            'FAILED' => VerifiedStatus::Failed,
            'CANCELLED' => VerifiedStatus::Cancelled,
            'EXPIRED' => VerifiedStatus::Expired,
            default => VerifiedStatus::Unknown,
        };
    }

    /** @param  array<string, mixed>  $reply  @throws PaymentException */
    private function assertConnected(array $reply): void
    {
        $connect = $reply['APIConnect'] ?? null;
        if ($connect === 'DONE') {
            return;
        }

        Log::warning('payments.sslcommerz.api_not_connected', ['api_connect' => is_string($connect) ? mb_substr($connect, 0, 30) : null]);

        throw new PaymentException(match ($connect) {
            'FAILED' => 'provider_auth_failed',
            'INACTIVE' => 'provider_inactive',
            'INVALID_REQUEST' => 'provider_invalid_request',
            default => 'provider_bad_response',
        });
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    private function send(string $method, string $path, array $params): array
    {
        if ($this->storeId === '' || $this->storePassword === '') {
            throw new PaymentException('provider_not_configured');
        }

        try {
            $http = Http::acceptJson()->timeout(25)->connectTimeout(10);
            $response = $method === 'POST' ? $http->asForm()->post($this->base().$path, $params) : $http->get($this->base().$path, $params);
        } catch (Throwable $e) {
            // The exception text can hold the whole address, store password included: record only what kind of failure it was.
            Log::warning('payments.sslcommerz.unreachable', ['kind' => $e::class, 'path' => $path]);

            throw new PaymentException('provider_unavailable');
        }

        if (! $response->successful()) {
            Log::warning('payments.sslcommerz.http_error', ['status' => $response->status(), 'path' => $path]);

            throw new PaymentException('provider_unavailable');
        }

        $json = $response->json();
        if (! is_array($json)) {
            Log::warning('payments.sslcommerz.not_json', ['path' => $path]);

            throw new PaymentException('provider_bad_response');
        }

        return $json;
    }

    private function base(): string
    {
        return $this->sandbox ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
    }

    private function isGatewayUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https' && ($host === 'sslcommerz.com' || str_ends_with($host, '.sslcommerz.com'));
    }

    private function clip(string $text, int $max): string
    {
        return mb_substr(trim($text), 0, $max);
    }
}
