<?php

namespace Sd1\IamSso\Mirror;

use Illuminate\Database\DatabaseManager;
use Sd1\IamSso\Bridge\ValueResolver;

/**
 * Mirror SATU ARAH user IAM -> tabel user lokal aplikasi (config `sso.mirror`).
 * Untuk aplikasi lama yang laporan/prosesnya masih JOIN ke tabel user sendiri.
 *
 * Hanya kolom yang disebut di config yang pernah ditulis (kolom password dsb. tidak disentuh).
 *
 *   key             : ['column' => 'userid', 'value' => 'user.ias_user_code|trim|upper', 'max' => 3]
 *                     nilai kosong / lebih panjang dari max -> dilewati
 *   columns         : [kolom => spec]   selalu ditulis
 *   columns_if_set  : [kolom => spec]   hanya ditulis bila nilainya tidak kosong
 *   when_active     : [kolom => spec]   user aktif
 *   when_inactive   : [kolom => spec]   user nonaktif / tanpa akses
 *   on_insert       : [kolom => spec]   hanya saat baris baru
 *   on_update       : [kolom => spec]   hanya saat baris sudah ada
 *
 * Spec memakai ValueResolver dengan sumber user.* (data user dari IAM) dan context.branch_code.
 */
class UserMirror
{
    /** @var DatabaseManager */
    private $db;

    /** @var ValueResolver */
    private $values;

    /** @var array */
    private $config;

    public function __construct(DatabaseManager $db, ValueResolver $values, array $config)
    {
        $this->db = $db;
        $this->values = $values;
        $this->config = $config;
    }

    public function enabled(): bool
    {
        return ! empty($this->config['enabled']) && ! empty($this->config['table']);
    }

    /**
     * @param array $user data user dari IAM: ias_user_code, name, email, nik, ias_userlevel, active, ...
     *
     * @return string 'inserted' | 'updated' | 'skipped'
     */
    public function upsert(string $connection, string $branchCode, array $user): string
    {
        $context = ['user' => $user, 'context' => ['branch_code' => $branchCode]];
        $keyCfg = (array) $this->cfg('key', []);
        $keyColumn = isset($keyCfg['column']) ? $keyCfg['column'] : 'id';
        $keyValue = $this->values->resolve(isset($keyCfg['value']) ? $keyCfg['value'] : null, $context);
        if ($this->values->isEmpty($keyValue) || (! empty($keyCfg['max']) && strlen((string) $keyValue) > (int) $keyCfg['max'])) {
            return 'skipped';
        }

        $values = $this->resolveAll((array) $this->cfg('columns', []), $context);
        foreach ((array) $this->cfg('columns_if_set', []) as $column => $spec) {
            $value = $this->values->resolve($spec, $context);
            if (! $this->values->isEmpty($value)) {
                $values[$column] = $value;
            }
        }
        $active = ! array_key_exists('active', $user) || (bool) $user['active'];
        $values = array_merge($values, $this->resolveAll((array) $this->cfg($active ? 'when_active' : 'when_inactive', []), $context));

        $table = (string) $this->cfg('table');
        if ($this->db->connection($connection)->table($table)->where($keyColumn, $keyValue)->exists()) {
            $this->db->connection($connection)->table($table)->where($keyColumn, $keyValue)
                ->update(array_merge($values, $this->resolveAll((array) $this->cfg('on_update', []), $context)));

            return 'updated';
        }

        $this->db->connection($connection)->table($table)->insert(array_merge(
            $values,
            [$keyColumn => $keyValue],
            $this->resolveAll((array) $this->cfg('on_insert', []), $context)
        ));

        return 'inserted';
    }

    private function resolveAll(array $specs, array $context): array
    {
        $out = [];
        foreach ($specs as $column => $spec) {
            $out[$column] = $this->values->resolve($spec, $context);
        }

        return $out;
    }

    private function cfg(string $key, $default = null)
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }
}
