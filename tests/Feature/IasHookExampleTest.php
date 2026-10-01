<?php

namespace Sd1\IamSso\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Sd1\IamSso\Branch\BranchConnectionRegistrar;
use Sd1\IamSso\Branch\BranchContext;
use Sd1\IamSso\Facades\Sso;
use Sd1\IamSso\Tests\TestCase;

require_once __DIR__ . '/../../examples/IasSsoHook.php';

/**
 * Contoh hook IAS (examples/IasSsoHook.php): semua perilaku khusus aplikasi ditulis sebagai kode,
 * SDK hanya memanggil hook. Koneksi cabang diarahkan ke SQLite in-memory.
 */
class IasHookExampleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['sso.hook' => SqliteIasHook::class]);

        // Koneksi cabang (igrjkt) sudah ada sebelum login, seperti provider koneksi lama IAS.
        $ctx = new BranchContext($this->branchPayload());
        config(['database.connections.igrjkt' => app(BranchConnectionRegistrar::class)->connectionConfig($ctx)]);
        $this->createTables('igrjkt');
        DB::connection('igrjkt')->table('tbmaster_perusahaan')->insert(['prs_kodeigr' => '01', 'prs_rptname' => 'IGR JAKARTA', 'prs_nilaippn' => 11]);
        DB::connection('igrjkt')->table('tbmaster_computer')->insert(['ip' => '127.0.0.1', 'useraktif' => 'XYZ']);
    }

    private function createTables(string $conn): void
    {
        Schema::connection($conn)->create('tbmaster_user', function ($t) {
            foreach (['kodeigr' => 2, 'recordid' => 1, 'userid' => 3, 'userpassword' => 8, 'username' => 15, 'email' => 50, 'create_by' => 3, 'modify_by' => 3, 'encryptpwd' => 100, 'jabatan' => 50, 'nik' => 16] as $c => $len) {
                $t->string($c, $len)->nullable();
            }
            $t->integer('userlevel')->nullable();
            $t->timestamp('create_dt')->nullable();
            $t->timestamp('modify_dt')->nullable();
        });
        Schema::connection($conn)->create('tbmaster_access_migrasi', function ($t) {
            foreach (['acc_group', 'acc_subgroup1', 'acc_subgroup2', 'acc_subgroup3', 'acc_name', 'acc_url', 'acc_id', 'acc_status'] as $c) {
                $t->string($c)->nullable();
            }
            $t->integer('acc_level')->nullable();
            $t->integer('acc_order')->nullable();
            $t->timestamp('acc_create_dt')->nullable();
            $t->timestamp('acc_modify_dt')->nullable();
        });
        Schema::connection($conn)->create('tbmaster_perusahaan', function ($t) {
            $t->string('prs_kodeigr')->nullable();
            $t->string('prs_rptname')->nullable();
            $t->integer('prs_nilaippn')->nullable();
            $t->timestamp('prs_periodeterakhir')->nullable();
            $t->string('prs_modify_by')->nullable();
            $t->timestamp('prs_modify_dt')->nullable();
        });
        Schema::connection($conn)->create('tbmaster_computer', function ($t) {
            $t->string('ip')->nullable();
            $t->string('useraktif')->nullable();
        });
    }

    private function branchPayload(array $override = []): array
    {
        return array_replace_recursive([
            'branch' => ['code' => '01', 'name' => 'INDOGROSIR JAKARTA', 'type' => 'IGR', 'kode' => 'jkt', 'service_name' => 'IGRJKT', 'php_host' => '10.1.1.1'],
            'env' => 'PRODUCTION', 'is_production' => true, 'locked' => false,
            'connection' => ['driver' => 'pgsql', 'host' => '10.9.1.10', 'port' => '5432', 'database' => 'IGRJKT', 'username' => 'igrjkt', 'password' => 'pwd-01', 'schema' => 'igrjkt'],
            'hosts' => ['PRODUCTION' => '10.9.1.10', 'SIMULASI' => '10.8.1.10'],
        ], $override);
    }

    private function login(array $claims = [], array $codes = ['FO005', 'BO027', 'BO190.EXPORT'])
    {
        $this->mockIam([
            $this->jsonResponse(['access_token' => 'AT1', 'refresh_token' => 'RT1', 'expires_in' => 3600]),
            $this->ok(['token' => $this->jwt($this->claims($claims))]),
            $this->ok($this->accessPayload($codes)),
            $this->ok($this->branchPayload()),
        ]);
        parse_str((string) parse_url($this->get('/sso/login')->headers->get('Location'), PHP_URL_QUERY), $q);

        return $this->get('/sso/callback?code=XYZ&state=' . $q['state'] . '&iam_ctx=CTX');
    }

    public function test_login_fills_legacy_ias_session(): void
    {
        $this->login(['email' => 'SM.maya@indogrosir.co.id'])->assertRedirect('/');

        $s = session()->all();
        $this->assertSame(['MYS', 'Maya Sari', 3, 'igrjkt', 'Jakarta', 'jkt', '01', 'sso'], [$s['usid'], $s['un'], $s['userlevel'], $s['connection'], $s['namacabang'], $s['kode'], $s['kodeigr'], $s['auth_via']]);
        $this->assertSame(['01', 'IGR JAKARTA', 11], [$s['kdigr'], $s['rptname'], (int) $s['ppn']]);
        $this->assertSame(['10.9.1.10', '10.8.1.10', '5432', 'pwd-01'], [$s['dbHostProd'], $s['dbHostSim'], $s['dbPort'], $s['dbPass']]);
        $this->assertSame([], $s['specialUser']);
        $this->assertSame('XXX', $s['usertype'], 'usertype TIDAK diturunkan dari email (bisa diubah user sendiri di IAM)');
        $this->assertSame(['FO005', 'BO027'], $s['menu']->pluck('acc_id')->all(), 'ACTION tanpa url tidak masuk menu lama');
        $this->assertSame('/fo/laporan-kasir/penjualan', $s['menu'][0]->acc_url);

        $this->assertSame('MYS', DB::connection('igrjkt')->table('tbmaster_perusahaan')->value('prs_modify_by'));
        $this->assertSame('XYZ', DB::connection('igrjkt')->table('tbmaster_computer')->value('useraktif'), 'tbmaster_computer tidak disentuh');
        $this->assertSame('sm.maya@indogrosir.co.id', strtolower((string) DB::connection('igrjkt')->table('tbmaster_user')->where('userid', 'MYS')->value('email')));
    }

    public function test_usertype_comes_from_iam_claim(): void
    {
        $this->login(['ias_usertype' => 'SJM', 'email' => 'apa.saja@x.id']);
        $this->assertSame('SJM', session('usertype'));
    }

    public function test_user_logged_in_elsewhere_is_not_blocked(): void
    {
        DB::connection('igrjkt')->table('tbmaster_computer')->insert(['ip' => '10.0.0.9', 'useraktif' => 'MYS']);
        $this->login()->assertRedirect('/');
        $this->assertTrue(Sso::check());
    }

    public function test_logout_clears_legacy_keys_only(): void
    {
        $this->login();
        session(['lain' => 'tetap']);
        $this->mockIam([]);
        $this->get('/sso/logout');
        $this->assertNull(session('usid'));
        $this->assertSame('XYZ', DB::connection('igrjkt')->table('tbmaster_computer')->value('useraktif'));
    }

    public function test_missing_user_code_cancels_login_with_message(): void
    {
        $this->login(['ias_user_code' => null])->assertRedirect(route('sso.error'));
        $this->assertFalse(Sso::check());
        $this->assertSame('Akun Anda belum memiliki kode user IAS. Hubungi admin SSO.', session('sso_error'));
    }

    public function test_missing_perusahaan_cancels_login(): void
    {
        DB::connection('igrjkt')->table('tbmaster_perusahaan')->delete();
        $this->login()->assertRedirect(route('sso.error'));
        $this->assertFalse(Sso::check());
        $this->assertNull(session('usid'));
        $this->assertSame('Data TBMASTER_PERUSAHAAN tidak ditemukan di koneksi igrjkt.', session('sso_error'));
    }

    public function test_mutated_branch_user_is_logged_out_on_refresh(): void
    {
        config(['sso.access_refresh' => true]);
        $this->login();
        Route::middleware(['web', 'sso.auth'])->get('/t/x', function () {
            return 'ok';
        });
        Sso::session()->put(['checked_at' => time() - 3600]);
        $this->mockIam([
            $this->ok(['perm_version' => '2.2', 'active' => true]),
            $this->ok(['token' => $this->jwt($this->claims(['branch_code' => '02']))]),
            $this->ok($this->accessPayload(['FO005'], '2.2')),
        ]);

        $this->get('/t/x')->assertRedirect(route('sso.logout'));
    }

    public function test_mirror_never_touches_password_or_existing_email(): void
    {
        $hook = app(SqliteIasHook::class);
        DB::connection('igrjkt')->table('tbmaster_user')->insert([
            'userid' => 'OLD', 'username' => 'lama', 'email' => 'admin.set@x.id', 'userlevel' => 2, 'encryptpwd' => 'HASHLAMA', 'userpassword' => 'pw', 'jabatan' => 'KASIR',
        ]);

        $this->assertSame('updated', $hook->mirrorUser('igrjkt', '01', ['ias_user_code' => 'OLD', 'name' => 'Nama Yang Sangat Panjang Sekali', 'email' => 'SM.naik@x.id', 'nik' => '2020', 'ias_userlevel' => null, 'active' => true]));
        $row = DB::connection('igrjkt')->table('tbmaster_user')->where('userid', 'OLD')->first();
        $this->assertSame(['HASHLAMA', 'pw', 'KASIR', 'admin.set@x.id'], [$row->encryptpwd, $row->userpassword, $row->jabatan, $row->email]);
        $this->assertSame(2, (int) $row->userlevel, 'userlevel lama tidak ditimpa null');
        $this->assertSame(15, strlen($row->username));
        $this->assertSame(['2020', null, 'SSO'], [$row->nik, $row->recordid, $row->modify_by]);

        $this->assertSame('inserted', $hook->mirrorUser('igrjkt', '01', ['ias_user_code' => 'new', 'name' => 'Baru', 'active' => false]));
        $new = DB::connection('igrjkt')->table('tbmaster_user')->where('userid', 'NEW')->first();
        $this->assertSame(['1', 'SSO'], [$new->recordid, $new->create_by]);
        $this->assertNull($new->encryptpwd);
        $this->assertSame('skipped', $hook->mirrorUser('igrjkt', '01', ['ias_user_code' => null]));
        $this->assertSame('skipped', $hook->mirrorUser('igrjkt', '01', ['ias_user_code' => 'ABCD']), 'kode > 3 karakter');
    }

    public function test_mirror_deactivates_sso_created_duplicate_after_legacy_code_is_linked(): void
    {
        $hook = app(SqliteIasHook::class);
        // Sempat login dengan kode baru K7P (dibuat mirror), lalu atasan menautkan kode lama BDI di IAM.
        $hook->mirrorUser('igrjkt', '01', ['ias_user_code' => 'K7P', 'name' => 'Budi', 'nik' => '2021', 'active' => true]);
        DB::connection('igrjkt')->table('tbmaster_user')->insert(['userid' => 'BDI', 'username' => 'budi', 'encryptpwd' => 'H', 'create_by' => 'ADM']);
        DB::connection('igrjkt')->table('tbmaster_user')->insert(['userid' => 'LMA', 'username' => 'lama', 'nik' => '2021', 'create_by' => 'ADM']);

        $hook->mirrorUser('igrjkt', '01', ['ias_user_code' => 'BDI', 'name' => 'Budi', 'nik' => '2021', 'ias_userlevel' => 3, 'active' => true]);

        $rows = DB::connection('igrjkt')->table('tbmaster_user')->orderBy('userid')->get()->keyBy('userid');
        $this->assertSame(['2021', null], [$rows['BDI']->nik, $rows['BDI']->recordid]);
        $this->assertSame('1', $rows['K7P']->recordid, 'baris kode baru buatan SSO dinonaktifkan');
        $this->assertNull($rows['LMA']->recordid, 'baris buatan admin tidak disentuh');
    }

    public function test_catalog_dedups_by_latest_and_skips_excluded_urls(): void
    {
        DB::connection('igrjkt')->table('tbmaster_access_migrasi')->insert([
            ['acc_id' => 'BO111', 'acc_name' => 'Lama', 'acc_group' => 'Back Office', 'acc_status' => '0', 'acc_modify_dt' => '2021-05-11 12:29:43', 'acc_url' => '/bo/a', 'acc_level' => 3],
            ['acc_id' => 'BO111', 'acc_name' => 'Baru', 'acc_group' => 'Back Office', 'acc_status' => '0', 'acc_modify_dt' => '2024-03-04 11:33:43', 'acc_url' => '/bo/b', 'acc_level' => 3],
            ['acc_id' => 'BO112', 'acc_name' => 'Mati', 'acc_group' => 'Back Office', 'acc_status' => '1', 'acc_modify_dt' => null, 'acc_url' => '/bo/c', 'acc_level' => 3],
            ['acc_id' => 'A001', 'acc_name' => 'User', 'acc_group' => 'Administration', 'acc_status' => '0', 'acc_modify_dt' => null, 'acc_url' => '/administration/user', 'acc_level' => 2],
            ['acc_id' => 'A002', 'acc_name' => 'User Access', 'acc_group' => 'Administration', 'acc_status' => '0', 'acc_modify_dt' => null, 'acc_url' => '/administration/access/', 'acc_level' => 2],
            ['acc_id' => 'A010', 'acc_name' => 'Unlock IP', 'acc_group' => 'Administration', 'acc_status' => '0', 'acc_modify_dt' => null, 'acc_url' => '/administration/unlock-ip', 'acc_level' => 2],
        ]);

        $items = collect(app(SqliteIasHook::class)->items(['connection' => 'igrjkt']))->keyBy('code');

        $this->assertSame(['A010', 'BO111', 'BO112'], $items->keys()->sort()->values()->all());
        $this->assertSame(['Baru', '/bo/b', true, 3], [$items['BO111']['name'], $items['BO111']['url'], $items['BO111']['is_active'], $items['BO111']['level']]);
        $this->assertFalse($items['BO112']['is_active']);
    }

    public function test_permission_push_command_uses_hook(): void
    {
        DB::connection('igrjkt')->table('tbmaster_access_migrasi')->insert([
            'acc_id' => 'FO005', 'acc_name' => 'Penjualan', 'acc_group' => 'Front Office', 'acc_status' => '0', 'acc_url' => '/fo/laporan-kasir/penjualan', 'acc_level' => 1,
        ]);

        $this->artisan('sso:permission-push', ['--connection' => 'igrjkt', '--dry-run' => true])->assertExitCode(0);
        $this->assertCount(0, $this->history);

        $this->mockIam([$this->ok(['created' => 1, 'updated' => 0, 'deactivated' => 0, 'skipped' => 0, 'perm_version' => 5])]);
        $this->artisan('sso:permission-push', ['--connection' => 'igrjkt'])->assertExitCode(0);
        $req = $this->history[0]['request'];
        $this->assertSame('Basic ' . base64_encode('7:rahasia'), $req->getHeaderLine('Authorization'));
        $this->assertSame('FO005', json_decode((string) $req->getBody(), true)['items'][0]['code']);
    }

    public function test_commands_refuse_hook_without_contract(): void
    {
        config(['sso.hook' => \Sd1\IamSso\Support\NullLoginHook::class]);
        $this->artisan('sso:permission-push', ['--dry-run' => true])->assertExitCode(1);
        $this->artisan('sso:mirror-users', ['--branch' => '01', '--connection' => ['igrjkt']])->assertExitCode(1);
        $this->assertCount(0, $this->history);
    }

    public function test_mirror_users_command(): void
    {
        $this->mockIam([$this->ok(['server_time' => '2026-09-29T10:00:00+07:00', 'users' => [
            ['ias_user_code' => 'MYS', 'name' => 'Maya', 'email' => 'm@x.id', 'nik' => '2020000006', 'ias_userlevel' => 3, 'active' => true],
            ['ias_user_code' => 'AGP', 'name' => 'Agus', 'email' => 'a@x.id', 'nik' => '2021000007', 'ias_userlevel' => null, 'active' => false],
        ]])]);

        $this->artisan('sso:mirror-users', ['--branch' => '01', '--connection' => ['igrjkt']])->assertExitCode(0);

        $this->assertSame('branch=01', parse_url((string) $this->history[0]['request']->getUri(), PHP_URL_QUERY));
        $this->assertSame(2, DB::connection('igrjkt')->table('tbmaster_user')->count());
        $this->assertSame('1', DB::connection('igrjkt')->table('tbmaster_user')->where('userid', 'AGP')->value('recordid'));
    }
}

/** Hook contoh IAS, koneksi cabang dialihkan ke SQLite in-memory. */
class SqliteIasHook extends \App\Sso\IasSsoHook
{
    public function connectionConfig(BranchContext $branch, array $default): array
    {
        return array_merge($default, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }
}
