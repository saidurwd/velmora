<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\SaveAddress;
use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\DigitalProducts\Actions\ImportLicenseKeys;
use App\Modules\DigitalProducts\Actions\UploadDigitalAsset;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Every signed-in customer page and action that names one customer's thing (an order, a return, a review, a ticket, a file, an address, a download, a data copy...)
 * is tried by ANOTHER customer, and by a visitor who is not signed in. Customer A owns one of everything; customer B must get a 404 or 403 (or, for an action that
 * redirects, a redirect that changes nothing), must never see a word of A's data, and must leave every table exactly as it was. A second test fails when a route
 * with an id appears that is neither in this table nor listed as covered somewhere else, so a new page cannot ship without an ownership case.
 */

const OWNER_TABLES = [
    'orders', 'order_items', 'order_cancellation_requests', 'return_requests', 'reviews', 'product_rating_stats', 'support_tickets', 'support_messages', 'support_attachments',
    'privacy_requests', 'wishlist_items', 'stock_alerts', 'customer_addresses', 'entitlements', 'carts', 'cart_items', 'notification_logs',
];

/** Routes with an id that this file does not try, and where each is tried instead. */
const COVERED_ELSEWHERE = [
    'GET {locale}/account/bookings/{booking}' => 'Bookings MyBookingsTest',
    'POST {locale}/account/bookings/{booking}/cancel' => 'Bookings MyBookingsTest',
    'POST {locale}/account/bookings/{booking}/move' => 'Bookings MyBookingsTest',
    'POST {locale}/account/bookings/{booking}/complete' => 'Bookings MyBookingsTest',
];

beforeEach(function () {
    shopWorld($this);
    $this->seed([GeographySeeder::class]);
    $this->withoutMiddleware([EnforceSessionPolicy::class, ThrottleRequests::class]);
    Mail::fake();
    Storage::fake('private');
    config(['engagement.support.disk' => 'private', 'services.sms.driver' => 'fake', 'digital.disk' => 'private']);
    Storage::disk('private')->buildTemporaryUrlsUsing(fn (string $path, $expiration, array $options) => 'https://files.test/'.$path);

    $person = function (string $name, string $email, string $phone): User {
        $user = User::factory()->create(['name' => $name, 'email' => $email, 'phone' => $phone, 'locale' => 'en']);
        app(CustomerProfiles::class)->ensureForUser($user->id, $name, $email, $phone);

        return $user;
    };
    $this->a = $person('Amina Owner', 'amina.owner@example.com', '+8801711111111');
    $this->b = $person('Bob Stranger', 'bob.stranger@example.com', '+8801722222222');

    // The e-book has a real file and a licence key waiting, so a download or a key shown to the wrong person would really happen.
    $staff = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'Amina');
    $file = tempnam(sys_get_temp_dir(), 'own');
    file_put_contents($file, "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n");
    app(UploadDigitalAsset::class)->handle($staff, 'EBOOK-1', $file, 'Book.pdf');
    @unlink($file);
    app(ImportLicenseKeys::class)->handle($staff, 'EBOOK-1', 'AMNA-KEY-AAAA-1111');

    // Customer A owns one of everything.
    $this->done = ($this->orderFor)($this->a->id, [$this->tee['variantId'] => 1, $this->ebook['variantId'] => 1]);
    ($this->completeOrder)($this->done->id);
    $this->paid = ($this->orderFor)($this->a->id, [$this->lamp['variantId'] => 2]);
    ($this->payOrder)($this->paid->id);
    $this->open = ($this->orderFor)($this->a->id, [$this->tee['variantId'] => 1]);   // not paid, not sent: its owner can still cancel it
    $now = now('UTC');
    $line = DB::table('order_items')->where('order_id', $this->done->id)->where('variant_id', $this->tee['variantId'])->first();
    $digitalLine = DB::table('order_items')->where('order_id', $this->done->id)->where('variant_id', $this->ebook['variantId'])->first();

    $this->returnId = strtolower((string) Str::ulid());
    DB::table('return_requests')->insert(['public_id' => $this->returnId, 'order_id' => $this->done->id, 'kind' => 'customer', 'reason_code' => 'changed_mind', 'requested_by_type' => 'customer', 'created_at' => $now, 'updated_at' => $now]);
    $this->cancellationId = strtolower((string) Str::ulid());
    DB::table('order_cancellation_requests')->insert(['public_id' => $this->cancellationId, 'order_id' => $this->paid->id, 'user_id' => $this->a->id, 'created_at' => $now, 'updated_at' => $now]);
    $this->reviewId = strtolower((string) Str::ulid());
    DB::table('reviews')->insert(['public_id' => $this->reviewId, 'product_id' => $line->product_id ?? DB::table('product_variants')->where('id', $this->tee['variantId'])->value('product_id'), 'variant_id' => $this->tee['variantId'], 'order_item_id' => $line->id,
        'user_id' => $this->a->id, 'rating' => 5, 'body' => 'Amina secret review words', 'locale' => 'en', 'created_at' => $now, 'updated_at' => $now]);

    $this->ticketId = strtolower((string) Str::ulid());
    $ticket = DB::table('support_tickets')->insertGetId(['public_id' => $this->ticketId, 'reference' => 'AMNA-0001', 'user_id' => $this->a->id, 'subject' => 'Amina private subject', 'category' => 'other',
        'sla_due_at' => $now->addDay(), 'last_message_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $message = DB::table('support_messages')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'ticket_id' => $ticket, 'author_type' => 'customer', 'author_id' => $this->a->id, 'body' => 'Amina private ticket words', 'created_at' => $now]);
    $this->fileId = strtolower((string) Str::ulid());
    Storage::disk('private')->put('support/amina.png', 'not-really-a-picture');
    DB::table('support_attachments')->insert(['public_id' => $this->fileId, 'message_id' => $message, 'original_name' => 'amina-receipt.png', 'mime' => 'image/webp', 'size_bytes' => 20, 'storage_key' => 'support/amina.png', 'created_at' => $now]);

    $this->exportId = strtolower((string) Str::ulid());
    Storage::disk('private')->put('privacy/amina.json', Crypt::encryptString('{"profile":"amina-export-words"}'));
    DB::table('privacy_requests')->insert(['public_id' => $this->exportId, 'user_id' => $this->a->id, 'type' => 'export', 'status' => 'ready', 'file_key' => 'privacy/amina.json', 'requested_at' => $now, 'expires_at' => $now->addDays(3)]);
    $this->erasureId = strtolower((string) Str::ulid());
    DB::table('privacy_requests')->insert(['public_id' => $this->erasureId, 'user_id' => $this->a->id, 'type' => 'erasure', 'requested_at' => $now]);

    DB::table('wishlist_items')->insert(['user_id' => $this->a->id, 'variant_id' => $this->tee['variantId'], 'created_at' => $now]);
    DB::table('stock_alerts')->insert(['user_id' => $this->a->id, 'variant_id' => $this->lamp['variantId'], 'created_at' => $now, 'updated_at' => $now]);

    $customerId = (int) DB::table('customers')->where('user_id', $this->a->id)->value('id');
    $dhaka = (int) DB::table('divisions')->where('name_en', 'Dhaka')->value('id');
    $district = (int) DB::table('districts')->where('division_id', $dhaka)->where('name_en', 'Dhaka')->value('id');
    $upazila = (int) DB::table('upazilas')->where('district_id', $district)->orderBy('id')->value('id');
    app(SaveAddress::class)->handle($customerId, null, new AddressInput('Amina Owner', '01712345678', $dhaka, $district, $upazila, 'Amina secret house 42', 'Mirpur 10'));
    $this->addressId = (string) DB::table('customer_addresses')->where('customer_id', $customerId)->value('public_id');

    $this->entitlementId = (string) DB::table('entitlements')->where('order_item_id', $digitalLine->id)->value('public_id');   // paying for the order already granted the right to download
    expect($this->entitlementId)->not->toBe('');

    // Words that belong to A alone: they must never appear on a page B receives.
    $this->secrets = [$this->done->order_number, $this->paid->order_number, 'AMNA-0001', 'Amina private subject', 'Amina private ticket words', 'Amina secret review words', 'Amina secret house 42', 'amina-receipt.png', 'amina.owner@example.com', 'amina-export-words', 'AMNA-KEY-AAAA-1111', $this->open->order_number];

    /** Every attempt: [method, uri template, uri, data, a plain GET page A can open (so the control proves the page exists)]. */
    $this->cases = (function (): array {
        $o = $this->done->public_id;
        $p = $this->paid->public_id;
        $tee = $this->tee['publicId'];
        $book = $this->ebook['publicId'];   // A has not reviewed this line, so A can open its form

        return [
            ['GET', '{locale}/account/orders/{order}', "/en/account/orders/{$o}", [], true],
            ['POST', '{locale}/account/orders/{order}/reorder', "/en/account/orders/{$o}/reorder", [], false],
            ['POST', '{locale}/account/orders/{order}/cancel', "/en/account/orders/{$this->open->public_id}/cancel", [], false],
            ['POST', '{locale}/account/orders/{order}/cancellation-request', "/en/account/orders/{$p}/cancellation-request", ['reason' => 'Amina secret'], false],
            ['POST', '{locale}/account/orders/{order}/cancellation-request/{cancellation}/withdraw', "/en/account/orders/{$p}/cancellation-request/{$this->cancellationId}/withdraw", [], false],
            ['GET', '{locale}/account/orders/{order}/return', "/en/account/orders/{$o}/return", [], true],
            ['POST', '{locale}/account/orders/{order}/return', "/en/account/orders/{$o}/return", ['reason' => 'changed_mind', 'lines' => []], false],
            ['POST', '{locale}/account/returns/{return}/withdraw', "/en/account/returns/{$this->returnId}/withdraw", [], false],
            ['GET', '{locale}/account/orders/{order}/reviews/{variant}', "/en/account/orders/{$o}/reviews/{$book}", [], true],
            ['POST', '{locale}/account/orders/{order}/reviews/{variant}', "/en/account/orders/{$o}/reviews/{$book}", ['rating' => 1, 'body' => 'stranger writes on A\'s order'], false],
            ['POST', '{locale}/account/reviews/{review}/withdraw', "/en/account/reviews/{$this->reviewId}/withdraw", [], false],
            ['GET', '{locale}/account/support/{ticket}', "/en/account/support/{$this->ticketId}", [], true],
            ['POST', '{locale}/account/support/{ticket}/reply', "/en/account/support/{$this->ticketId}/reply", ['body' => 'stranger reply'], false],
            ['POST', '{locale}/account/support/{ticket}/close', "/en/account/support/{$this->ticketId}/close", [], false],
            ['GET', '{locale}/account/support/files/{file}', "/en/account/support/files/{$this->fileId}", [], false],
            ['GET', '{locale}/account/privacy/exports/{publicId}/download', "/en/account/privacy/exports/{$this->exportId}/download", [], false],
            ['POST', '{locale}/account/privacy/erasure/{publicId}/cancel', "/en/account/privacy/erasure/{$this->erasureId}/cancel", [], false],
            ['DELETE', '{locale}/account/wishlist/{variant}', "/en/account/wishlist/{$tee}", [], false],
            ['POST', '{locale}/account/wishlist/{variant}/cart', "/en/account/wishlist/{$tee}/cart", [], false],
            ['DELETE', '{locale}/account/stock-alerts/{variant}', '/en/account/stock-alerts/'.$this->lamp['publicId'], [], false],
            ['GET', '{locale}/account/addresses/{address}/edit', "/en/account/addresses/{$this->addressId}/edit", [], true],
            ['PUT', '{locale}/account/addresses/{address}', "/en/account/addresses/{$this->addressId}", ['recipient' => 'Bob', 'phone' => '01712345678', 'address_line' => 'Bob street'], false],
            ['DELETE', '{locale}/account/addresses/{address}', "/en/account/addresses/{$this->addressId}", [], false],
            ['POST', '{locale}/account/addresses/{address}/default', "/en/account/addresses/{$this->addressId}/default", [], false],
            ['POST', '{locale}/account/library/{entitlement}/keys', "/en/account/library/{$this->entitlementId}/keys", [], false],
            ['POST', '{locale}/account/library/{entitlement}/download', "/en/account/library/{$this->entitlementId}/download", [], false],
            ['GET', '{locale}/account/email/confirm/{user}', "/en/account/email/confirm/{$this->a->public_id}", [], false],   // not signed: refused for everyone
        ];
    })->call($this);

    $this->snapshot = function (): string {
        $parts = [];
        foreach (OWNER_TABLES as $table) {
            $parts[] = $table.':'.md5(DB::table($table)->orderBy(DB::raw('1'))->get()->toJson());
        }

        return implode('|', $parts);
    };
});

it('keeps every one of customer A\'s things away from customer B, and from a visitor who is not signed in', function () {
    $failures = [];
    foreach ($this->cases as [$method, $template, $uri, $data, $control]) {
        $label = "{$method} {$uri}";

        if ($control) {   // the owner can open it, so a 404 for B really is about ownership and not about a page that is missing
            $own = $this->flushSession()->withSession(['auth.recently_confirmed_at' => now()->getTimestamp()])->actingAs($this->a, 'web')->get($uri);
            if ($own->status() !== 200) {
                $failures[] = "{$label}: the owner got {$own->status()}, expected 200";
            }
        }

        $before = ($this->snapshot)();
        $response = $this->flushSession()->withSession(['auth.recently_confirmed_at' => now()->getTimestamp()])->actingAs($this->b, 'web')->call($method, $uri, $data);
        $status = $response->status();
        $body = (string) $response->getContent();
        $redirected = in_array($status, [301, 302, 303], true);
        $allowed = $method === 'GET' ? in_array($status, [403, 404], true) : (in_array($status, [403, 404], true) || $redirected);
        if (! $allowed) {
            $failures[] = "{$label}: customer B got {$status}";
        }
        $where = (string) $response->headers->get('Location');
        if ($redirected && preg_match('#^https?://#', $where) === 1 && ! str_starts_with($where, (string) config('app.url'))) {
            $failures[] = "{$label}: customer B was sent to {$where}";
        }
        foreach ($this->secrets as $secret) {
            if (str_contains($body, $secret)) {
                $failures[] = "{$label}: customer B's response contains \"{$secret}\"";
            }
        }
        if (($this->snapshot)() !== $before) {
            $failures[] = "{$label}: customer B's request changed the data";
        }

        auth('web')->logout();
        $guest = $this->flushSession()->call($method, $uri, $data);
        if ($template !== '{locale}/account/email/confirm/{user}' && ! ($guest->isRedirect() && str_contains((string) $guest->headers->get('Location'), '/login'))) {
            $failures[] = "{$label}: a visitor who is not signed in got {$guest->status()}, expected a redirect to sign in";
        }
        if (($this->snapshot)() !== $before) {
            $failures[] = "{$label}: a visitor's request changed the data";
        }
    }

    expect($failures)->toBe([]);
});

it('refuses a stranger and a visitor at every order link meant for a guest (signed link or code), and shows nothing', function () {
    $routes = [];
    foreach (Route::getRoutes() as $route) {
        if (preg_match('#^\{locale\}/orders/\{order\}/#', $route->uri()) === 1) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [$method, $route];
            }
        }
    }
    expect($routes)->not->toBeEmpty();

    $failures = [];
    foreach ($routes as [$method, $route]) {
        $uri = '/en/'.str_replace(['{order}', '{booking}', '{entitlement}'], [$this->done->public_id, $this->done->public_id, $this->entitlementId], substr($route->uri(), strlen('{locale}/')));
        $signed = in_array('signed', $route->gatherMiddleware(), true);
        $before = ($this->snapshot)();
        foreach (['a visitor' => null, 'customer B' => $this->b] as $who => $user) {
            $this->flushSession();
            $response = ($user === null ? $this : $this->actingAs($user, 'web'))->call($method, $uri, ['phone' => '01712345678', 'code' => '123456']);
            $status = $response->status();
            $body = (string) $response->getContent();
            if ($signed && $status !== 403 && ! ($response->isRedirect() && $user === null)) {
                $failures[] = "{$method} {$uri} ({$who}): an unsigned link got {$status}, expected 403";
            }
            if ($status >= 200 && $status < 300 && ! $signed) {
                // a page that asks for a code is allowed; it must hold nothing of A's
                foreach ($this->secrets as $secret) {
                    if (str_contains($body, $secret)) {
                        $failures[] = "{$method} {$uri} ({$who}): shows \"{$secret}\"";
                    }
                }
            }
            foreach ($this->secrets as $secret) {
                if (str_contains($body, $secret)) {
                    $failures[] = "{$method} {$uri} ({$who}): shows \"{$secret}\" (status {$status})";
                }
            }
            auth('web')->logout();
        }
        if (($this->snapshot)() !== $before) {
            $failures[] = "{$method} {$uri}: changed the data";
        }
    }

    expect(array_values(array_unique($failures)))->toBe([]);
});

it('has an ownership case for every customer route that names a thing', function () {
    $tried = [];
    foreach ($this->cases as [$method, $template]) {
        $tried["{$method} {$template}"] = true;
    }

    $missing = [];
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        $isCustomerRoute = str_starts_with($uri, '{locale}/account/');
        if (! $isCustomerRoute || ! str_contains(substr($uri, strlen('{locale}')), '{')) {
            continue;
        }
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $key = "{$method} {$uri}";
            if (! isset($tried[$key]) && ! isset(COVERED_ELSEWHERE[$key])) {
                $missing[] = $key;
            }
        }
    }

    expect($missing)->toBe([], 'add these routes to the ownership table (or to COVERED_ELSEWHERE with where they are tried)');
});

it('never puts an internal number in a customer address', function () {
    foreach ($this->cases as [, , $uri]) {
        $path = explode('?', $uri)[0];
        foreach (array_slice(explode('/', $path), 3) as $segment) {
            expect(preg_match('/^\d+$/', $segment))->toBe(0, "{$path} has the plain number {$segment}");
        }
    }
});
