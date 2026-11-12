<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Api;

use App\Modules\Notifications\Actions\SaveMessagePreferences;
use App\Modules\Notifications\Http\Requests\SavePreferencesApiRequest;
use App\Modules\Notifications\Services\PreferenceReader;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What marketing the customer wants, and their quiet hours (Dhaka time). Messages about their own orders are always sent. Only ever their own answers. */
class PreferenceController
{
    private const TYPE = 'array{data: array{marketing: array{email: bool, sms: bool}, quiet_hours: array{from: string|null, to: string|null}, history: list<array{channel: string, granted: bool, source: string, at: string}>}}';

    /**
     * My message preferences
     *
     * `history` is the last ten changes (what, when, from where).
     */
    #[ResponseDoc(status: 200, type: self::TYPE)]
    public function show(Request $request, PreferenceReader $reader): JsonResponse
    {
        return response()->json($this->payload($reader->forUser((int) $request->user()?->getAuthIdentifier())));
    }

    /**
     * Save my message preferences
     *
     * Send both `email` and `sms` every time (true = yes, please). `quiet_from` and `quiet_to` (`HH:MM`, Dhaka time) come together or not at all; marketing messages wait out these hours.
     */
    #[ResponseDoc(status: 200, type: self::TYPE)]
    public function update(SavePreferencesApiRequest $request, SaveMessagePreferences $save, PreferenceReader $reader): JsonResponse
    {
        $userId = (int) $request->user()?->getAuthIdentifier();
        $save->handle($userId, ['email' => $request->boolean('email'), 'sms' => $request->boolean('sms')], $request->validated('quiet_from'), $request->validated('quiet_to'));  // a choice the customer makes in their own account, as on the website

        return response()->json($this->payload($reader->forUser($userId)));
    }

    /**
     * @param  array{optIn: array<string, bool>, quietFrom: string|null, quietTo: string|null, history: array<int, array{channel: string, granted: bool, source: string, at: string}>}  $p
     * @return array<string, mixed>
     */
    private function payload(array $p): array
    {
        return ['data' => ['marketing' => ['email' => $p['optIn']['email'] ?? false, 'sms' => $p['optIn']['sms'] ?? false], 'quiet_hours' => ['from' => $p['quietFrom'], 'to' => $p['quietTo']], 'history' => array_values($p['history'])]];
    }
}
