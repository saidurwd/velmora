<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\DTOs\Recipient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells a customer about their own privacy request, by email and text message where we have the address. A problem is only logged (never with the details):
 * the request itself is what matters and is shown on the customer's page.
 */
final class PrivacyMessages
{
    public function __construct(private readonly NotificationSender $sender, private readonly CustomerProfiles $profiles) {}

    /** @return array{phone: string|null, email: string|null}  how to reach a customer, taken BEFORE their details are erased */
    public function contactOf(int $userId): array
    {
        $profile = $this->profiles->findByUserId($userId);

        return ['phone' => $profile?->phone, 'email' => $profile?->email];
    }

    /**
     * @param  array{phone: string|null, email: string|null}  $contact
     * @param  array<string, mixed>  $variables
     */
    public function tell(string $what, array $contact, string $locale, array $variables, string $key, ?int $userId = null): void
    {
        foreach (['email' => $contact['email'], 'sms' => $contact['phone']] as $channel => $address) {
            if ($address === null || $address === '') {
                continue;
            }
            try {
                $this->sender->send("privacy.{$what}.{$channel}", new Recipient($channel === 'sms' ? $address : null, $channel === 'email' ? $address : null, $locale, $userId), $variables + ['shop' => (string) config('app.name')], $key);
            } catch (Throwable $e) {
                Log::warning('privacy.message_not_recorded', ['message' => "privacy.{$what}.{$channel}", 'error' => $e::class]);
            }
        }
    }
}
