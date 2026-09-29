<?php

namespace Sd1\IamSso\Ias\Console;

use Illuminate\Console\Command;
use Sd1\IamSso\Http\IamClient;
use Sd1\IamSso\Ias\TbmasterUserMirror;

/**
 * Sinkron berkala IAM -> tbmaster_user cabang (satu arah).
 *   php artisan sso:mirror-users --branch=01 --connection=igrjkt --connection=simjkt
 */
class MirrorUsersCommand extends Command
{
    protected $signature = 'sso:mirror-users
        {--branch= : kode cabang (default config sso.ias.branch / KODEIGR)}
        {--connection=* : koneksi DB cabang tujuan (boleh berulang: produksi & simulasi)}
        {--since= : hanya user yang berubah sejak waktu ini (ISO 8601)}';

    protected $description = 'Salin user cabang dari IAM ke tbmaster_user (mirror baca-saja).';

    public function handle(IamClient $client)
    {
        $branch = $this->option('branch') ?: config('sso.ias.branch');
        $connections = array_filter((array) $this->option('connection'));
        if (! $branch || ! $connections) {
            $this->error('Wajib: --branch=<kode> (atau KODEIGR) dan minimal satu --connection=<nama>.');

            return 1;
        }

        $data = $client->clientUsers($branch, $this->option('since'));
        $users = isset($data['users']) ? $data['users'] : [];
        $mirror = new TbmasterUserMirror(app('db'), (string) config('sso.ias.mirror_actor', 'SSO'));

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
