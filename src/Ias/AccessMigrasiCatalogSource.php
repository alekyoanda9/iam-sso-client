<?php

namespace Sd1\IamSso\Ias;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Sd1\IamSso\Contracts\PermissionCatalogSource;

/**
 * Katalog menu IAS dari tbmaster_access_migrasi (untuk sso:permission-push --connection=...).
 * - Duplikat acc_id: diambil baris dengan acc_modify_dt (fallback acc_create_dt) terbaru.
 * - acc_status '0' = aktif (sama dengan filter IAS).
 * - acc_url di config sso.ias.excluded_urls (default Master User & akses menu per user)
 *   dan grup di sso.ias.excluded_groups (default kosong) tidak dikirim.
 */
class AccessMigrasiCatalogSource implements PermissionCatalogSource
{
    /** @var DatabaseManager */
    private $db;

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
    }

    public function items(array $options): array
    {
        if (empty($options['connection'])) {
            throw new InvalidArgumentException('Sebutkan koneksi DB sumber: --connection=<nama koneksi cabang>');
        }
        $excluded = array_map('strtolower', (array) config('sso.ias.excluded_groups', []));
        $excludedUrls = array_map(function ($u) {
            return rtrim(strtolower(trim($u)), '/');
        }, (array) config('sso.ias.excluded_urls', []));

        $rows = $this->db->connection($options['connection'])->table('tbmaster_access_migrasi')->get();

        $latest = [];
        foreach ($rows as $r) {
            $code = strtoupper(trim((string) $r->acc_id));
            if ($code === '' || in_array(strtolower(trim((string) $r->acc_group)), $excluded, true)) {
                continue;
            }
            if (in_array(rtrim(strtolower(trim((string) $r->acc_url)), '/'), $excludedUrls, true)) {
                continue;
            }
            $ts = $r->acc_modify_dt ?: $r->acc_create_dt;
            if (! isset($latest[$code]) || strcmp((string) $ts, (string) $latest[$code]['_ts']) > 0) {
                $latest[$code] = ['_ts' => $ts, 'row' => $r];
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
                'order' => $r->acc_order !== null ? (int) $r->acc_order : null,
                'level' => $r->acc_level !== null ? (int) $r->acc_level : null,
                'is_active' => trim((string) $r->acc_status) === '0',
                'source_modified_at' => $entry['_ts'] ? (string) $entry['_ts'] : null,
            ];
        }

        return $items;
    }

    private function nullable($value)
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
