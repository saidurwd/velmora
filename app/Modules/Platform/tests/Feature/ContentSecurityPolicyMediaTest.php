<?php

declare(strict_types=1);

/*
 * Images are allowed from 'self' and https: only. A developer's media store is plain http (local S3 on another port, or APP_URL with local files), so outside
 * production its one address is allowed as well; otherwise every image is blocked on a dev site. Production never gets an http image source.
 */

function imgSrcWith(array $env): string
{
    // Set directly where env() reads from: the environment store will not overwrite a value phpunit set (APP_ENV), so its set() would be ignored for that one.
    $put = function (string $k, ?string $v): void {
        if ($v === null) {
            unset($_SERVER[$k], $_ENV[$k]);
            putenv($k);
        } else {
            $_SERVER[$k] = $_ENV[$k] = $v;
            putenv("{$k}={$v}");
        }
    };
    $before = [];
    foreach ($env as $k => $v) {
        $before[$k] = [$_SERVER[$k] ?? null, $_ENV[$k] ?? null, getenv($k) === false ? null : getenv($k)];
        $put($k, $v);
    }
    try {
        $policy = (string) (require base_path('config/security.php'))['csp']['policy'];
    } finally {
        foreach ($before as $k => [$server, $envVar, $process]) {   // put back exactly what each of the three places held
            $process === null ? putenv($k) : putenv("{$k}={$process}");
            if ($server === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $server;
            }
            if ($envVar === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $envVar;
            }
        }
    }

    return (string) collect(explode(';', $policy))->map(fn ($d) => trim($d))->first(fn ($d) => str_starts_with($d, 'img-src'));
}

it('allows the local S3 address on a developer machine, and nothing more', function () {
    expect(imgSrcWith(['APP_ENV' => 'local', 'MEDIA_DRIVER' => 's3', 'MEDIA_PUBLIC_URL' => null, 'AWS_ENDPOINT' => 'http://127.0.0.1:9010']))
        ->toBe("img-src 'self' data: https: http://127.0.0.1:9010");
    expect(imgSrcWith(['APP_ENV' => 'local', 'MEDIA_DRIVER' => 's3', 'MEDIA_PUBLIC_URL' => 'http://media.test:8080/velmora-media', 'AWS_ENDPOINT' => 'http://127.0.0.1:9010']))
        ->toBe("img-src 'self' data: https: http://media.test:8080");   // the public address wins, path dropped
    expect(imgSrcWith(['APP_ENV' => 'local', 'MEDIA_DRIVER' => 'local', 'APP_URL' => 'http://localhost:8000']))
        ->toBe("img-src 'self' data: https: http://localhost:8000");
});

it('never adds an http image source in production, nor for an https or odd address', function () {
    expect(imgSrcWith(['APP_ENV' => 'production', 'MEDIA_DRIVER' => 's3', 'MEDIA_PUBLIC_URL' => null, 'AWS_ENDPOINT' => 'http://127.0.0.1:9010']))->toBe("img-src 'self' data: https:")
        ->and(imgSrcWith(['APP_ENV' => 'local', 'MEDIA_DRIVER' => 's3', 'MEDIA_PUBLIC_URL' => 'https://cdn.example.com', 'AWS_ENDPOINT' => null]))->toBe("img-src 'self' data: https:")
        ->and(imgSrcWith(['APP_ENV' => 'local', 'MEDIA_DRIVER' => 's3', 'MEDIA_PUBLIC_URL' => null, 'AWS_ENDPOINT' => null]))->toBe("img-src 'self' data: https:")
        ->and(imgSrcWith(['APP_ENV' => 'local', 'MEDIA_DRIVER' => 's3', 'MEDIA_PUBLIC_URL' => 'http://x;script-src *', 'AWS_ENDPOINT' => null]))->toBe("img-src 'self' data: https:");
});
