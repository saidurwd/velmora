<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

/** Needs the compose `s3` service (SeaweedFS). Skipped automatically when it is not reachable (e.g. CI without S3). */
beforeEach(function () {
    $endpoint = parse_url((string) config('filesystems.disks.private.endpoint'), PHP_URL_HOST) ? config('filesystems.disks.private.endpoint') : null;
    $socket = $endpoint ? @fsockopen((string) parse_url($endpoint, PHP_URL_HOST), (int) parse_url($endpoint, PHP_URL_PORT), $errno, $errstr, 1) : false;

    if (! $socket) {
        $this->markTestSkipped('S3 endpoint not reachable; run `docker compose up -d`.');
    }

    fclose($socket);
    $this->key = 'tests/'.uniqid().'.txt';
});

afterEach(function () {
    foreach (['private', 'media'] as $disk) {
        try {
            Storage::disk($disk)->delete($this->key ?? 'x');
        } catch (Throwable) {
        }
    }
});

it('keeps private objects unreachable anonymously but available through short-lived signed URLs (BD §9)', function () {
    Storage::disk('private')->put($this->key, 'TOP-SECRET');
    $base = rtrim((string) config('filesystems.disks.private.endpoint'), '/').'/'.config('filesystems.disks.private.bucket').'/'.$this->key;

    expect(@file_get_contents($base))->toBeFalse()
        ->and(@get_headers($base)[0] ?? '')->toContain('403');

    $signed = Storage::disk('private')->temporaryUrl($this->key, now()->addMinutes(5));
    expect(file_get_contents($signed))->toBe('TOP-SECRET');
});

it('rejects an expired signed URL', function () {
    Storage::disk('private')->put($this->key, 'TOP-SECRET');
    $signed = Storage::disk('private')->temporaryUrl($this->key, now()->addSeconds(1));
    sleep(3);

    expect(@get_headers($signed)[0] ?? '')->toContain('403');
});

it('serves public media from the media bucket', function () {
    Storage::disk('media')->put($this->key, 'PUBLIC-OK', 'public');
    $url = rtrim((string) config('filesystems.disks.media.endpoint'), '/').'/'.config('filesystems.disks.media.bucket').'/'.$this->key;

    expect(file_get_contents($url))->toBe('PUBLIC-OK');
});
