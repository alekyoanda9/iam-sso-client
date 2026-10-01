<?php

namespace Sd1\IamSso\Console;

use Illuminate\Console\Command;
use Sd1\IamSso\Contracts\LoginHook;
use Sd1\IamSso\Contracts\UserMirrorHook;
use Sd1\IamSso\Http\IamClient;

/**
 * Sinkron berkala IAM -> tabel user lokal (satu arah). Penulisan ke tabel dilakukan hook aplikasi
 * (config sso.hook) yang mengimplementasikan UserMirrorHook.
 *   php artisan sso:mirror-users --branch=01 --connection=igrjkt --connection=simjkt
 */
class MirrorUsersCommand extends Command
{
    protected $signature = 'sso:mirror-users
        {--branch= : kode cabang}
        {--connection=* : koneksi DB tujuan (boleh berulang, mis. produksi & simulasi)}
        {--since= : hanya user yang berubah sejak waktu ini (ISO 8601)}';

    protected $description = 'Salin user cabang dari IAM ke tabel user lokal aplikasi (mirror baca-saja).';

    public function handle(IamClient $client, LoginHook $hook)
    {
        if (! $hook instanceof UserMirrorHook) {
            $this->error(get_class($hook) . ' (config sso.hook) belum mengimplementasikan ' . UserMirrorHook::class . '::mirrorUser().');

            return 1;
        }
        $branch = (string) $this->option('branch');
        $connections = array_filter((array) $this->option('connection'));
        if ($branch === '' || ! $connections) {
            $this->error('Wajib: --branch=<kode> dan minimal satu --connection=<nama>.');

            return 1;
        }

        $data = $client->clientUsers($branch, $this->option('since'));
        $users = isset($data['users']) ? $data['users'] : [];

        foreach ($connections as $conn) {
            $count = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
            foreach ($users as $u) {
                $result = $hook->mirrorUser($conn, $branch, $u);
                $count[isset($count[$result]) ? $result : 'skipped']++;
            }
            $this->info(sprintf('[%s] %d user: %d baru, %d diperbarui, %d dilewati.', $conn, count($users), $count['inserted'], $count['updated'], $count['skipped']));
        }
        if (isset($data['server_time'])) {
            $this->line('server_time (pakai untuk --since berikutnya): ' . $data['server_time']);
        }

        return 0;
    }
}
