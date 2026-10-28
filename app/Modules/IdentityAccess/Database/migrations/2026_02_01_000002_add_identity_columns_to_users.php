<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique()->after('id');
            $table->string('phone', 16)->nullable()->unique()->after('email'); // E.164
            $table->dateTime('phone_verified_at', 6)->nullable()->after('email_verified_at');
            $table->string('account_type', 16)->default('customer')->after('password');
            $table->string('locale', 5)->default('en')->after('account_type');
            $table->string('status', 16)->default('active')->after('locale');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('status');
            $table->dateTime('locked_until', 6)->nullable()->after('failed_login_attempts');
            $table->dateTime('last_login_at', 6)->nullable()->after('locked_until');

            $table->index(['account_type', 'status']); // admin staff lists
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['account_type', 'status']);
            $table->dropUnique(['public_id']);
            $table->dropUnique(['phone']);
            $table->dropColumn(['public_id', 'phone', 'phone_verified_at', 'account_type', 'locale', 'status', 'failed_login_attempts', 'locked_until', 'last_login_at']);
        });
    }
};
