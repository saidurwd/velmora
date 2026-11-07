<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use Illuminate\Contracts\Auth\Authenticatable;

/** Every report, once. A person sees (and can export) only the reports whose permissions they hold. */
final class ReportCatalogue
{
    /** @var list<class-string<ReportDefinition>> */
    private const REPORTS = [
        SalesByDay::class, PaymentMethods::class, ChannelsAndLanguages::class, RefundsAndCancellations::class, CodAging::class, LowStock::class, StockAging::class,
    ];

    /** @var list<ReportDefinition> reports added after the built-in ones */
    private array $extra = [];

    /** Adds a report (for a module that has its own, or a test). */
    public function register(ReportDefinition $report): void
    {
        $this->extra[] = $report;
    }

    /** @return list<ReportDefinition> */
    public function all(): array
    {
        return [...array_map(fn (string $class): ReportDefinition => app($class), self::REPORTS), ...$this->extra];
    }

    public function find(string $code): ?ReportDefinition
    {
        foreach ($this->all() as $report) {
            if ($report->code() === $code) {
                return $report;
            }
        }

        return null;
    }

    /** @return list<ReportDefinition> */
    public function visibleFor(?Authenticatable $user): array
    {
        return array_values(array_filter($this->all(), fn (ReportDefinition $r): bool => $this->allowed($user, $r)));
    }

    public function allowed(?Authenticatable $user, ReportDefinition $report): bool
    {
        if ($user === null || ! method_exists($user, 'can')) {
            return false;
        }
        foreach ($report->permissions() as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }
}
