<?php

declare(strict_types=1);

use App\Modules\Platform\Logging\RedactLogs;
use App\Modules\Platform\Support\Redactor;
use Illuminate\Support\Facades\Log;

it('masks sensitive keys at any depth', function () {
    $out = Redactor::array(['user' => 'a', 'password' => 'x', 'nested' => ['api_key' => 'k', 'Authorization' => 'Bearer t', 'ok' => 1], 'otp_code' => '123456']);

    expect($out)->toBe(['user' => 'a', 'password' => '[redacted]', 'nested' => ['api_key' => '[redacted]', 'Authorization' => '[redacted]', 'ok' => 1], 'otp_code' => '[redacted]']);
});

it('masks credentials in URLs and query strings (e.g. gateway store_passwd)', function () {
    $text = Redactor::string('GET https://sandbox.example/validator?val_id=1&store_id=shop&store_passwd=s3cret&format=json and https://u:pw123@host/x');

    expect($text)->not->toContain('s3cret')->not->toContain('pw123')->toContain('store_id=shop')->toContain('val_id=1');
});

it('masks Bangladeshi phone numbers in any format', function (string $raw) {
    expect(Redactor::string("customer {$raw} called"))->toBe('customer +88017****5678 called');
})->with(['01712345678', '+8801712345678', '8801712345678']);

it('masks card-like numbers but leaves short numbers alone', function () {
    expect(Redactor::string('card 4111 1111 1111 1111 ok'))->toBe('card [card] ok')
        ->and(Redactor::string('order 202610 qty 12'))->toBe('order 202610 qty 12');
});

it('redacts and writes JSON lines through the log channel tap', function () {
    $path = storage_path('framework/testing/redact-'.uniqid().'.log');
    config(['logging.channels.redact_test' => ['driver' => 'single', 'path' => $path, 'tap' => [RedactLogs::class]]]);
    $logger = Log::channel('redact_test');

    $logger->info('login for 01712345678', ['password' => 'hunter2', 'otp' => '654321', 'note' => 'ok']);

    $line = json_decode((string) file_get_contents($path), true);
    @unlink($path);

    expect($line['message'])->toBe('login for +88017****5678')
        ->and($line['context']['password'])->toBe('[redacted]')
        ->and($line['context']['otp'])->toBe('[redacted]')
        ->and($line['context']['note'])->toBe('ok');
});
