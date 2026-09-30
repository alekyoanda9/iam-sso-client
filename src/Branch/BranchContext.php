<?php

namespace Sd1\IamSso\Branch;

use JsonSerializable;

/**
 * Cabang + koneksi yang dipilih user di halaman login IAM (client ber-flag "Login multi-cabang").
 * Berasal dari GET /api/me/branch-context; berisi juga detail koneksi DB cabang.
 *
 *   $b = Sso::branch();
 *   $b->code();          // '01'  (kode IGR)
 *   $b->kode();          // 'jkt' (service name tanpa 'IGR', huruf kecil)
 *   $b->type();          // IGR | SPI | ICM
 *   $b->env();           // PRODUCTION | SIMULASI
 *   $b->isProduction();
 *   $b->connection();    // ['driver','host','port','database','username','password','schema']
 */
class BranchContext implements JsonSerializable
{
    /** @var array */
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** Payload IAM valid minimal? */
    public static function isValidPayload(array $data): bool
    {
        return isset($data['branch']['code'], $data['env'], $data['connection']['host'], $data['connection']['database'])
            && is_array($data['connection']);
    }

    public function code()
    {
        return $this->branch('code');
    }

    public function name()
    {
        return $this->branch('name');
    }

    public function type()
    {
        return $this->branch('type');
    }

    public function kode()
    {
        return $this->branch('kode');
    }

    public function serviceName()
    {
        return $this->branch('service_name');
    }

    public function phpHost()
    {
        return $this->branch('php_host');
    }

    public function env()
    {
        return isset($this->data['env']) ? $this->data['env'] : null;
    }

    public function isProduction(): bool
    {
        return $this->env() === 'PRODUCTION';
    }

    /** true bila cabang dikunci oleh server aplikasi cabang (login di server IAS cabang). */
    public function isLocked(): bool
    {
        return ! empty($this->data['locked']);
    }

    /** Detail koneksi DB cabang pada koneksi terpilih. */
    public function connection(): array
    {
        return isset($this->data['connection']) ? (array) $this->data['connection'] : [];
    }

    /** Host DB cabang ini pada koneksi lain (mis. host('SIMULASI')), null bila tidak ada. */
    public function host(string $env)
    {
        return isset($this->data['hosts'][$env]) ? $this->data['hosts'][$env] : null;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    /** Tanpa password (aman untuk log/JSON). */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $data = $this->data;
        if (isset($data['connection']['password'])) {
            $data['connection']['password'] = '***';
        }

        return $data;
    }

    private function branch(string $key)
    {
        return isset($this->data['branch'][$key]) ? $this->data['branch'][$key] : null;
    }
}
