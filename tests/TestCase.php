<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(sprintf(
                'Refusing to run tests against [%s/%s]. Tests must use sqlite :memory: to protect local data.',
                $connection,
                $database
            ));
        }
    }
}
