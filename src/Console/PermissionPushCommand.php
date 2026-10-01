<?php

namespace Sd1\IamSso\Console;

use Illuminate\Console\Command;
use Sd1\IamSso\Contracts\LoginHook;
use Sd1\IamSso\Contracts\PermissionCatalogSource;
use Sd1\IamSso\Http\IamClient;

/**
 * Kirim katalog menu/aksi aplikasi ke IAM (client harus authz_mode MANAGED).
 * Katalog diambil dari hook aplikasi (config sso.hook) yang mengimplementasikan PermissionCatalogSource.
 * IAM tidak pernah menghapus permission; --full hanya MENONAKTIFKAN kode yang tidak dikirim.
 */
class PermissionPushCommand extends Command
{
    protected $signature = 'sso:permission-push
        {--connection= : koneksi DB sumber katalog (diteruskan ke hook)}
        {--full : nonaktifkan permission di IAM yang tidak ada di kiriman ini}
        {--dry-run : tampilkan saja, jangan kirim}';

    protected $description = 'Kirim katalog permission (menu/aksi) aplikasi ke IAM.';

    public function handle(IamClient $client, LoginHook $hook)
    {
        if (! $hook instanceof PermissionCatalogSource) {
            $this->error(get_class($hook) . ' (config sso.hook) belum mengimplementasikan ' . PermissionCatalogSource::class . '::items().');

            return 1;
        }

        $items = $hook->items(['connection' => $this->option('connection')]);
        $active = count(array_filter($items, function ($i) {
            return ! array_key_exists('is_active', $i) || $i['is_active'];
        }));

        $groups = [];
        foreach ($items as $i) {
            $g = isset($i['group']) && $i['group'] !== null ? $i['group'] : '(tanpa grup)';
            $groups[$g] = isset($groups[$g]) ? $groups[$g] + 1 : 1;
        }
        ksort($groups);
        $this->table(['Grup', 'Jumlah'], array_map(function ($g, $n) {
            return [$g, $n];
        }, array_keys($groups), array_values($groups)));
        $this->line(sprintf('Total %d permission (%d aktif).', count($items), $active));

        if ($this->option('dry-run')) {
            $this->warn('Dry run: tidak ada yang dikirim.');

            return 0;
        }
        if ($this->option('full') && ! $this->confirm('Mode --full akan menonaktifkan permission di IAM yang tidak ada di daftar ini. Lanjut?', true)) {
            return 1;
        }

        $result = $client->pushPermissions($items, (bool) $this->option('full'));
        $this->info(sprintf(
            'Terkirim. Baru: %d, diperbarui: %d, dinonaktifkan: %d, duplikat dilewati: %d. perm_version client: %s',
            isset($result['created']) ? $result['created'] : 0,
            isset($result['updated']) ? $result['updated'] : 0,
            isset($result['deactivated']) ? $result['deactivated'] : 0,
            isset($result['skipped']) ? $result['skipped'] : 0,
            isset($result['perm_version']) ? $result['perm_version'] : '-'
        ));

        return 0;
    }
}
