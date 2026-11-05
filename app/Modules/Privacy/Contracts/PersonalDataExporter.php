<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Contracts;

/** A module's part of a customer's data export. It returns ONLY what belongs to the one user asked about, never another person's data. */
interface PersonalDataExporter
{
    /** The name of this part in the export file, e.g. "orders". */
    public function section(): string;

    /** @return array<array-key, mixed> */
    public function export(int $userId): array;
}
