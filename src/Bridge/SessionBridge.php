<?php

namespace Sd1\IamSso\Bridge;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Sd1\IamSso\Access\PermissionSet;
use Sd1\IamSso\Exceptions\SsoException;
use Sd1\IamSso\SsoManager;
use Sd1\IamSso\SsoUser;
use stdClass;
use Throwable;

/**
 * Jembatan hasil SSO -> sesi LAMA aplikasi, sepenuhnya lewat config `sso.bridge`.
 * Aplikasi lama (mis. IAS) tetap membaca Session::get('usid'), Session::get('menu'),
 * DB::connection(Session::get('connection')) ... tanpa mengubah controller-nya.
 *
 *   session   : [kunci sesi => spec nilai]              (lihat ValueResolver)
 *   menu      : bentuk daftar menu lama dari permission MENU
 *   required  : [spec => pesan]  nilai yang wajib ada (mis. kode user aplikasi)
 *   queries   : SELECT satu baris dari DB cabang -> kunci sesi
 *   on_login  : statement (UPDATE/INSERT) setelah sesi terisi
 *   on_logout : statement sebelum sesi dihapus
 *   forget    : kunci sesi tambahan yang dibersihkan saat login/logout
 */
class SessionBridge
{
    /** @var SsoManager */
    private $sso;

    /** @var DatabaseManager */
    private $db;

    /** @var ValueResolver */
    private $values;

    /** @var array */
    private $config;

    public function __construct(SsoManager $sso, DatabaseManager $db, ValueResolver $values, array $config)
    {
        $this->sso = $sso;
        $this->db = $db;
        $this->values = $values;
        $this->config = $config;
    }

    public function enabled(): bool
    {
        return ! empty($this->config['enabled']);
    }

    /**
     * Isi sesi lama setelah login SSO.
     *
     * @throws SsoException pesan untuk user (login dibatalkan)
     */
    public function login(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        $session = $request->session();
        $this->clear($session);

        foreach ((array) $this->get('required', []) as $spec => $message) {
            if ($this->values->isEmpty($this->values->resolve($spec, $this->context($user, $request)))) {
                throw new SsoException((string) $message);
            }
        }

        $this->writeSession($user, $permissions, $request);

        foreach ((array) $this->get('queries', []) as $query) {
            $this->runQuery($query, $user, $request);
        }
        foreach ((array) $this->get('on_login', []) as $statement) {
            $this->runStatement($statement, $user, $request, isset($statement['required']) ? (bool) $statement['required'] : true);
        }
    }

    /** Hak akses berubah (perm_version): perbarui kunci sesi & menu tanpa menjalankan query/statement. */
    public function refresh(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        $this->writeSession($user, $permissions, $request);
    }

    /** Sebelum sesi dihapus: jalankan statement logout (gagal = hanya dicatat), lalu hapus kunci sesi lama. */
    public function logout(Request $request)
    {
        $user = $this->sso->user() ?: new SsoUser([]);
        foreach ((array) $this->get('on_logout', []) as $statement) {
            $this->runStatement($statement, $user, $request, false);
        }
        $this->clear($request->session());
    }

    /** Hapus semua kunci sesi yang dikelola bridge (url.intended dipertahankan). */
    public function clear(Session $session)
    {
        foreach ($this->managedKeys() as $key) {
            if ($key !== 'url.intended') {
                $session->forget($key);
            }
        }
    }

    public function managedKeys(): array
    {
        $keys = array_keys((array) $this->get('session', []));
        $menu = $this->get('menu');
        if (is_array($menu) && ! empty($menu['key'])) {
            $keys[] = $menu['key'];
        }
        foreach ((array) $this->get('queries', []) as $query) {
            $keys = array_merge($keys, array_keys(isset($query['into']) ? (array) $query['into'] : []));
        }

        return array_values(array_unique(array_merge($keys, (array) $this->get('forget', []))));
    }

    /** Konteks nilai untuk ValueResolver. */
    public function context(SsoUser $user, Request $request, array $extra = []): array
    {
        $branch = $this->sso->branch();
        $branchData = [];
        if ($branch) {
            $data = $branch->toArray();
            $branchData = array_merge(isset($data['branch']) ? (array) $data['branch'] : [], [
                'env' => $branch->env(),
                'is_production' => $branch->isProduction(),
                'locked' => $branch->isLocked(),
                'connection' => $branch->connection(),
                'hosts' => isset($data['hosts']) ? (array) $data['hosts'] : [],
                'connection_name' => $this->sso->connectionName(),
            ]);
        }

        return array_merge([
            'user' => $user->toArray(),
            'branch' => $branchData,
            'request' => [
                'ip' => $request->getClientIp(),
                'host' => $request->getHost(),
                'http_host' => $request->getHttpHost(),
            ],
            'session' => $request->session()->all(),
            'context' => [],
        ], $extra);
    }

    private function writeSession(SsoUser $user, PermissionSet $permissions, Request $request)
    {
        $session = $request->session();
        foreach ((array) $this->get('session', []) as $key => $spec) {
            // Konteks dibangun ulang tiap kunci: spec boleh merujuk kunci sesi yang baru ditulis.
            $session->put($key, $this->values->resolve($spec, $this->context($user, $request)));
        }

        $menu = $this->get('menu');
        if (is_array($menu) && ! empty($menu['key'])) {
            $session->put($menu['key'], $this->menu($permissions, $menu));
        }
    }

    /** Permission MENU -> bentuk menu lama (mis. stdClass acc_id, acc_url, ...). */
    public function menu(PermissionSet $permissions, array $config)
    {
        $fields = isset($config['fields']) ? (array) $config['fields'] : [];
        $requireUrl = ! isset($config['require_url']) || $config['require_url'];
        $format = isset($config['format']) ? $config['format'] : 'collection';

        $rows = [];
        foreach ($permissions->menus() as $p) {
            if ($requireUrl && empty($p['url'])) {
                continue;
            }
            $row = [];
            foreach ($fields as $target => $source) {
                $row[$target] = isset($p[$source]) && $p[$source] !== '' ? $p[$source] : null;
            }
            $rows[] = $format === 'arrays' ? $row : (object) $row;
        }

        return $format === 'collection' ? new Collection($rows) : $rows;
    }

    private function runQuery(array $query, SsoUser $user, Request $request)
    {
        $context = $this->context($user, $request);
        $required = ! empty($query['required']);
        try {
            $rows = $this->connection($query)->select((string) $query['sql'], $this->bindings($query, $context));
        } catch (Throwable $e) {
            if ($required) {
                throw $e;
            }
            Log::warning('[sso-bridge] query dilewati: ' . $e->getMessage());

            return;
        }
        $row = $rows ? (array) (is_object($rows[0]) ? get_object_vars($rows[0]) : $rows[0]) : null;
        if ($row === null) {
            if ($required) {
                throw new SsoException($this->values->template(
                    isset($query['error']) ? (string) $query['error'] : 'Data wajib tidak ditemukan di database aplikasi.',
                    $context
                ));
            }

            return;
        }

        $context['row'] = $row;
        foreach ((array) (isset($query['into']) ? $query['into'] : []) as $key => $spec) {
            $request->session()->put($key, $this->values->resolve($spec, $context));
        }
    }

    private function runStatement(array $statement, SsoUser $user, Request $request, bool $required)
    {
        $context = $this->context($user, $request);
        foreach ((array) (isset($statement['when']) ? $statement['when'] : []) as $spec) {
            if ($this->values->isEmpty($this->values->resolve($spec, $context))) {
                return;
            }
        }
        try {
            $this->connection($statement)->statement((string) $statement['sql'], $this->bindings($statement, $context));
        } catch (Throwable $e) {
            if ($required) {
                throw $e;
            }
            Log::warning('[sso-bridge] statement gagal: ' . $e->getMessage());
        }
    }

    private function bindings(array $item, array $context): array
    {
        $bindings = [];
        foreach ((array) (isset($item['bindings']) ? $item['bindings'] : []) as $name => $spec) {
            $bindings[ltrim((string) $name, ':')] = $this->values->resolve($spec, $context);
        }

        return $bindings;
    }

    /** 'branch' (default) = koneksi cabang terpilih; selain itu nama koneksi Laravel. */
    private function connection(array $item)
    {
        $name = isset($item['connection']) ? $item['connection'] : 'branch';
        if ($name === 'branch') {
            $name = $this->sso->connectionName();
            if (! $name) {
                throw new SsoException('Koneksi cabang belum tersedia (client belum ber-flag Login multi-cabang di IAM?).');
            }
        }

        return $this->db->connection($name);
    }

    private function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }
}
