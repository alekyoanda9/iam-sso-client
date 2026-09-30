<?php

namespace Sd1\IamSso\Console;

use Illuminate\Console\Command;
use Sd1\IamSso\Http\IamClient;
use Sd1\IamSso\Mirror\UserMirror;

/**
 * Sinkron berkala IAM -> tabel user lokal (satu arah, config sso.mirror).
 *   php artisan sso:mirror-users --branch=01 --connection=igrjkt --connection=simjkt
 */
class MirrorUsersCommand extends Command
{
    protected $signature = 'sso:mirror-users
        {--branch= : kode cabang (default config sso.mirror.branch)}
        {--connection=* : koneksi DB tujuan (boleh berulang, mis. produksi & simulasi)}
        {--since= : hanya user yang berubah sejak waktu ini (ISO 8601)}';

    protected $description = 'Salin user cabang dari IAM ke tabel user lokal aplikasi (mirror baca-saja).';

    public function handle(IamClient $client, UserMirror $mirror)
    {
        if (! $mirror->enabled()) {
            $this->error('Mirror belum diaktifkan: isi config sso.mirror (enabled + table).');

            return 1;
        }
        $branch = $this->option('branch') ?: config('sso.mirror.branch');
        $connections = array_filter((array) $this->option('connection'));
        if (! $connections && config('sso.mirror.connection')) {
            $connections = [config('sso.mirror.connection')];
        }
        if (! $branch || ! $connections) {
            $this->error('Wajib: --branch=<kode> (atau config sso.mirror.branch) dan minimal satu --connection=<nama>.');

            return 1;
        }

        $data = $client->clientUsers($branch, $this->option('since'));
        $users = isset($data['users']) ? $data['users'] : [];

        foreach ($connections as $conn) {
            $count = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
            foreach ($users as $u) {
                $count[$mirror->upsert($conn, $branch, $u)]++;
            }
            $this->info(sprintf('[%s] %d user: %d baru, %d diperbarui, %d dilewati.', $conn, count($users), $count['inserted'], $count['updated'], $count['skipped']));
        }
        if (isset($data['server_time'])) {
            $this->line('server_time (pakai untuk --since berikutnya): ' . $data['server_time']);
        }

        return 0;
    }
}
