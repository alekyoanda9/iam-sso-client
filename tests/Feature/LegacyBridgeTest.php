<?php

namespace Sd1\IamSso\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sd1\IamSso\Branch\BranchConnectionRegistrar;
use Sd1\IamSso\Branch\BranchContext;
use Sd1\IamSso\Bridge\ValueResolver;
use Sd1\IamSso\Catalog\TableCatalogSource;
use Sd1\IamSso\Facades\Sso;
use Sd1\IamSso\Mirror\UserMirror;
use Sd1\IamSso\Tests\TestCase;

/**
 * Bridge generik (config) dengan preset IAS (tests/fixtures/ias-sso.php): hasilnya harus sama
 * dengan adapter IAS lama (IasSessionWriter, IasBranchSessionWriter, SsoIasHook, TbmasterUserMirror,
 * AccessMigrasiCatalogSource) - tanpa satu pun kelas khusus IAS di SDK.
 */
class LegacyBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $preset = require __DIR__ . '/../../examples/ias-sso.php';
        $preset['branch']['connection_options'] = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        config([
            'sso.hook' => \Sd1\IamSso\Bridge\BridgeLoginHook::class,
            'sso.branch' => $preset['branch'],
            'sso.bridge' => $preset['bridge'],
            'sso.mirror' => $preset['mirror'],
            'sso.permission_push' => $preset['permission_push'],
        ]);

        // Koneksi cabang (igrjkt) sudah ada sebelum login, seperti provider koneksi lama IAS.
        $registrar = app(BranchConnectionRegistrar::class);
        $ctx = new BranchContext($this->branchPayload());
        config(['database.connections.igrjkt' => $registrar->connectionConfig($ctx)]);
        $this->createTables('igrjkt');
        DB::connection('igrjkt')->table('tbmaster_perusahaan')->insert(['prs_kodeigr' => '01', 'prs_rptname' => 'IGR JAKARTA', 'prs_nilaippn' => 11]);
        DB::connection('igrjkt')->table('tbmaster_computer')->insert(['ip' => '127.0.0.1', 'useraktif' => null]);
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
            $this->ok(['token' => $this->jwt($this->claims(array_merge(['email' => 'SJM.maya@indogrosir.co.id'], $claims)))]),
            $this->ok($this->accessPayload($codes)),
            $this->ok($this->branchPayload()),
        ]);
        parse_str((string) parse_url($this->get('/sso/login')->headers->get('Location'), PHP_URL_QUERY), $q);

        return $this->get('/sso/callback?code=XYZ&state=' . $q['state'] . '&iam_ctx=CTX');
    }

    public function test_login_fills_legacy_ias_session_from_config_only(): void
    {
        $this->login()->assertRedirect('/');

        $s = session()->all();
        $this->assertSame(['MYS', 'Maya Sari', 'SJM.maya@indogrosir.co.id', 3, 'SJM', [], '2020000006', 'KASIR'],
            [$s['usid'], $s['un'], $s['eml'], $s['userlevel'], $s['usertype'], $s['specialUser'], $s['sso_nik'], $s['sso_role']]);
        $this->assertSame(['igrjkt', '10.1.1.1', 'Jakarta', 'jkt', '01', '10.9.1.10', '10.8.1.10', '5432', 'pwd-01'],
            [$s['connection'], $s['phpIP'], $s['namacabang'], $s['kode'], $s['kodeigr'], $s['dbHostProd'], $s['dbHostSim'], $s['dbPort'], $s['dbPass']]);
        $this->assertSame(['127.0.0.1', '127001', 'http://app.test:3050', 'sso'], [$s['ip'], $s['id'], $s['baseUrlIasApi'], $s['auth_via']]);
        $this->assertSame(['01', 'IGR JAKARTA', 11], [$s['kdigr'], $s['rptname'], (int) $s['ppn']]);
        $this->assertArrayNotHasKey('sessionID', $s, 'pg_backend_pid dilewati di sqlite (required=false)');

        $this->assertSame(['FO005', 'BO027'], collect($s['menu'])->pluck('acc_id')->all(), 'ACTION tidak masuk menu');
        $this->assertSame('Laporan Kasir', $s['menu'][0]->acc_subgroup1);
        $this->assertNull($s['menu'][0]->acc_subgroup2);

        $this->assertSame('MYS', DB::connection('igrjkt')->table('tbmaster_computer')->value('useraktif'));
        $this->assertSame('MYS', DB::connection('igrjkt')->table('tbmaster_perusahaan')->value('prs_modify_by'));
        $this->assertNotNull(DB::connection('igrjkt')->table('tbmaster_perusahaan')->value('prs_periodeterakhir'));
        $mirrored = DB::connection('igrjkt')->table('tbmaster_user')->where('userid', 'MYS')->first();
        $this->assertSame(['01', 'Maya Sari', '2020000006', 3, 'SSO'], [$mirrored->kodeigr, $mirrored->username, $mirrored->nik, (int) $mirrored->userlevel, $mirrored->create_by]);
    }

    public function test_usertype_prefix_rules(): void
    {
        $v = new ValueResolver();
        $spec = 'user.email|string|prefix:SM=SM,SJM=SJM,*=XXX';
        $this->assertSame('SM', $v->resolve($spec, ['user' => ['email' => 'sm.jkt@x.id']]));
        $this->assertSame('SJM', $v->resolve($spec, ['user' => ['email' => 'SJM.budi@x.id']]));
        $this->assertSame('XXX', $v->resolve($spec, ['user' => ['email' => 'maya@x.id']]));
        $this->assertSame('XXX', $v->resolve($spec, ['user' => ['email' => null]]));
    }

    public function test_logout_resets_useraktif_and_clears_legacy_keys(): void
    {
        $this->login();
        session()->put('token', 'api3050');

        $this->get('/sso/logout')->assertRedirect();

        $this->assertSame('', DB::connection('igrjkt')->table('tbmaster_computer')->value('useraktif'));
        foreach (['usid', 'menu', 'connection', 'kdigr', 'auth_via', 'token'] as $key) {
            $this->assertFalse(session()->has($key), $key . ' harus terhapus');
        }
    }

    public function test_missing_user_code_cancels_login_with_message(): void
    {
        $this->login(['ias_user_code' => null])->assertRedirect(route('sso.error'));
        $this->assertFalse(Sso::check());
        $this->assertFalse(session()->has('usid'));
        $this->assertStringContainsString('belum memiliki kode user IAS', session('sso_error'));
    }

    public function test_missing_required_row_cancels_login_with_templated_message(): void
    {
        DB::connection('igrjkt')->table('tbmaster_perusahaan')->delete();

        $this->login()->assertRedirect(route('sso.error'));
        $this->assertFalse(Sso::check());
        $this->assertSame('Data TBMASTER_PERUSAHAAN tidak ditemukan di koneksi igrjkt.', session('sso_error'));
        $this->assertNull(DB::connection('igrjkt')->table('tbmaster_computer')->value('useraktif'), 'statement on_login tidak jalan');
    }

    public function test_sso_disabled_redirects_to_app_login_without_calling_iam(): void
    {
        config(['sso.enabled' => false]);
        $this->mockIam([]);

        $this->get('/sso/login')->assertRedirect(url('/login'))->assertSessionHas('sso_message', 'Login SSO belum diaktifkan di server ini.');
        $this->assertCount(0, $this->history);
    }

    public function test_mutated_branch_user_is_logged_out_on_refresh(): void
    {
        config(['sso.access_refresh' => true]);
        $this->login();
        \Illuminate\Support\Facades\Route::middleware(['web', 'sso.auth'])->get('/t/x', function () {
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

    public function test_mirror_inserts_updates_and_never_touches_password(): void
    {
        $mirror = app(UserMirror::class);
        DB::connection('igrjkt')->table('tbmaster_user')->insert([
            'userid' => 'OLD', 'username' => 'lama', 'userlevel' => 2, 'encryptpwd' => 'HASHLAMA', 'userpassword' => 'pw', 'jabatan' => 'KASIR',
        ]);

        $this->assertSame('updated', $mirror->upsert('igrjkt', '01', ['ias_user_code' => 'OLD', 'name' => 'Nama Yang Sangat Panjang Sekali', 'email' => 'a@b.c', 'nik' => '2020', 'ias_userlevel' => null, 'active' => true]));
        $row = DB::connection('igrjkt')->table('tbmaster_user')->where('userid', 'OLD')->first();
        $this->assertSame(['HASHLAMA', 'pw', 'KASIR'], [$row->encryptpwd, $row->userpassword, $row->jabatan]);
        $this->assertSame(2, (int) $row->userlevel, 'userlevel lama tidak ditimpa null');
        $this->assertSame(15, strlen($row->username));
        $this->assertNull($row->recordid);
        $this->assertSame('SSO', $row->modify_by);

        $this->assertSame('inserted', $mirror->upsert('igrjkt', '01', ['ias_user_code' => 'new', 'name' => 'Baru', 'active' => false]));
        $new = DB::connection('igrjkt')->table('tbmaster_user')->where('userid', 'NEW')->first();
        $this->assertSame('1', $new->recordid, 'nonaktif -> recordid 1');
        $this->assertNull($new->encryptpwd);
        $this->assertSame('skipped', $mirror->upsert('igrjkt', '01', ['ias_user_code' => null]));
        $this->assertSame('skipped', $mirror->upsert('igrjkt', '01', ['ias_user_code' => 'ABCD']), 'kode > 3 karakter');
    }

    public function test_table_catalog_source_dedups_by_latest_and_skips_excluded_urls(): void
    {
        DB::connection('igrjkt')->table('tbmaster_access_migrasi')->insert([
            ['acc_id' => 'BO111', 'acc_name' => 'Lama', 'acc_group' => 'Back Office', 'acc_status' => '0', 'acc_modify_dt' => '2021-05-11 12:29:43', 'acc_url' => '/bo/a', 'acc_level' => 3],
            ['acc_id' => 'BO111', 'acc_name' => 'Baru', 'acc_group' => 'Back Office', 'acc_status' => '0', 'acc_modify_dt' => '2024-03-04 11:33:43', 'acc_url' => '/bo/b', 'acc_level' => 3],
            ['acc_id' => 'BO112', 'acc_name' => 'Mati', 'acc_group' => 'Back Office', 'acc_status' => '1', 'acc_modify_dt' => null, 'acc_url' => '/bo/c', 'acc_level' => 3],
            ['acc_id' => 'A001', 'acc_name' => 'User', 'acc_group' => 'Administration', 'acc_status' => '0', 'acc_modify_dt' => null, 'acc_url' => '/administration/user', 'acc_level' => 2],
            ['acc_id' => 'A002', 'acc_name' => 'User Access', 'acc_group' => 'Administration', 'acc_status' => '0', 'acc_modify_dt' => null, 'acc_url' => '/administration/access', 'acc_level' => 2],
            ['acc_id' => 'A010', 'acc_name' => 'Unlock IP', 'acc_group' => 'Administration', 'acc_status' => '0', 'acc_modify_dt' => null, 'acc_url' => '/administration/unlock-ip', 'acc_level' => 2],
        ]);

        $items = collect(app(TableCatalogSource::class)->items(['connection' => 'igrjkt']))->keyBy('code');

        $this->assertSame(['A010', 'BO111', 'BO112'], $items->keys()->sort()->values()->all());
        $this->assertSame(['Baru', '/bo/b', true, 3], [$items['BO111']['name'], $items['BO111']['url'], $items['BO111']['is_active'], $items['BO111']['level']]);
        $this->assertFalse($items['BO112']['is_active']);
    }

    public function test_permission_push_command_uses_table_source(): void
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
