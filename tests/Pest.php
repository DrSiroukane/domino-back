<?php

declare(strict_types=1);

// Pest setup: feature tests run on the Laravel test case (HTTP, database, events).
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
