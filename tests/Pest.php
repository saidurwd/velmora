<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Architecture', '../app/Modules/*/tests');
pest()->use(RefreshDatabase::class)->in('Feature', '../app/Modules/*/tests/Feature');
