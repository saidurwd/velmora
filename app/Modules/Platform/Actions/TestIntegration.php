<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\TestOutcome;
use App\Modules\Platform\Exceptions\SettingsException;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\IntegrationTests;

/** Runs one provider's connection test and records that it was run and what it found (never any credential). */
final class TestIntegration
{
    public function __construct(private readonly IntegrationTests $tests, private readonly AuditLogger $audit) {}

    /** @throws SettingsException */
    public function handle(Actor $actor, string $provider): TestOutcome
    {
        $this->tests->has($provider) || throw SettingsException::unknown();

        $outcome = $this->tests->for($provider)->run();
        $this->audit->record('integrations.tested', 'integration', [], ['provider' => $provider, 'outcome' => $outcome->code], $actor, $provider);

        return $outcome;
    }
}
