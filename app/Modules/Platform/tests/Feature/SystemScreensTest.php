<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* System screens: the queue monitor and About this installation. Counts, kinds and versions only: never a job's data, an address, a key or a password. */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
});

describe('the queue monitor', function () {
    it('shows each queue, the failures of the last day by kind (never their data) and the outbox', function () {
        foreach (['App\\Modules\\Notifications\\Jobs\\DeliverNotificationJob', 'App\\Modules\\Notifications\\Jobs\\DeliverNotificationJob', 'App\\Modules\\Reporting\\Jobs\\RefreshCohortsJob'] as $i => $job) {
            DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => $job, 'data' => ['phone' => '+8801712345678']]), 'exception' => 'boom', 'failed_at' => now()->subMinutes($i)]);
        }
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'OldJob']), 'exception' => 'x', 'failed_at' => now()->subDays(3)]);
        DB::table('outbox_messages')->insert(['public_id' => strtolower((string) Str::ulid()), 'event_name' => 'x.y', 'payload' => '{}', 'status' => 'failed', 'attempts' => 10, 'available_at' => now(), 'created_at' => now()]);

        $html = (string) $this->actingAs(catalogStaff(['queues.view']), 'admin')->get('/admin/ops/queues')->assertOk()->getContent();

        expect($html)->toContain('Queue monitor')->toContain('>default<')->toContain('>low<')->toContain('DeliverNotificationJob')->toContain('RefreshCohortsJob')
            ->not->toContain('OldJob')->not->toContain('+8801712345678')   // only the last day, and never the job's data
            ->toContain('All failed jobs (4)');
        $this->actingAs(catalogStaff(['system.view']), 'admin')->get('/admin/ops/queues')->assertForbidden();
    });
});

describe('about', function () {
    it('tells the release, the software and the services by kind, and never a secret', function () {
        config(['payments.sslcommerz.store_password' => 'S3cret-store-pass', 'services.sms.sslwireless.api_token' => 'S3cret-sms-token', 'mail.mailers.smtp.password' => 'S3cret-mail']);

        $html = (string) $this->actingAs(catalogStaff(['system.view']), 'admin')->get('/admin/ops/about')->assertOk()->getContent();

        expect($html)->toContain('About')->toContain('Laravel '.app()->version())->toContain('PHP '.PHP_VERSION)->toContain('Test (sandbox)')
            ->toContain('IdentityAccess')->toContain('laravel/framework')->toContain('alpinejs')->toContain('MIT')
            ->not->toContain('S3cret')->not->toContain((string) config('app.key'));
        $this->actingAs(catalogStaff(['queues.view']), 'admin')->get('/admin/ops/about')->assertForbidden();
    });

    it('reads in Bangla', function () {
        $staff = catalogStaff(['system.view']);
        $staff->forceFill(['locale' => 'bn'])->save();

        $this->actingAs($staff->fresh(), 'admin')->get('/admin/ops/about')->assertOk()->assertSee('পরিচিতি')->assertSee('পরীক্ষামূলক (স্যান্ডবক্স)');
    });
});

it('puts security and system screens in their own menu areas', function () {
    $html = (string) $this->actingAs(catalogStaff(['audit.view', 'system.view', 'queues.view']), 'admin')->get('/admin/security/logins')->assertOk()->getContent();

    expect($html)->toMatch('/data-nav-area="security"\s+open/')->toContain('data-nav-area="system"')
        ->toContain('>Login history<')->toContain('>Queue monitor<')->toContain('>About<');
});
