<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Feature tests render views; skip Vite so tests don't need `npm run build`.
        $this->withoutVite();
    }
}
