<?php

/*
 * Test SDK bisa jalan dua cara:
 *  1. Normal: `composer install` di repo ini -> Orchestra Testbench dipakai.
 *  2. Tanpa testbench: set SSO_TEST_APP=/path/aplikasi-laravel yang sudah memuat SDK
 *     (autoload + SsoServiceProvider), lalu `vendor/bin/phpunit` dari aplikasi itu.
 */
$own = __DIR__ . '/../vendor/autoload.php';
$host = getenv('SSO_TEST_APP') ? rtrim(getenv('SSO_TEST_APP'), '/') . '/vendor/autoload.php' : null;

require file_exists($own) ? $own : $host;

if (class_exists('Orchestra\Testbench\TestCase')) {
    class_alias('Orchestra\Testbench\TestCase', 'Sd1\IamSso\Tests\BaseTestCase');
} else {
    class_alias('Sd1\IamSso\Tests\HarnessTestCase', 'Sd1\IamSso\Tests\BaseTestCase');
}
