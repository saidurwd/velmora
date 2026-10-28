<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string date(\Carbon\CarbonInterface $at, string $style = 'medium')
 * @method static string dateTime(\Carbon\CarbonInterface $at)
 * @method static string relative(\Carbon\CarbonInterface $at)
 * @method static string number(int|float|string $value, int $decimals = 0)
 * @method static string money(\App\Modules\Platform\DTOs\Money $money)
 *
 * @see LocaleFormatter
 */
final class Fmt extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LocaleFormatter::class;
    }
}
