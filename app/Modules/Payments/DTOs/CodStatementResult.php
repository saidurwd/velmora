<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

/** What recording a courier's payment came to: reconciled, short, over, or pending (the total agrees but a parcel does not). */
final readonly class CodStatementResult
{
    public function __construct(public string $remittancePublicId, public string $status, public int $expectedMinor) {}
}
