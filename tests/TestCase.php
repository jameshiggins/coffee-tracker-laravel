<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Authenticate as the admin operator for /admin/* feature tests:
     * configures the env credential pair and pre-sets the session flag that
     * AdminSessionAuth checks. Centralized here because three admin test
     * files used to carry identical copies of a Basic-auth header builder
     * (2026-07 review, reuse finding).
     */
    protected function actingAsAdmin(): static
    {
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);

        return $this->withSession(['admin_authenticated' => true]);
    }
}
