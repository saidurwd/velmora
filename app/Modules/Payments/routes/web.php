<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\Web\PaymentReturnController;
use App\Modules\Payments\Http\Controllers\Web\PayOrderController;
use Illuminate\Support\Facades\Route;

// Both are reached only through links WE signed. The gateway posts the customer's browser back from another site (so there is no
// CSRF token and no session cookie), which is why the return route is exempt from CSRF in bootstrap/app.php and relies on the signature.
Route::localized(function (): void {
    Route::match(['get', 'post'], '/pay/return/{payment}/{outcome}', [PaymentReturnController::class, 'handle'])
        ->whereIn('outcome', ['success', 'fail', 'cancel'])->middleware(['signed', 'throttle:public-read'])->name('payments.return');
    Route::get('/pay/order/{order}', [PayOrderController::class, 'start'])->middleware(['signed', 'throttle:critical'])->name('payments.pay');
});
