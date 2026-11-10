<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

use App\Modules\Platform\DTOs\TestOutcome;

/**
 * A safe "does it work?" check of one provider's saved credentials, owned by the module that talks to the provider. It must never create a payment,
 * send a message to a customer or touch a live service; it returns a short code and never echoes a credential.
 */
interface IntegrationTest
{
    /** The provider it checks: one of the IntegrationCatalogue providers. */
    public function provider(): string;

    public function run(): TestOutcome;
}
