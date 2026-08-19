<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Human-paced reply delivery pauses between messages. No test should
        // spend real wall-clock time on that — record the pauses instead, so
        // they can be asserted on without slowing the suite down.
        Sleep::fake();
    }
}
