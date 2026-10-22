<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create the application, then abort before RefreshDatabase if the
     * resolved database is not SQLite :memory:.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        TestDatabaseGuard::ensure($app->make('config'));
        TestDatabaseGuard::protectApplication($app);

        return $app;
    }
}
