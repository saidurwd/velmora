<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Providers;

use App\Modules\Engagement\Notifications\EngagementTemplates;
use App\Modules\Engagement\Privacy\EngagementPersonalData;
use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Privacy\Contracts\PersonalDataRegistry;

class EngagementServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Engagement';
    }

    public function boot(): void
    {
        parent::boot();

        // This module's part of a customer's data export and erasure (the Privacy module asks every module for its own).
        $registry = $this->app->make(PersonalDataRegistry::class);
        $registry->addExporter(EngagementPersonalData::class);
        $registry->addEraser(EngagementPersonalData::class, 50);

        foreach (EngagementTemplates::all() as $template) {
            $this->app->make(TemplateRegistry::class)->register($template);   // the messages this module sends; staff change their words
        }
    }
}
