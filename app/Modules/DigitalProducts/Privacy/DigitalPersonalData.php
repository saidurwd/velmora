<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Privacy;

use App\Modules\DigitalProducts\Services\LibraryReader;
use App\Modules\Orders\Contracts\CustomerOrders;
use App\Modules\Privacy\Contracts\PersonalDataEraser;
use App\Modules\Privacy\Contracts\PersonalDataExporter;
use Illuminate\Support\Facades\DB;

/**
 * A customer's download rights and download history go into their data export (not the files, and not the keys themselves). On erasure the address and browser
 * recorded with each download are cleared; the history of what was downloaded stays, without a person attached.
 */
final class DigitalPersonalData implements PersonalDataEraser, PersonalDataExporter
{
    public function __construct(private readonly CustomerOrders $orders, private readonly LibraryReader $library) {}

    public function section(): string
    {
        return 'downloads';
    }

    public function export(int $userId): array
    {
        $rights = array_map(fn (array $r): array => ['order' => $r['order'], 'product' => $r['product'], 'sku' => $r['sku'], 'version' => $r['version'], 'unlimited' => $r['unlimited'], 'downloads_left' => $r['left'], 'state' => $r['state']],
            $this->library->forOrders($this->orders->orderIdsOwnedBy($userId), 'en'));
        $history = DB::table('download_logs')->where('user_id', $userId)->orderBy('id')->limit(1000)->get(['outcome', 'created_at'])   // index user_id (FK)
            ->map(fn ($l) => ['outcome' => $l->outcome, 'at' => $l->created_at])->all();

        return ['rights' => $rights, 'history' => $history];
    }

    public function blockers(int $userId): array
    {
        return [];
    }

    public function erase(int $userId): void
    {
        DB::table('download_logs')->where('user_id', $userId)->where(fn ($q) => $q->whereNotNull('ip')->orWhereNotNull('user_agent'))->update(['ip' => null, 'user_agent' => null]);
    }
}
