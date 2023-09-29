<?php

namespace Tests;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('pennant.stores.session_and_database', [
            'driver' => 'session_and_database',
            'table' => 'features',
        ]);

        $app['config']->set('pennant.default', 'session_and_database');
    }
}
