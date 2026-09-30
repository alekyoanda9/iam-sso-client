<?php

namespace Sd1\IamSso\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Sd1\IamSso\Branch\BranchContext;
use Sd1\IamSso\Facades\Sso;
use Sd1\IamSso\Branch\BranchConnectionRegistrar;
use Sd1\IamSso\Tests\RecordingHook;
use Sd1\IamSso\Tests\TestCase;

/**
 * Login multi-cabang: IAM menambahkan iam_ctx di callback, SDK menukarnya di
 * /api/me/branch-context lalu mendaftarkan koneksi DB cabang.
 */
class BranchLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'sso.auth'])->get('/t/db', function () {
            return (string) config('database.connections.' . Sso::connectionName() . '.host');
        });
        app('router')->getRoutes()->refreshNameLookups();
    }

    private function branchPayload(array $override = []): array
    {
        return array_replace_recursive([
            'branch' => ['code' => '50', 'name' => 'SPI BEKASI', 'type' => 'SPI', 'kode' => 'spibks', 'service_name' => 'SPIBKS', 'php_host' => '10.1.50.1'],
            'env' => 'PRODUCTION',
            'is_production' => true,
            'locked' => false,
            'connection' => ['driver' => 'pgsql', 'host' => '10.9.50.10', 'port' => '5432', 'database' => 'SPIBKS', 'username' => 'spibks', 'password' => 'pwd-50', 'schema' => 'spibks'],
            'hosts' => ['PRODUCTION' => '10.9.50.10', 'SIMULASI' => '10.8.50.10'],
        ], $override);
    }

    private function loginWithBranch(array $payload, $ctx = 'CTX123', array $claims = ['role_scope' => 'HO', 'branch_code' => '00', 'branch_type' => null])
    {
        $this->mockIam([
            $this->jsonResponse(['access_token' => 'AT1', 'refresh_token' => 'RT1', 'expires_in' => 3600]),
            $this->ok(['token' => $this->jwt($this->claims($claims))]),
            $this->ok($this->accessPayload(['FO005', 'BO068'])),
            $payload === [] ? $this->jsonResponse(['status' => 'error', 'message' => 'Konteks cabang sudah dipakai atau kedaluwarsa.'], 403) : $this->ok($payload),
        ]);
        parse_str((string) parse_url($this->get('/sso/login')->headers->get('Location'), PHP_URL_QUERY), $q);

        return $this->get('/sso/callback?code=XYZ&state=' . $q['state'] . ($ctx ? '&iam_ctx=' . $ctx : ''));
    }

    public function test_callback_fetches_branch_context_and_registers_connection(): void
    {
        $this->loginWithBranch($this->branchPayload())->assertRedirect('/');

        $req = $this->history[3]['request'];
        $this->assertSame('/api/me/branch-context', $req->getUri()->getPath());
        $this->assertSame('ctx=CTX123', $req->getUri()->getQuery());
        $this->assertSame('Bearer AT1', $req->getHeaderLine('Authorization'));

        $branch = Sso::branch();
        $this->assertInstanceOf(BranchContext::class, $branch);
        $this->assertSame(['50', 'SPI', 'PRODUCTION', 'spibks'], [$branch->code(), $branch->type(), $branch->env(), $branch->kode()]);
        $this->assertSame('SPI', Sso::activeBranchType(), 'menu difilter sesuai cabang yang dimasuki, bukan cabang user HO');
        $this->assertFalse(Sso::canUrl('/bo/monitoring-stok-pareto'), 'BO068 khusus IGR');

        $this->assertSame('sso_branch', Sso::connectionName());
        $conn = config('database.connections.sso_branch');
        $this->assertSame(['pgsql', '10.9.50.10', 'SPIBKS', 'spibks', 'pwd-50', 'spibks'], [$conn['driver'], $conn['host'], $conn['database'], $conn['username'], $conn['password'], $conn['schema']]);

        // Password DB tidak tersimpan polos di sesi; JSON tidak membocorkannya.
        $this->assertStringNotContainsString('pwd-50', json_encode(session()->all()));
        $this->assertStringNotContainsString('pwd-50', json_encode($branch));
        $this->assertSame([['login', '2020000006', ['FO005']]], RecordingHook::$calls, 'hook menerima permission yang sudah difilter tipe SPI');
    }

    public function test_connection_is_registered_again_on_next_request(): void
    {
        $this->loginWithBranch($this->branchPayload());
        config(['database.connections.sso_branch' => null]); // request baru: config belum ada

        $this->get('/t/db')->assertOk()->assertSee('10.9.50.10');
    }

    private function useIasNaming(): void
    {
        $preset = require __DIR__ . '/../../examples/ias-sso.php';
        config(['sso.branch' => array_merge(config('sso.branch'), $preset['branch'], ['enabled' => false])]);
    }

    public function test_pattern_connection_naming_follows_legacy_ias_names(): void
    {
        $this->useIasNaming();
        $this->loginWithBranch($this->branchPayload());
        $this->assertSame('spibks', Sso::connectionName(), 'SPI + PRODUCTION = kode saja');
        $this->assertSame('10.9.50.10', config('database.connections.spibks.host'));

        $registrar = app(BranchConnectionRegistrar::class);
        $name = function (array $override) use ($registrar) {
            return $registrar->name(new BranchContext($this->branchPayload($override)));
        };
        $this->assertSame('simspibks', $name(['env' => 'SIMULASI']));
        $this->assertSame('igrjkt', $name(['branch' => ['kode' => 'jkt', 'type' => 'IGR']]));
        $this->assertSame('simjkt', $name(['branch' => ['kode' => 'jkt', 'type' => 'IGR'], 'env' => 'SIMULASI']));
        $this->assertSame('icmxyz', $name(['branch' => ['kode' => 'icmxyz', 'type' => 'ICM']]));

        config(['sso.branch.connection_name' => 'sso_branch']);
        $this->assertSame('sso_branch', $name([]), 'nama tetap');
    }

    public function test_existing_config_for_same_db_is_kept(): void
    {
        $this->useIasNaming();
        // Provider koneksi lama IAS sudah mendaftarkan 'spibks' (dengan kunci tambahan 'ip').
        config(['database.connections.spibks' => ['driver' => 'pgsql', 'host' => '10.9.50.10', 'port' => '5432', 'database' => 'SPIBKS',
            'username' => 'spibks', 'password' => 'pwd-50', 'ip' => '10.1.50.1']]);

        $this->loginWithBranch($this->branchPayload());

        $this->assertSame('10.1.50.1', config('database.connections.spibks.ip'), 'config lama yang menunjuk DB sama tidak ditimpa');
    }

    public function test_required_branch_login_without_ctx_fails(): void
    {
        config(['sso.branch.enabled' => true]);

        $this->loginWithBranch($this->branchPayload(), null)->assertRedirect(route('sso.error'));
        $this->assertFalse(Sso::check());
        $this->assertStringContainsString('Login multi-cabang', session('sso_error'));
    }

    public function test_client_without_branch_login_has_no_branch(): void
    {
        $this->loginAs()->assertRedirect('/');
        $this->assertNull(Sso::branch());
        $this->assertNull(Sso::connectionName());
        $this->assertCount(3, $this->history, 'tidak ada panggilan branch-context');
    }

    public function test_rejected_context_shows_iam_message(): void
    {
        $this->loginWithBranch([])->assertRedirect(route('sso.error'));
        $this->assertFalse(Sso::check());
        $this->assertStringContainsString('sudah dipakai atau kedaluwarsa', session('sso_error'));
    }
}
