<?php

namespace Tests\Feature;

use Tests\TestCase;

class ManilaDateTimeConfigurationTest extends TestCase
{
    public function test_mysql_connection_is_configured_for_utc_without_changing_sqlite(): void
    {
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertArrayNotHasKey('timezone', config('database.connections.sqlite'));
    }
}
