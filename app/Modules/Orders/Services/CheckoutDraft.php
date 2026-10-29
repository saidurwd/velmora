<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * What the shopper has told us so far, kept in their server-side session: contact, address (a saved one, or typed once),
 * delivery and payment choice, and the form's one-time token (the idempotency key of the placed order).
 * Nothing here is trusted: every value is validated again when the order is placed.
 *
 * @phpstan-type Draft array{contact_name: string, contact_phone: string, contact_email: string|null, address: array{mode: string, id?: string, input?: array<string, mixed>}|null, shipping?: string|null, payment?: string|null}
 */
final class CheckoutDraft
{
    private const KEY = 'checkout';

    private const CONFIRMATION_MINUTES = 30;

    public function __construct(private readonly Session $session) {}

    /** @return Draft|null */
    public function get(): ?array
    {
        $d = $this->session->get(self::KEY.'.draft');
        if (! is_array($d)) {
            return null;
        }

        $address = null;
        if (is_array($d['address'] ?? null)) {
            $address = ['mode' => (string) ($d['address']['mode'] ?? '')];
            if (isset($d['address']['id'])) {
                $address['id'] = (string) $d['address']['id'];
            }
            if (is_array($d['address']['input'] ?? null)) {
                $address['input'] = $d['address']['input'];
            }
        }
        $string = fn (string $k): ?string => isset($d[$k]) && is_string($d[$k]) && $d[$k] !== '' ? $d[$k] : null;

        return [
            'contact_name' => (string) $string('contact_name'), 'contact_phone' => (string) $string('contact_phone'), 'contact_email' => $string('contact_email'),
            'address' => $address, 'shipping' => $string('shipping'), 'payment' => $string('payment'),
        ];
    }

    /** @param  Draft  $draft */
    public function put(array $draft): void
    {
        $this->session->put(self::KEY.'.draft', $draft);
    }

    public function choose(?string $shipping, ?string $payment): void
    {
        $draft = $this->get();
        if ($draft !== null) {
            $draft['shipping'] = $shipping;
            $draft['payment'] = $payment;
            $this->put($draft);
        }
    }

    /** One token per checkout attempt; it survives reloads of the review page and is dropped once an order is placed. */
    public function token(): string
    {
        $token = $this->session->get(self::KEY.'.token');
        if (! is_string($token) || $token === '') {
            $token = Str::random(40);
            $this->session->put(self::KEY.'.token', $token);
        }

        return $token;
    }

    /** Remember (for this shopper's session only) that a code was entered correctly for this phone. */
    public function confirmPhone(string $phoneE164): void
    {
        $this->session->put(self::KEY.'.phone_confirmed', ['phone' => $phoneE164, 'at' => now()->getTimestamp()]);
    }

    public function isPhoneConfirmed(string $phoneE164): bool
    {
        $c = $this->session->get(self::KEY.'.phone_confirmed');

        return is_array($c) && ($c['phone'] ?? null) === $phoneE164 && now()->getTimestamp() - (int) ($c['at'] ?? 0) <= self::CONFIRMATION_MINUTES * 60;
    }

    public function placed(string $orderPublicId): void
    {
        $this->session->forget([self::KEY.'.draft', self::KEY.'.token', self::KEY.'.phone_confirmed']);
        $this->session->put(self::KEY.'.last_order', $orderPublicId);
    }

    public function lastOrder(): ?string
    {
        $id = $this->session->get(self::KEY.'.last_order');

        return is_string($id) ? $id : null;
    }
}
