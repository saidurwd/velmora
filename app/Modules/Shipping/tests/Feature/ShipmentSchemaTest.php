<?php

declare(strict_types=1);

use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Shipping\Contracts\CourierProvider;
use App\Modules\Shipping\Couriers\ManualCourier;
use App\Modules\Shipping\Database\Seeders\CourierSeeder;
use App\Modules\Shipping\DTOs\ShipmentRequest;
use App\Modules\Shipping\Enums\ShipmentStatus;
use App\Modules\Shipping\Exceptions\CourierException;
use App\Modules\Shipping\Models\CourierAccount;
use App\Modules\Shipping\Services\CourierRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The database is the last line of defence for parcels: these tests go straight at the tables and check that impossible status changes,
 * rewrites, deletes and double bookings are refused no matter what the application does.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, CourierSeeder::class]);
    fakeOrders([1, 2]);
    $this->account = (int) DB::table('courier_accounts')->value('id');
    $this->refuses = fn (callable $statement) => expect($statement)->toThrow(QueryException::class);

    $this->shipment = fn (array $over = []) => DB::table('shipments')->insertGetId($over + [
        'public_id' => strtolower((string) Str::ulid()), 'order_id' => 1, 'courier_account_id' => $this->account, 'courier' => 'manual', 'status' => 'created',
        'cod_amount_minor' => 0, 'currency' => 'BDT', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    /** An order line (the foreign key to a catalog variant is switched off for this one insert: the schema test is about shipments). */
    $this->line = function (int $orderId = 1, int $quantity = 3): int {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $id = DB::table('order_items')->insertGetId(['order_id' => $orderId, 'variant_id' => 999999, 'product_type' => 'physical', 'name_snapshot' => '{}', 'sku_snapshot' => 'X', 'quantity' => $quantity, 'unit_price_minor' => 1000, 'discount_minor' => 0, 'tax_rate_bp' => 0, 'tax_minor' => 0, 'line_total_minor' => 1000 * $quantity, 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return $id;
    };
    $this->status = fn (int $id) => DB::table('shipments')->where('id', $id)->value('status');
    $this->move = fn (int $id, string $to) => DB::table('shipments')->where('id', $id)->update(['status' => $to] + (in_array($to, ['delivered']) ? ['delivered_at' => now()] : []) + (! in_array($to, ['created', 'cancelled']) ? ['handed_over_at' => DB::raw('COALESCE(handed_over_at, NOW(6))')] : []));
});

describe('shipments', function () {
    it('accepts a normal parcel and refuses nonsense', function () {
        expect(($this->shipment)())->toBeInt();

        ($this->refuses)(fn () => ($this->shipment)(['status' => 'lost']));
        ($this->refuses)(fn () => ($this->shipment)(['cod_amount_minor' => -1]));
        ($this->refuses)(fn () => ($this->shipment)(['order_id' => 99999]));
        ($this->refuses)(fn () => ($this->shipment)(['courier_account_id' => 99999]));
        ($this->refuses)(fn () => ($this->shipment)(['status' => 'delivered']));   // delivered needs its delivery time
        ($this->refuses)(fn () => ($this->shipment)(['status' => 'in_transit']));  // a parcel past "created" needs its handover time
    });

    it('lets a courier\'s parcel belong to one shipment only, but allows many without an id yet', function () {
        ($this->shipment)(['courier_consignment_id' => 'C-1']);
        ($this->shipment)();
        ($this->shipment)();

        ($this->refuses)(fn () => ($this->shipment)(['courier_consignment_id' => 'C-1']));
        expect(($this->shipment)(['courier_consignment_id' => 'C-1', 'courier' => 'other']))->toBeInt();   // another courier may reuse the number
    });

    it('lets the courier\'s id be set once and never changed, and the tracking code be corrected', function () {
        $id = ($this->shipment)();

        expect(DB::table('shipments')->where('id', $id)->update(['courier_consignment_id' => 'C-9']))->toBe(1)
            ->and(DB::table('shipments')->where('id', $id)->update(['tracking_code' => 'TRK-1']))->toBe(1)
            ->and(DB::table('shipments')->where('id', $id)->update(['tracking_code' => 'TRK-2']))->toBe(1);
        ($this->refuses)(fn () => DB::table('shipments')->where('id', $id)->update(['courier_consignment_id' => 'C-10']));
        ($this->refuses)(fn () => DB::table('shipments')->where('id', $id)->update(['courier_consignment_id' => null]));
    });

    it('can never be deleted, and its fixed facts never change', function () {
        $id = ($this->shipment)(['cod_amount_minor' => 120000]);

        ($this->refuses)(fn () => DB::table('shipments')->where('id', $id)->delete());
        ($this->refuses)(fn () => DB::table('shipments')->where('id', $id)->update(['cod_amount_minor' => 1]));
        ($this->refuses)(fn () => DB::table('shipments')->where('id', $id)->update(['order_id' => 2]));
        ($this->refuses)(fn () => DB::table('shipments')->where('id', $id)->update(['courier' => 'pathao']));
        ($this->refuses)(fn () => DB::table('shipments')->where('id', $id)->update(['public_id' => 'x']));
    });

    // The answer must equal the enum's own rule. If someone changes one and not the other, this fails.
    it('allows exactly the status moves the enum allows, and no others', function () {
        foreach (ShipmentStatus::cases() as $from) {
            foreach (ShipmentStatus::cases() as $to) {
                if ($from === $to) {
                    continue;
                }
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                $id = ($this->shipment)(['status' => $from->value] + ($from === ShipmentStatus::Created || $from === ShipmentStatus::Cancelled ? [] : ['handed_over_at' => now()]) + ($from === ShipmentStatus::Delivered ? ['delivered_at' => now()] : []));
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
                $extra = ($to === ShipmentStatus::Delivered ? ['delivered_at' => now()] : []) + (! in_array($to, [ShipmentStatus::Created, ShipmentStatus::Cancelled], true) ? ['handed_over_at' => DB::raw('COALESCE(handed_over_at, NOW(6))')] : []);

                try {
                    DB::table('shipments')->where('id', $id)->update(['status' => $to->value] + $extra);
                    $moved = true;
                } catch (QueryException) {
                    $moved = false;
                }

                expect($moved)->toBe($from->canMoveTo($to), "{$from->value} -> {$to->value}");
            }
        }
    });

    it('can be cancelled only before it is handed over, and never goes back to created', function () {
        $a = ($this->shipment)();
        $b = ($this->shipment)();
        ($this->move)($b, 'in_transit');

        expect(($this->move)($a, 'cancelled'))->toBe(1);
        ($this->refuses)(fn () => ($this->move)($b, 'cancelled'));
        ($this->refuses)(fn () => DB::table('shipments')->where('id', $b)->update(['status' => 'created']));
        ($this->refuses)(fn () => ($this->move)($a, 'handed_over'));
    });

    it('agrees with the enum about what is final and what has left the shop', function () {
        expect(array_values(array_map(fn (ShipmentStatus $s) => $s->value, array_filter(ShipmentStatus::cases(), fn (ShipmentStatus $s) => $s->isFinal()))))->toEqualCanonicalizing(['delivered', 'returned', 'cancelled'])
            ->and(ShipmentStatus::Created->hasLeft())->toBeFalse()->and(ShipmentStatus::Cancelled->hasLeft())->toBeFalse()->and(ShipmentStatus::HandedOver->hasLeft())->toBeTrue();
    });
});

describe('what is in a parcel', function () {
    it('holds lines with a positive quantity, once per order line, and never changes', function () {
        $ship = ($this->shipment)();
        $line = ($this->line)();
        $add = fn (array $over = []) => DB::table('shipment_items')->insertGetId($over + ['shipment_id' => $ship, 'order_item_id' => $line, 'quantity' => 2, 'created_at' => now()]);
        $id = $add();

        ($this->refuses)(fn () => $add());
        ($this->refuses)(fn () => $add(['order_item_id' => ($this->line)(), 'quantity' => 0]));
        ($this->refuses)(fn () => $add(['order_item_id' => 99999]));
        ($this->refuses)(fn () => DB::table('shipment_items')->where('id', $id)->update(['quantity' => 3]));
        ($this->refuses)(fn () => DB::table('shipment_items')->where('id', $id)->delete());
    });
});

describe('what happened to a parcel', function () {
    it('keeps each event once and never changes or deletes it', function () {
        $ship = ($this->shipment)();
        $event = fn (array $over = []) => DB::table('shipment_events')->insertGetId($over + ['shipment_id' => $ship, 'provider_status' => 'Picked up', 'internal_status' => 'handed_over', 'occurred_at' => '2026-11-01 10:00:00.000000', 'received_at' => now(), 'source' => 'webhook']);
        $id = $event();

        ($this->refuses)(fn () => $event());   // the same event delivered twice
        expect($event(['occurred_at' => '2026-11-01 11:00:00.000000']))->toBeInt()->and($event(['provider_status' => 'In transit', 'internal_status' => 'in_transit']))->toBeInt();
        ($this->refuses)(fn () => $event(['source' => 'carrier-pigeon', 'provider_status' => 'x']));
        ($this->refuses)(fn () => $event(['internal_status' => 'lost', 'provider_status' => 'y']));
        ($this->refuses)(fn () => DB::table('shipment_events')->where('id', $id)->update(['internal_status' => 'delivered']));
        ($this->refuses)(fn () => DB::table('shipment_events')->where('id', $id)->delete());
    });
});

describe('courier accounts and the status map', function () {
    it('keeps credentials encrypted at rest and out of every array and JSON', function () {
        $account = CourierAccount::query()->create(['public_id' => strtolower((string) Str::ulid()), 'courier' => 'steadfast', 'label' => 'Main', 'credentials' => ['api_key' => 'KEY-SECRET-123', 'secret_key' => 'SHH-456']]);

        $raw = (string) DB::table('courier_accounts')->where('id', $account->id)->value('credentials');
        $fresh = CourierAccount::query()->findOrFail($account->id);
        expect($raw)->not->toContain('KEY-SECRET-123')->not->toContain('SHH-456')->and($fresh->credentials)->toBe(['api_key' => 'KEY-SECRET-123', 'secret_key' => 'SHH-456'])
            ->and(json_encode($fresh))->not->toContain('KEY-SECRET')->and($fresh->toArray())->not->toHaveKey('credentials');
    });

    it('refuses the same account twice and a badly named courier', function () {
        $row = fn (array $over = []) => DB::table('courier_accounts')->insertGetId($over + ['public_id' => strtolower((string) Str::ulid()), 'courier' => 'steadfast', 'label' => 'Main', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $row();

        ($this->refuses)(fn () => $row());
        ($this->refuses)(fn () => $row(['courier' => 'Steadfast Ltd', 'label' => 'x']));
        expect($row(['label' => 'Second']))->toBeInt();
    });

    it('seeds the manual courier once and never overwrites the owner\'s edits', function () {
        DB::table('courier_accounts')->where('courier', 'manual')->update(['label' => 'Manual / own delivery', 'is_active' => 0]);
        $this->seed(CourierSeeder::class);
        $this->seed(CourierSeeder::class);

        expect(DB::table('courier_accounts')->where('courier', 'manual')->count())->toBe(1)->and((bool) DB::table('courier_accounts')->where('courier', 'manual')->value('is_active'))->toBeFalse();
    });

    it('maps a courier\'s word to one meaning, and only to a meaning a courier can report', function () {
        $map = fn (array $over = []) => DB::table('courier_status_map')->insertGetId($over + ['courier' => 'steadfast', 'provider_status' => 'in_review', 'internal_status' => 'handed_over', 'created_at' => now(), 'updated_at' => now()]);
        $map();

        ($this->refuses)(fn () => $map());
        ($this->refuses)(fn () => $map(['provider_status' => 'x', 'internal_status' => 'cancelled']));
        ($this->refuses)(fn () => $map(['provider_status' => 'y', 'internal_status' => 'created']));
        expect($map(['courier' => 'pathao']))->toBeInt();
    });
});

describe('the courier contract', function () {
    it('finds couriers by name and says clearly when one is unknown', function () {
        $registry = app(CourierRegistry::class);

        expect($registry->has('manual'))->toBeTrue()->and($registry->for('manual'))->toBeInstanceOf(CourierProvider::class)->and($registry->couriers())->toContain('manual')->and($registry->has('nope'))->toBeFalse();
        expect(fn () => $registry->for('nope'))->toThrow(CourierException::class, 'unknown_courier');
    });

    it('builds an adapter once, and lets a test replace it', function () {
        $registry = new CourierRegistry;
        $built = 0;
        $registry->register('x', function () use (&$built) {
            $built++;

            return new ManualCourier;
        });

        $registry->for('x');
        $registry->for('x');
        expect($built)->toBe(1);
    });

    it('has a manual courier that talks to nobody and loses nothing', function () {
        $manual = app(CourierRegistry::class)->for('manual');
        $request = new ShipmentRequest('01H', 'VM-2611-000001', 'Rahim', '+8801712345678', 'House 5', 'Dhaka', null, '1205', 2, 500, 120000, 'BDT');

        $booking = $manual->createShipment($request);

        expect($manual->name())->toBe('manual')->and($manual->capabilities()->createsConsignment)->toBeFalse()->and($manual->capabilities()->webhook)->toBeFalse()->and($manual->capabilities()->codHandling)->toBeTrue()
            ->and($booking->consignmentId)->toBeNull()->and($manual->track('anything'))->toBe([]);
        $manual->cancelShipment('anything');
        expect(fn () => $manual->parseWebhook(Request::create('/x', 'POST')))->toThrow(CourierException::class, 'not_supported');
    });
});
