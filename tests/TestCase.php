<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::flush();   // settings are memoised per process; each test starts clean
        \Illuminate\Support\Facades\Http::preventStrayRequests();   // no real network calls (Google, Distru) from tests
    }
}
