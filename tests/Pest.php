<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/Support/helpers.php';

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Architecture', 'Database', '../app/Modules/*/tests');
pest()->use(RefreshDatabase::class)->in('Feature', 'Architecture', '../app/Modules/*/tests/Feature');

// Real top-level transactions / DDL (deadlock retry, triggers, concurrency): data is committed, tables truncated between tests.
pest()->use(DatabaseTruncation::class)->in('Database');
