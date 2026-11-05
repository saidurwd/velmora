<?php

declare(strict_types=1);

namespace App\Modules\Cart\Privacy;

use App\Modules\Privacy\Contracts\PersonalDataEraser;
use Illuminate\Support\Facades\DB;

/** A signed-in customer's shopping cart goes when they are erased (it holds nothing worth keeping). */
final class CartPersonalData implements PersonalDataEraser
{
    public function section(): string
    {
        return 'cart';
    }

    public function blockers(int $userId): array
    {
        return [];
    }

    public function erase(int $userId): void
    {
        DB::table('carts')->where('user_id', $userId)->delete();   // unique user_id; its lines go with it (cascade)
    }
}
