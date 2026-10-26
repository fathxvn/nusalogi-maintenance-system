<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");
        $databaseUrl = $app['config']->get("database.connections.{$connection}.url");

        if (! $app->environment('testing') || $connection !== 'mysql' || $database !== 'testing' || filled($databaseUrl)) {
            throw new RuntimeException('Tests require the separate MySQL testing database without a database URL.');
        }

        return $app;
    }
}
