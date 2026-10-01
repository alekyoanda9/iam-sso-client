<?php

namespace App\Sso;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sd1\IamSso\Access\PermissionSet;
use Sd1\IamSso\Branch\BranchContext;
use Sd1\IamSso\Contracts\PermissionCatalogSource;
use Sd1\IamSso\Contracts\UserMirrorHook;
use Sd1\IamSso\SsoManager;
use Sd1\IamSso\SsoUser;
use Sd1\IamSso\Support\BaseHook;
use stdClass;
use Throwable;

/**
 * Sambungan SSO IAM -> sesi lama IAS. Semua perilaku khusus IAS ada di file ini (config/sso.php
 * hanya berisi koneksi ke IAM + nama kelas ini).
 *
 *  - Login  : isi Session usid/un/menu/connection/kodeigr/... seperti loginController lama,
 *             baca tbmaster_perusahaan, update prs_periodeterakhir, mirror user ke tbmaster_user.
 *             TIDAK ada cek/isi tbmaster_computer: login SSO tidak dicegat walau user sedang login di IP lain.
 *  - Logout : bersihkan kunci sesi lama.
 *  - sso:permission-push : katalog menu dari tbmaster_access_migrasi.
 *  - sso:mirror-users    : salin user IAM ke tbmaster_user (baca-saja bagi IAS).
 */
class IasSsoHook extends BaseHook implements PermissionCatalogSource, UserMirrorHook
{
    /** Kunci sesi lama IAS yang diisi/dibersihkan hook ini. */
    const SESSION_KEYS = [
        'usid', 'un', 'eml', 'userlevel', 'usertype', 'specialUser', 'sso_nik', 'sso_role',
        'connection', 'phpIP', 'namacabang', 'kode', 'kodeigr', 'dbHostProd', 'dbHostSim', 'dbPort', 'dbPass',
        'ip', 'id', 'baseUrlIasApi', 'auth_via', 'menu', 'kdigr', 'rptname', 'ppn', 'sessionID',
        // sisa login lama / token IAS API :3050
        'stat', 'token', 'apilogin_token_expiry', 'apilogin_retry_after',
    ];

    /** Nilai usertype yang dikenal IAS; selain itu 'XXX'. Sumbernya klaim IAM ias_usertype (diisi atasan), BUKAN email. */
    const USERTYPES = ['SM', 'SJM'];

    /** Menu yang digantikan IAM (tidak dikirim ke katalog). */
    const EXCLUDED_MENU_URLS = ['/administration/user', '/administration/access'];

    /** @var SsoManager */
    private $sso;

    public function __construct(SsoManager $sso)
    {
        $this->sso = $sso;
    }

    // ------------------------------------------------------------------ cabang

    public function requiresBranch(): bool
    {
        return true;
    }

    /** Nama koneksi gaya IAS: igrjkt / simjkt; SPI & ICM di PRODUCTION = kode saja (spibks), SIMULASI = simspibks. */
    public function connectionName(BranchContext $branch): string
    {
        $kode = (string) $branch->kode();
        if ($branch->isProduction()) {
            return in_array($branch->type(), ['SPI', 'ICM'], true) ? $kode : 'igr' . $kode;
        }

        return 'sim' . $kode;
    }

    // ------------------------------------------------------------------ login / logout

    public function onLogin(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        if (trim((string) $user->get('ias_user_code')) === '') {
            return $this->cancelLogin($request, 'Akun Anda belum memiliki kode user IAS. Hubungi admin SSO.');
        }

        $session = $request->session();
        $this->forgetSession($request);
        $connection = $this->sso->connectionName();

        try {
            $this->writeSession($user, $permissions, $request);

            $prs = DB::connection($connection)->table('tbmaster_perusahaan')
                ->selectRaw('prs_kodeigr, prs_rptname, prs_nilaippn')
                ->first();
            if (! $prs) {
                $this->forgetSession($request);

                return $this->cancelLogin($request, 'Data TBMASTER_PERUSAHAAN tidak ditemukan di koneksi ' . $connection . '.');
            }
            $session->put('kdigr', $prs->prs_kodeigr);
            $session->put('rptname', $prs->prs_rptname);
            $session->put('ppn', $prs->prs_nilaippn);
            $session->put('sessionID', $this->backendPid($connection));

            DB::connection($connection)->table('tbmaster_perusahaan')->update([
                'prs_periodeterakhir' => Carbon::now(),
                'prs_modify_by' => $session->get('usid'),
                'prs_modify_dt' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::error('[sso-ias] login gagal: ' . $e->getMessage(), ['exception' => $e]);
            $this->forgetSession($request);
            $branch = $this->sso->branch();

            return $this->cancelLogin($request, 'Gagal menyiapkan sesi IAS'
                . ($branch ? ' untuk cabang ' . $branch->code() . ' (' . $branch->env() . ')' : '') . ': ' . $e->getMessage());
        }

        // Mirror gagal tidak membatalkan login (tbmaster_user hanya cermin untuk laporan/JOIN lama).
        try {
            $this->mirrorUser($connection, (string) $session->get('kdigr'), $user->toArray() + ['active' => true]);
        } catch (Throwable $e) {
            Log::warning('[sso-ias] mirror tbmaster_user saat login gagal: ' . $e->getMessage());
        }

        return null;
    }

    /** Hanya jalan bila SSO_ACCESS_REFRESH=true (default IAS: false, perubahan berlaku setelah login ulang). */
    public function onAccessRefreshed(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        $branch = $this->sso->branch();
        // User cabang yang dimutasi ke cabang lain saat sedang login -> keluar.
        if ($branch && ! $user->isHeadOffice() && $user->branchCode() && $user->branchCode() != $branch->code()) {
            return redirect()->route('sso.logout');
        }
        $this->writeSession($user, $permissions, $request);

        return null;
    }

    public function onLogout(Request $request)
    {
        $this->forgetSession($request);
    }

    private function writeSession(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        $session = $request->session();
        $branch = $this->sso->branch();
        $conn = $branch ? $branch->connection() : [];
        $usertype = strtoupper(trim((string) $user->get('ias_usertype')));

        $session->put([
            'usid' => $user->get('ias_user_code'),
            'un' => $user->name(),
            'eml' => (string) $user->email(),
            'userlevel' => $user->get('ias_userlevel'),
            'usertype' => in_array($usertype, self::USERTYPES, true) ? $usertype : 'XXX',
            'specialUser' => [],
            'sso_nik' => $user->nik(),
            'sso_role' => $user->roleCode(),
            'connection' => $this->sso->connectionName(),
            'phpIP' => $branch ? (string) $branch->phpHost() : '',
            'namacabang' => $branch ? ucfirst(strtolower(trim(str_replace('INDOGROSIR', '', (string) $branch->name())))) : '',
            'kode' => $branch ? $branch->kode() : null,
            'kodeigr' => $branch ? $branch->code() : null,
            'dbHostProd' => $branch ? (string) $branch->host('PRODUCTION') : '',
            'dbHostSim' => $branch ? (string) $branch->host('SIMULASI') : '',
            'dbPort' => '5432',
            'dbPass' => isset($conn['password']) ? (string) $conn['password'] : '',
            'ip' => $request->getClientIp(),
            'id' => str_replace('.', '', (string) $request->getClientIp()),
            'baseUrlIasApi' => 'http://' . $request->getHost() . ':3050',
            'auth_via' => 'sso',
            'menu' => $this->legacyMenu($permissions),
        ]);
    }

    /** Permission MENU dari IAM -> bentuk AccessController::getListMenu() (stdClass acc_*). */
    private function legacyMenu(PermissionSet $permissions): Collection
    {
        $rows = [];
        foreach ($permissions->menus() as $p) {
            if (empty($p['url'])) {
                continue;
            }
            $row = new stdClass();
            $row->acc_id = $p['code'];
            $row->acc_group = $this->nullable(isset($p['group']) ? $p['group'] : null);
            $row->acc_subgroup1 = $this->nullable(isset($p['subgroup1']) ? $p['subgroup1'] : null);
            $row->acc_subgroup2 = $this->nullable(isset($p['subgroup2']) ? $p['subgroup2'] : null);
            $row->acc_subgroup3 = $this->nullable(isset($p['subgroup3']) ? $p['subgroup3'] : null);
            $row->acc_name = $p['name'];
            $row->acc_url = $p['url'];
            $rows[] = $row;
        }

        return new Collection($rows);
    }

    private function forgetSession(Request $request)
    {
        $request->session()->forget(self::SESSION_KEYS);
    }

    private function backendPid(string $connection): string
    {
        try {
            $db = DB::connection($connection);
            if ($db->getDriverName() !== 'pgsql') {
                return (string) getmypid();
            }

            return (string) $db->selectOne('select pg_backend_pid() as userenv')->userenv;
        } catch (Throwable $e) {
            Log::warning('[sso-ias] pg_backend_pid dilewati: ' . $e->getMessage());

            return '';
        }
    }

    // ------------------------------------------------------------------ sso:mirror-users

    /**
     * Mirror satu arah IAM -> tbmaster_user. Kolom password (encryptpwd/userpassword), jabatan, dsb.
     * tidak pernah disentuh. Email hanya diisi saat baris baru dibuat: email di IAM diubah user sendiri,
     * jadi tidak boleh menimpa email baris lama (login lama menurunkan usertype dari email itu).
     */
    public function mirrorUser(string $connection, string $branchCode, array $user): string
    {
        $userid = strtoupper(trim((string) (isset($user['ias_user_code']) ? $user['ias_user_code'] : '')));
        if ($userid === '' || strlen($userid) > 3) {
            return 'skipped';
        }
        $nik = $this->limit(isset($user['nik']) ? $user['nik'] : null, 16);
        $active = ! array_key_exists('active', $user) || (bool) $user['active'];
        $now = Carbon::now();

        $values = [
            'kodeigr' => substr($branchCode, 0, 2),
            'username' => $this->limit(isset($user['name']) ? $user['name'] : null, 15),
            'nik' => $nik,
            'recordid' => $active ? null : '1',
        ];
        if (isset($user['ias_userlevel']) && $user['ias_userlevel'] !== '') {
            $values['userlevel'] = (int) $user['ias_userlevel'];
        }

        $table = DB::connection($connection)->table('tbmaster_user');
        if ((clone $table)->where('userid', $userid)->exists()) {
            (clone $table)->where('userid', $userid)->update($values + ['modify_by' => 'SSO', 'modify_dt' => $now]);
            $result = 'updated';
        } else {
            (clone $table)->insert($values + [
                'userid' => $userid,
                'email' => $this->limit(isset($user['email']) ? $user['email'] : null, 50),
                'create_by' => 'SSO',
                'create_dt' => $now,
            ]);
            $result = 'inserted';
        }

        // Kode lama ditautkan di IAM setelah user sempat mendapat kode baru: baris kode baru buatan
        // mirror (create_by SSO) untuk NIK yang sama dinonaktifkan supaya tidak ada user ganda.
        if ($nik !== null) {
            (clone $table)->where('nik', $nik)->where('userid', '<>', $userid)->where('create_by', 'SSO')
                ->whereRaw("coalesce(recordid, '0') <> '1'")
                ->update(['recordid' => '1', 'modify_by' => 'SSO', 'modify_dt' => $now]);
        }

        return $result;
    }

    // ------------------------------------------------------------------ sso:permission-push

    /** Katalog menu dari tbmaster_access_migrasi; acc_id ganda -> baris paling baru (acc_modify_dt, lalu acc_create_dt). */
    public function items(array $options): array
    {
        if (empty($options['connection'])) {
            throw new \InvalidArgumentException('Wajib --connection=<koneksi cabang sumber katalog>, mis. igrjkt.');
        }
        $excluded = array_map([$this, 'normUrl'], self::EXCLUDED_MENU_URLS);

        $latest = [];
        foreach (DB::connection($options['connection'])->table('tbmaster_access_migrasi')->get() as $r) {
            $code = strtoupper(trim((string) $r->acc_id));
            if ($code === '' || in_array($this->normUrl($r->acc_url), $excluded, true)) {
                continue;
            }
            $ts = $r->acc_modify_dt ?: $r->acc_create_dt;
            if (! isset($latest[$code]) || strcmp((string) $ts, (string) $latest[$code]['ts']) > 0) {
                $latest[$code] = ['ts' => $ts, 'row' => $r];
            }
        }

        $items = [];
        foreach ($latest as $code => $entry) {
            $r = $entry['row'];
            $items[] = [
                'code' => $code,
                'type' => 'MENU',
                'name' => trim((string) $r->acc_name),
                'url' => $this->nullable($r->acc_url),
                'group' => $this->nullable($r->acc_group),
                'subgroup1' => $this->nullable($r->acc_subgroup1),
                'subgroup2' => $this->nullable($r->acc_subgroup2),
                'subgroup3' => $this->nullable($r->acc_subgroup3),
                'order' => $r->acc_order !== null && $r->acc_order !== '' ? (int) $r->acc_order : null,
                'level' => $r->acc_level !== null && $r->acc_level !== '' ? (int) $r->acc_level : null,
                'is_active' => trim((string) $r->acc_status) === '0',
                'source_modified_at' => $entry['ts'] ? (string) $entry['ts'] : null,
            ];
        }

        return $items;
    }

    // ------------------------------------------------------------------ util

    private function nullable($value)
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function limit($value, int $max)
    {
        $value = $this->nullable($value);

        return $value === null ? null : mb_substr($value, 0, $max);
    }

    private function normUrl($url): string
    {
        return rtrim(strtolower(trim((string) $url)), '/');
    }
}
