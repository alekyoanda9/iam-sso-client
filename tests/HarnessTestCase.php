<?php

namespace Sd1\IamSso\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;

/** Dipakai bila testbench tidak tersedia: boot aplikasi host dari SSO_TEST_APP. */
abstract class HarnessTestCase extends TestCase
{
    public function createApplication()
    {
        $app = require rtrim(getenv('SSO_TEST_APP'), '/') . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
