<?php

namespace Xstrm\Xstrm\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Xstrm\Xstrm\XstrmServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [XstrmServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('xstrm.dsn', 'https://sk_live_test@ingest.test/17');
    }
}
