<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Storage;

/* Shared hosting has no S3: MEDIA_DRIVER=local and PRIVATE_DRIVER=local turn the two buckets into folders. Private files must stay private. */

function disksWith(array $env): array
{
    $repo = Env::getRepository();   // the place a .env file is loaded into
    $before = array_map(fn ($k) => $repo->get($k), array_keys($env));
    foreach ($env as $k => $v) {
        $repo->set($k, $v);
    }
    try {
        return (require base_path('config/filesystems.php'))['disks'];
    } finally {
        foreach (array_keys($env) as $i => $k) {
            $before[$i] === null ? $repo->clear($k) : $repo->set($k, $before[$i]);
        }
    }
}

it('keeps S3 as the default for both buckets and turns them into folders on request', function () {
    $s3 = disksWith(['MEDIA_DRIVER' => 's3', 'PRIVATE_DRIVER' => 's3']);
    $local = disksWith(['MEDIA_DRIVER' => 'local', 'PRIVATE_DRIVER' => 'local', 'APP_URL' => 'https://shop.example']);

    expect($s3['media']['driver'])->toBe('s3')->and($s3['private']['driver'])->toBe('s3')
        ->and($local['media'])->toMatchArray(['driver' => 'local', 'visibility' => 'public', 'url' => 'https://shop.example/storage/media'])
        ->and($local['private'])->toMatchArray(['driver' => 'local', 'visibility' => 'private', 'serve' => true, 'url' => 'https://shop.example/private-files'])
        ->and($local['private']['root'])->toBe(storage_path('app/private/files'))->and($local['private']['root'])->not->toStartWith(public_path());
});

it('serves a private file only through a signed, expiring link, and never by its plain address', function () {
    $disks = disksWith(['MEDIA_DRIVER' => 'local', 'PRIVATE_DRIVER' => 'local', 'APP_URL' => 'http://localhost']);
    $root = sys_get_temp_dir().'/velmora-private-'.bin2hex(random_bytes(4));
    config(['filesystems.disks.private' => ['root' => $root] + $disks['private']]);
    Storage::forgetDisk('private');
    (new FilesystemServiceProvider(app()))->boot();   // the served-disk route exists from boot in a real server (the setting is read then); here the setting arrived after
    app('router')->getRoutes()->refreshNameLookups();
    Storage::disk('private')->put('digital/book.pdf', 'SECRET-BYTES');

    $signed = Storage::disk('private')->temporaryUrl('digital/book.pdf', now()->addMinutes(1));
    $this->get($signed)->assertOk();
    $refused = fn ($response) => expect([403, 404])->toContain($response->getStatusCode())->and($response->getContent())->not->toContain('SECRET-BYTES');   // 403 here, 404 in production: the framework hides it there
    $refused($this->get('/private-files/digital/book.pdf'));
    $refused($this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $signed)));

    $this->travel(2)->minutes();
    $refused($this->get($signed));
    expect(file_exists(public_path('private-files')))->toBeFalse();
    Storage::disk('private')->deleteDirectory('digital');
    @rmdir($root);
});
