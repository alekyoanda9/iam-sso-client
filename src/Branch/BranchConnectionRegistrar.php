<?php

namespace Sd1\IamSso\Branch;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Sd1\IamSso\Contracts\BranchHook;
use Sd1\IamSso\Contracts\LoginHook;

/**
 * Mendaftarkan koneksi DB cabang terpilih ke config database.connections.{nama} setiap request
 * (dipanggil SsoController::callback, middleware sso.auth, dan middleware sso.branch).
 * Nama & config koneksi ditentukan hook aplikasi (Contracts\BranchHook); default 'sso_branch'.
 * Aplikasi cukup memakai DB::connection(Sso::connectionName()) — tidak perlu tahu webservice,
 * kunci AES, atau daftar koneksi semua cabang.
 */
class BranchConnectionRegistrar
{
    /** @var Container */
    private $app;

    /** @var Repository */
    private $config;

    /** @var DatabaseManager */
    private $db;

    public function __construct(Container $app, Repository $config, DatabaseManager $db)
    {
        $this->app = $app;
        $this->config = $config;
        $this->db = $db;
    }

    /** @return string|null nama koneksi yang didaftarkan */
    public function register(?BranchContext $branch = null)
    {
        if (! $branch) {
            return null;
        }
        $name = $this->name($branch);
        $key = 'database.connections.' . $name;
        $config = $this->connectionConfig($branch);

        if (! $this->sameTarget((array) $this->config->get($key, []), $config)) {
            $this->config->set($key, $config);
            // Koneksi bernama sama mungkin sudah dibuat sebelumnya di request ini dengan config lain.
            $this->db->purge($name);
        }

        return $name;
    }

    /**
     * Config yang sudah ada menunjuk ke DB yang sama? Aplikasi lama (mis. provider koneksi IAS)
     * boleh sudah mendaftarkan nama yang sama dengan kunci tambahan; tidak perlu ditimpa.
     */
    private function sameTarget(array $existing, array $config): bool
    {
        foreach (['driver', 'host', 'port', 'database', 'username', 'password'] as $k) {
            if (! isset($existing[$k]) || (string) $existing[$k] !== (string) $config[$k]) {
                return false;
            }
        }

        return true;
    }

    public function name(BranchContext $branch): string
    {
        $hook = $this->hook();

        return $hook ? $hook->connectionName($branch) : 'sso_branch';
    }

    public function connectionConfig(BranchContext $branch): array
    {
        $c = $branch->connection();
        $schema = isset($c['schema']) ? $c['schema'] : strtolower((string) $c['database']);

        $default = [
            'driver' => isset($c['driver']) ? $c['driver'] : 'pgsql',
            'host' => $c['host'],
            'port' => isset($c['port']) && $c['port'] !== '' ? $c['port'] : '5432',
            'database' => $c['database'],
            'username' => isset($c['username']) ? $c['username'] : '',
            'password' => isset($c['password']) ? $c['password'] : '',
            'charset' => 'utf8',
            'prefix' => '',
            // Laravel <= 8 memakai 'schema', Laravel 9+ memakai 'search_path'.
            'schema' => $schema,
            'search_path' => $schema,
        ];
        $hook = $this->hook();

        return $hook ? $hook->connectionConfig($branch, $default) : $default;
    }

    /** @return BranchHook|null */
    private function hook()
    {
        $hook = $this->app->make(LoginHook::class);

        return $hook instanceof BranchHook ? $hook : null;
    }
}
