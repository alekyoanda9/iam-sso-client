<?php

namespace Sd1\IamSso\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sd1\IamSso\Access\PermissionSet;
use Sd1\IamSso\Ias\AccessMigrasiCatalogSource;
use Sd1\IamSso\Ias\IasSessionWriter;
use Sd1\IamSso\Ias\TbmasterUserMirror;
use Sd1\IamSso\SsoUser;
use Sd1\IamSso\Tests\TestCase;

class IasAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.cabang' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        Schema::connection('cabang')->create('tbmaster_user', function ($t) {
            foreach (['kodeigr' => 2, 'recordid' => 1, 'userid' => 3, 'userpassword' => 8, 'station' => 3, 'username' => 15, 'email' => 50, 'create_by' => 3, 'modify_by' => 3, 'encryptpwd' => 100, 'jabatan' => 50, 'nik' => 16] as $c => $len) {
                $t->string($c, $len)->nullable();
            }
            $t->integer('userlevel')->nullable();
            $t->timestamp('create_dt')->nullable();
            $t->timestamp('modify_dt')->nullable();
        });
        Schema::connection('cabang')->create('tbmaster_access_migrasi', function ($t) {
            foreach (['acc_group', 'acc_subgroup1', 'acc_subgroup2', 'acc_subgroup3', 'acc_name', 'acc_url', 'acc_id', 'acc_create_by', 'acc_modify_by', 'acc_status'] as $c) {
                $t->string($c)->nullable();
            }
            $t->integer('acc_level')->nullable();
            $t->integer('acc_order')->nullable();
            $t->timestamp('acc_create_dt')->nullable();
            $t->timestamp('acc_modify_dt')->nullable();
        });
    }

    public function test_session_writer_fills_legacy_keys(): void
    {
        $user = new SsoUser($this->claims(['email' => 'SJM.budi@indogrosir.co.id']));
        $perms = new PermissionSet($this->accessPayload(['FO005', 'BO027', 'BO190.EXPORT'])['permissions'], 'IGR');
        $session = $this->app['session.store'];

        app(IasSessionWriter::class)->writeUser($session, $user, $perms);

        $this->assertSame('MYS', $session->get('usid'));
        $this->assertSame(3, $session->get('userlevel'));
        $this->assertSame('SJM', $session->get('usertype'));
        $this->assertSame([], $session->get('specialUser'));
        $menu = $session->get('menu');
        $this->assertSame(['FO005', 'BO027'], array_map(function ($m) {
            return $m->acc_id;
        }, $menu), 'ACTION tidak masuk menu');
        $this->assertSame('Laporan Kasir', $menu[0]->acc_subgroup1);
        $this->assertNull($menu[0]->acc_subgroup2);
        $this->assertSame('XXX', IasSessionWriter::userType('maya@x.id'));
        $this->assertSame('SM', IasSessionWriter::userType('sm.jkt@x.id'));
    }

    public function test_mirror_inserts_updates_and_never_touches_password(): void
    {
        $mirror = new TbmasterUserMirror(app('db'));
        DB::connection('cabang')->table('tbmaster_user')->insert([
            'userid' => 'OLD', 'username' => 'lama', 'userlevel' => 2, 'encryptpwd' => 'HASHLAMA', 'userpassword' => 'pw', 'jabatan' => 'KASIR',
        ]);

        $this->assertSame('updated', $mirror->upsert('cabang', '01', ['ias_user_code' => 'OLD', 'name' => 'Nama Yang Sangat Panjang Sekali', 'email' => 'a@b.c', 'nik' => '2020', 'ias_userlevel' => null, 'active' => true]));
        $row = DB::connection('cabang')->table('tbmaster_user')->where('userid', 'OLD')->first();
        $this->assertSame('HASHLAMA', $row->encryptpwd);
        $this->assertSame('pw', $row->userpassword);
        $this->assertSame('KASIR', $row->jabatan);
        $this->assertSame(2, (int) $row->userlevel, 'userlevel lama tidak ditimpa null');
        $this->assertSame(15, strlen($row->username));
        $this->assertNull($row->recordid);
        $this->assertSame('SSO', $row->modify_by);

        $this->assertSame('inserted', $mirror->upsert('cabang', '01', ['ias_user_code' => 'new', 'name' => 'Baru', 'active' => false]));
        $new = DB::connection('cabang')->table('tbmaster_user')->where('userid', 'NEW')->first();
        $this->assertSame('1', $new->recordid, 'nonaktif -> recordid 1');
        $this->assertNull($new->encryptpwd);
        $this->assertSame('skipped', $mirror->upsert('cabang', '01', ['ias_user_code' => null]));
    }

    public function test_access_migrasi_source_dedups_by_latest_and_skips_replaced_menus(): void
    {
        DB::connection('cabang')->table('tbmaster_access_migrasi')->insert([
            ['acc_id' => 'BO111', 'acc_name' => 'Lama', 'acc_group' => 'Back Office', 'acc_status' => '0', 'acc_modify_dt' => '2021-05-11 12:29:43', 'acc_url' => '/bo/a', 'acc_level' => 3],
            ['acc_id' => 'BO111', 'acc_name' => 'Baru', 'acc_group' => 'Back Office', 'acc_status' => '0', 'acc_modify_dt' => '2024-03-04 11:33:43', 'acc_url' => '/bo/b', 'acc_level' => 3],
            ['acc_id' => 'BO112', 'acc_name' => 'Mati', 'acc_group' => 'Back Office', 'acc_status' => '1', 'acc_modify_dt' => null, 'acc_url' => '/bo/c', 'acc_level' => 3],
            ['acc_id' => 'A001', 'acc_name' => 'User', 'acc_group' => 'Administration', 'acc_status' => '0', 'acc_modify_dt' => null, 'acc_url' => '/administration/user', 'acc_level' => 2],
            ['acc_id' => 'A002', 'acc_name' => 'User Access', 'acc_group' => 'Administration', 'acc_status' => '0', 'acc_modify_dt' => null, 'acc_url' => '/administration/access', 'acc_level' => 2],
            ['acc_id' => 'A010', 'acc_name' => 'Unlock IP', 'acc_group' => 'Administration', 'acc_status' => '0', 'acc_modify_dt' => null, 'acc_url' => '/administration/unlock-ip', 'acc_level' => 2],
            ['acc_id' => 'A011', 'acc_name' => 'Access IAS', 'acc_group' => 'Administration', 'acc_status' => '0', 'acc_modify_dt' => null, 'acc_url' => '/administration/access-ias', 'acc_level' => 2],
        ]);

        $items = collect(app(AccessMigrasiCatalogSource::class)->items(['connection' => 'cabang']))->keyBy('code');

        $this->assertSame(['A010', 'A011', 'BO111', 'BO112'], $items->keys()->sort()->values()->all(), 'Master User & akses per user dibuang; menu Administration lain tetap');
        $this->assertSame('Baru', $items['BO111']['name']);
        $this->assertSame('/bo/b', $items['BO111']['url']);
        $this->assertTrue($items['BO111']['is_active']);
        $this->assertFalse($items['BO112']['is_active']);
    }

    public function test_permission_push_command_sends_basic_auth_payload(): void
    {
        config(['sso.permission_push.source' => AccessMigrasiCatalogSource::class]);
        DB::connection('cabang')->table('tbmaster_access_migrasi')->insert([
            'acc_id' => 'FO005', 'acc_name' => 'Penjualan', 'acc_group' => 'Front Office', 'acc_status' => '0', 'acc_url' => '/fo/laporan-kasir/penjualan', 'acc_level' => 1,
        ]);

        $this->artisan('sso:permission-push', ['--connection' => 'cabang', '--dry-run' => true])->assertExitCode(0);
        $this->assertCount(0, $this->history);

        $this->mockIam([$this->ok(['created' => 1, 'updated' => 0, 'deactivated' => 0, 'skipped' => 0, 'perm_version' => 5])]);
        $this->artisan('sso:permission-push', ['--connection' => 'cabang'])->assertExitCode(0);
        $req = $this->history[0]['request'];
        $this->assertSame('Basic ' . base64_encode('7:rahasia'), $req->getHeaderLine('Authorization'));
        $body = json_decode((string) $req->getBody(), true);
        $this->assertFalse($body['full']);
        $this->assertSame('FO005', $body['items'][0]['code']);
    }

    public function test_mirror_users_command(): void
    {
        $this->mockIam([$this->ok(['server_time' => '2026-09-29T10:00:00+07:00', 'users' => [
            ['ias_user_code' => 'MYS', 'name' => 'Maya', 'email' => 'm@x.id', 'nik' => '2020000006', 'ias_userlevel' => 3, 'active' => true],
            ['ias_user_code' => 'AGP', 'name' => 'Agus', 'email' => 'a@x.id', 'nik' => '2021000007', 'ias_userlevel' => null, 'active' => false],
        ]])]);

        $this->artisan('sso:mirror-users', ['--branch' => '01', '--connection' => ['cabang']])->assertExitCode(0);

        $this->assertSame('branch=01', parse_url((string) $this->history[0]['request']->getUri(), PHP_URL_QUERY));
        $this->assertSame(2, DB::connection('cabang')->table('tbmaster_user')->count());
        $this->assertSame('1', DB::connection('cabang')->table('tbmaster_user')->where('userid', 'AGP')->value('recordid'));
    }
}
