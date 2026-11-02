<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\DigitalProducts\Actions\DownloadDigitalFile;
use App\Modules\DigitalProducts\Actions\UploadDigitalAsset;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\Services\BreakdownCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The whole path against the REAL private bucket (SeaweedFS in docker compose): our upload, our download, and the bucket itself still refusing everyone
 * without a signed address. Skipped automatically when the bucket is not reachable (e.g. CI without S3).
 */
beforeEach(function () {
    $endpoint = (string) config('filesystems.disks.private.endpoint');
    $socket = $endpoint !== '' ? @fsockopen((string) parse_url($endpoint, PHP_URL_HOST), (int) parse_url($endpoint, PHP_URL_PORT), $errno, $errstr, 1) : false;
    if (! $socket) {
        $this->markTestSkipped('S3 endpoint not reachable; run `docker compose up -d`.');
    }
    fclose($socket);

    config(['digital.disk' => 'private']);
    $this->keys = [];
});

afterEach(function () {
    foreach ($this->keys ?? [] as $key) {
        try {
            Storage::disk('private')->delete($key);
        } catch (Throwable) {
        }
    }
});

it('stores the file privately, refuses the bucket address to everyone, and hands out a working signed address that saves it under its real name', function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class]);
    $system = Actor::system('t');
    $category = app(SaveCategory::class)->handle($system, null, null, [new TranslationInput('en', 'General')]);
    $variant = (int) app(SaveProduct::class)->handle($system, null, new ProductData(ProductType::Digital, [new TranslationInput('en', 'Book'), new TranslationInput('bn', 'বই')], categoryIds: [$category->id], defaultVariant: new VariantData('BOOK-S3')))->variants()->value('id');
    $order = app(CreateOrder::class)->handle(new NewOrder(Actor::guest('t'), null, 'Rahim', '+8801712345678', null, OrderChannel::Web, 'en', 'online', null, (new BreakdownCalculator)->calculate([['variantId' => $variant, 'quantity' => 1, 'unitMinor' => 35000, 'lineDiscountMinor' => 0, 'taxRateBp' => 0]], false, 0, 0, 0), [new NewOrderLine($variant, 'digital', ['en' => 'Book', 'bn' => 'বই'], 'BOOK-S3')], []));
    app(TransitionOrder::class)->status($system, $order->id, OrderStatus::Confirmed, 'test');
    app(TransitionOrder::class)->payment($system, $order->id, PaymentStatus::Paid, 'test');   // the rights are made through the normal event

    $body = "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n";
    $path = tempnam(sys_get_temp_dir(), 'dg');
    file_put_contents($path, $body);
    $staff = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Amina');
    $version = app(UploadDigitalAsset::class)->handle($staff, 'BOOK-S3', $path, 'রান্নার বই.pdf');
    $this->keys[] = $version->storage_key;
    unlink($path);

    $bucketAddress = rtrim((string) config('filesystems.disks.private.endpoint'), '/').'/'.config('filesystems.disks.private.bucket').'/'.$version->storage_key;
    expect(Storage::disk('private')->exists($version->storage_key))->toBeTrue()->and(@file_get_contents($bucketAddress))->toBeFalse()->and(@get_headers($bucketAddress)[0] ?? '')->toContain('403');

    $right = Entitlement::query()->where('order_id', $order->id)->firstOrFail();
    $link = app(DownloadDigitalFile::class)->handle($right, null, '127.0.0.1', 'test');

    expect(file_get_contents($link->url))->toBe($body)->and($link->remaining)->toBe(4);
    $headers = array_change_key_case((array) get_headers($link->url, true), CASE_LOWER);
    expect((string) ($headers['content-disposition'] ?? ''))->toContain('attachment')->toContain('filename*=UTF-8')->and((string) ($headers['content-type'] ?? ''))->toContain('application/pdf');
    expect(DB::table('download_logs')->where('outcome', 'granted')->count())->toBeGreaterThanOrEqual(1);
});
