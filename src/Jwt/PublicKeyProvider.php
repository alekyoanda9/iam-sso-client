<?php

namespace Sd1\IamSso\Jwt;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as Cache;
use Sd1\IamSso\Exceptions\SsoException;
use Sd1\IamSso\Http\IamClient;

/**
 * Sumber public key: config (PEM / path) > cache > GET {IAM}/api/public-key.
 */
class PublicKeyProvider
{
    const CACHE_KEY = 'sso:iam-public-key';

    /** @var IamClient */
    private $client;

    /** @var Cache */
    private $cache;

    /** @var array */
    private $config;

    public function __construct(IamClient $client, Cache $cache, array $config)
    {
        $this->client = $client;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function isRemote(): bool
    {
        return empty($this->config['public_key']) && empty($this->config['public_key_path']);
    }

    public function get(bool $fresh = false): string
    {
        if (! empty($this->config['public_key'])) {
            return str_replace('\n', "\n", $this->config['public_key']);
        }
        if (! empty($this->config['public_key_path'])) {
            if (! is_readable($this->config['public_key_path'])) {
                throw new SsoException('File public key SSO tidak terbaca: ' . $this->config['public_key_path']);
            }

            return (string) file_get_contents($this->config['public_key_path']);
        }

        if ($fresh) {
            $this->cache->forget(self::CACHE_KEY);
        }
        $client = $this->client;

        return $this->cache->remember(
            self::CACHE_KEY,
            Carbon::now()->addMinutes((int) $this->config['public_key_cache_minutes']),
            function () use ($client) {
                return $client->publicKey();
            }
        );
    }
}
