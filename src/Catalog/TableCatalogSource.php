<?php

namespace Sd1\IamSso\Catalog;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Sd1\IamSso\Contracts\PermissionCatalogSource;

/**
 * Katalog menu dari satu tabel DB aplikasi (config sso.permission_push), untuk
 *   php artisan sso:permission-push --connection=<koneksi sumber>
 *
 *   table            : nama tabel menu
 *   columns          : [field IAM => kolom tabel] untuk code, name, url, group, subgroup1..3, order, level
 *   type             : MENU (default) / ACTION
 *   active_column    : kolom status (null = semua aktif);  active_value: nilai yang berarti aktif
 *   modified_columns : kolom waktu, urut prioritas; kode duplikat -> baris paling baru dipakai
 *   excluded_urls    : url yang tidak dikirim (dicocokkan persis, tanpa beda huruf, tanpa '/' akhir)
 *   excluded_groups  : grup yang tidak dikirim
 */
class TableCatalogSource implements PermissionCatalogSource
{
    /** @var DatabaseManager */
    private $db;

    /** @var array */
    private $config;

    public function __construct(DatabaseManager $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function items(array $options): array
    {
        if (empty($this->config['table'])) {
            throw new InvalidArgumentException('Isi config sso.permission_push.table (tabel menu aplikasi).');
        }
        $connection = ! empty($options['connection']) ? $options['connection'] : (isset($this->config['connection']) ? $this->config['connection'] : null);
        $cols = (array) (isset($this->config['columns']) ? $this->config['columns'] : []);
        if (empty($cols['code'])) {
            throw new InvalidArgumentException('config sso.permission_push.columns.code wajib diisi.');
        }
        $excludedGroups = array_map(function ($g) {
            return strtolower(trim($g));
        }, (array) (isset($this->config['excluded_groups']) ? $this->config['excluded_groups'] : []));
        $excludedUrls = array_map([$this, 'normUrl'], (array) (isset($this->config['excluded_urls']) ? $this->config['excluded_urls'] : []));
        $modifiedCols = (array) (isset($this->config['modified_columns']) ? $this->config['modified_columns'] : []);

        $latest = [];
        foreach ($this->db->connection($connection)->table($this->config['table'])->get() as $r) {
            $r = (array) $r;
            $code = strtoupper(trim((string) $this->col($r, $cols, 'code')));
            if ($code === '') {
                continue;
            }
            if (in_array(strtolower(trim((string) $this->col($r, $cols, 'group'))), $excludedGroups, true)) {
                continue;
            }
            if (in_array($this->normUrl((string) $this->col($r, $cols, 'url')), $excludedUrls, true)) {
                continue;
            }
            $ts = null;
            foreach ($modifiedCols as $c) {
                if (! empty($r[$c])) {
                    $ts = $r[$c];
                    break;
                }
            }
            if (! isset($latest[$code]) || strcmp((string) $ts, (string) $latest[$code]['_ts']) > 0) {
                $latest[$code] = ['_ts' => $ts, 'row' => $r];
            }
        }

        $items = [];
        foreach ($latest as $code => $entry) {
            $r = $entry['row'];
            $order = $this->col($r, $cols, 'order');
            $level = $this->col($r, $cols, 'level');
            $items[] = [
                'code' => $code,
                'type' => isset($this->config['type']) ? $this->config['type'] : 'MENU',
                'name' => trim((string) $this->col($r, $cols, 'name')),
                'url' => $this->nullable($this->col($r, $cols, 'url')),
                'group' => $this->nullable($this->col($r, $cols, 'group')),
                'subgroup1' => $this->nullable($this->col($r, $cols, 'subgroup1')),
                'subgroup2' => $this->nullable($this->col($r, $cols, 'subgroup2')),
                'subgroup3' => $this->nullable($this->col($r, $cols, 'subgroup3')),
                'order' => $order !== null && $order !== '' ? (int) $order : null,
                'level' => $level !== null && $level !== '' ? (int) $level : null,
                'is_active' => $this->isActive($r),
                'source_modified_at' => $entry['_ts'] ? (string) $entry['_ts'] : null,
            ];
        }

        return $items;
    }

    private function isActive(array $r): bool
    {
        if (empty($this->config['active_column'])) {
            return true;
        }
        $value = isset($r[$this->config['active_column']]) ? trim((string) $r[$this->config['active_column']]) : '';

        return $value === (string) (isset($this->config['active_value']) ? $this->config['active_value'] : '1');
    }

    private function col(array $row, array $cols, string $field)
    {
        return ! empty($cols[$field]) && array_key_exists($cols[$field], $row) ? $row[$cols[$field]] : null;
    }

    private function nullable($value)
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function normUrl($url): string
    {
        return rtrim(strtolower(trim((string) $url)), '/');
    }
}
