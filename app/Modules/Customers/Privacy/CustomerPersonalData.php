<?php

declare(strict_types=1);

namespace App\Modules\Customers\Privacy;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Privacy\Contracts\PersonalDataEraser;
use App\Modules\Privacy\Contracts\PersonalDataExporter;
use Illuminate\Support\Facades\DB;

/** The customer's profile and saved addresses: copied into their data export, and removed when they are erased. */
final class CustomerPersonalData implements PersonalDataEraser, PersonalDataExporter
{
    public function __construct(private readonly Clock $clock) {}

    public function section(): string
    {
        return 'profile';
    }

    public function export(int $userId): array
    {
        $customer = DB::table('customers')->where('user_id', $userId)->first();   // unique user_id
        if ($customer === null) {
            return ['profile' => null, 'addresses' => []];
        }
        $addresses = DB::table('customer_addresses')->where('customer_id', $customer->id)->orderBy('id')->get()   // index customer_id
            ->map(fn ($a) => ['label' => $a->label, 'recipient' => $a->recipient, 'phone' => $a->phone, 'area' => $a->area, 'address' => $a->address_line, 'landmark' => $a->landmark, 'delivery_note' => $a->delivery_note, 'postal_code' => $a->postal_code, 'default' => (bool) $a->is_default])->all();

        return [
            'profile' => ['name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone, 'birth_date' => $customer->birth_date, 'created_at' => $customer->created_at],
            'addresses' => $addresses,
        ];
    }

    public function blockers(int $userId): array
    {
        return [];
    }

    public function erase(int $userId): void
    {
        $customer = DB::table('customers')->where('user_id', $userId)->first();
        if ($customer === null) {
            return;
        }
        DB::table('customer_addresses')->where('customer_id', $customer->id)->delete();
        DB::table('customers')->where('id', $customer->id)->whereNull('anonymised_at')->update([
            'name' => null, 'phone' => null, 'email' => null, 'birth_date' => null, 'marketing_consent' => false, 'marketing_consent_at' => null,
            'anonymised_at' => $this->clock->now(), 'updated_at' => $this->clock->now(),
        ]);
    }
}
