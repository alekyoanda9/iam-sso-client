<?php

namespace Sd1\IamSso\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Sd1\IamSso\Http\IamClient;
use Sd1\IamSso\Jwt\JwtVerifier;
use Sd1\IamSso\Jwt\PublicKeyProvider;
use Sd1\IamSso\SsoManager;
use Sd1\IamSso\SsoServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /** @var string */
    protected $privateKey;

    /** @var string */
    protected $publicKey;

    /** @var array riwayat request Guzzle */
    protected $history = [];

    /** @var MockHandler */
    protected $mock;

    protected function getPackageProviders($app)
    {
        return [SsoServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $this->publicKey = openssl_pkey_get_details($key)['key'];

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'session.driver' => 'array',
            'cache.default' => 'array',
            'sso.base_url' => 'http://iam.test',
            'sso.client_id' => '7',
            'sso.client_secret' => 'rahasia',
            'sso.redirect_uri' => 'http://app.test/sso/callback',
            'sso.jwt.public_key' => $this->publicKey,
            'sso.jwt.issuer' => 'http://iam.test',
            'sso.version_check_seconds' => 60,
            'sso.fail_open' => true,
            'sso.hook' => RecordingHook::class,
        ]);
        // Pakai default paket (aplikasi host bisa punya config/sso.php sendiri).
        $defaults = require __DIR__ . '/../config/sso.php';
        foreach (['enabled', 'disabled_redirect', 'access_refresh'] as $key) {
            config(['sso.' . $key => $defaults[$key]]);
        }
        RecordingHook::$calls = [];
        RecordingHook::$loginResponse = null;
        $this->mockIam([]);
    }

    /** Antrekan respons IAM (urutan sesuai panggilan). */
    protected function mockIam(array $responses)
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);
        $this->app->bind('sso.http', function () use ($client) {
            return $client;
        });
        foreach ([IamClient::class, PublicKeyProvider::class, JwtVerifier::class, SsoManager::class, 'sso'] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('sso');
    }

    protected function jsonResponse($data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
    }

    protected function ok($data): Response
    {
        return $this->jsonResponse(['status' => 'success', 'message' => '', 'data' => $data]);
    }

    protected function claims(array $override = []): array
    {
        return array_merge([
            'iss' => 'http://iam.test',
            'aud' => '7',
            'sub' => 'abc',
            'iat' => time(),
            'exp' => time() + 3600,
            'nik' => '2020000006',
            'name' => 'Maya Sari',
            'email' => 'maya@indogrosir.co.id',
            'ias_user_code' => 'MYS',
            'ias_userlevel' => 3,
            'branch_code' => '01',
            'branch_type' => 'IGR',
            'role_code' => 'KASIR',
            'role_scope' => 'BRANCH',
            'pv' => '1.1',
        ], $override);
    }

    protected function jwt(array $claims, ?string $privateKey = null, string $alg = 'RS256'): string
    {
        $b64 = function ($v) {
            return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        };
        $head = $b64(json_encode(['typ' => 'JWT', 'alg' => $alg]));
        $body = $b64(json_encode($claims));
        openssl_sign($head . '.' . $body, $sig, $privateKey ?: $this->privateKey, OPENSSL_ALGO_SHA256);

        return $head . '.' . $body . '.' . $b64($sig);
    }

    protected function accessPayload(array $codes = ['FO005'], string $version = '1.1'): array
    {
        $catalog = [
            'FO005' => ['code' => 'FO005', 'type' => 'MENU', 'name' => 'Penjualan', 'url' => '/fo/laporan-kasir/penjualan', 'group' => 'Front Office', 'subgroup1' => 'Laporan Kasir', 'branch_types' => null],
            'BO027' => ['code' => 'BO027', 'type' => 'MENU', 'name' => 'Month End', 'url' => '/bo/proses/monthend', 'group' => 'Back Office', 'subgroup1' => 'Proses', 'branch_types' => null],
            'BO068' => ['code' => 'BO068', 'type' => 'MENU', 'name' => 'Monitoring', 'url' => '/bo/monitoring-stok-pareto', 'group' => 'Back Office', 'branch_types' => ['IGR']],
            'BO190.EXPORT' => ['code' => 'BO190.EXPORT', 'type' => 'ACTION', 'name' => 'Export', 'url' => null, 'group' => null, 'branch_types' => null],
        ];

        return [
            'authz_mode' => 'MANAGED',
            'perm_version' => $version,
            'role' => ['code' => 'KASIR'],
            'app_role' => ['name' => 'Kasir'],
            'permissions' => array_values(array_intersect_key($catalog, array_flip($codes))),
        ];
    }

    /** Login penuh lewat /sso/login + /sso/callback dengan respons IAM palsu. */
    protected function loginAs(array $claims = [], array $codes = ['FO005'], string $version = '1.1')
    {
        $this->mockIam([
            $this->jsonResponse(['access_token' => 'AT1', 'refresh_token' => 'RT1', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            $this->ok(['token' => $this->jwt($this->claims($claims))]),
            $this->ok($this->accessPayload($codes, $version)),
        ]);
        $location = $this->get('/sso/login')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        return $this->get('/sso/callback?code=XYZ&state=' . $q['state']);
    }
}
