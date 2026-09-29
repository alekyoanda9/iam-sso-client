<?php

namespace Sd1\IamSso\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Sd1\IamSso\Exceptions\IamRequestException;
use Sd1\IamSso\Exceptions\IamUnavailableException;

/**
 * Semua panggilan HTTP ke IAM. Guzzle 6/7 (IAS sudah memakai Guzzle).
 * Respons IAM berformat {status, message, data}; method di sini mengembalikan isi "data".
 */
class IamClient
{
    /** @var ClientInterface */
    private $http;

    /** @var array */
    private $config;

    public function __construct(ClientInterface $http, array $config)
    {
        $this->http = $http;
        $this->config = $config;
    }

    /** Tukar authorization code menjadi token. */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        return $this->send('POST', '/oauth/token', [
            'form_params' => [
                'grant_type' => 'authorization_code',
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ],
        ], false);
    }

    public function refreshToken(string $refreshToken): array
    {
        return $this->send('POST', '/oauth/token', [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'refresh_token' => $refreshToken,
                'scope' => '',
            ],
        ], false);
    }

    /** POST /api/user -> data user + token (JWT). */
    public function user(string $accessToken): array
    {
        return $this->send('POST', '/api/user', [
            'headers' => $this->bearer($accessToken),
            'form_params' => ['client_id' => $this->config['client_id']],
        ]);
    }

    /** GET /api/me/access -> authz_mode, perm_version, role, app_role, branch, permissions[]. */
    public function access(string $accessToken): array
    {
        return $this->send('GET', '/api/me/access', ['headers' => $this->bearer($accessToken)]);
    }

    /** GET /api/me/access/version -> {perm_version, active}. */
    public function accessVersion(string $accessToken): array
    {
        return $this->send('GET', '/api/me/access/version', ['headers' => $this->bearer($accessToken)]);
    }

    public function publicKey(): string
    {
        $response = $this->raw('GET', '/api/public-key', []);
        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== 200 || strpos($body, 'PUBLIC KEY') === false) {
            throw new IamRequestException('Gagal mengambil public key IAM.', $response->getStatusCode());
        }

        return $body;
    }

    /** POST /api/client/permissions/sync (server-to-server). */
    public function pushPermissions(array $items, bool $full): array
    {
        return $this->send('POST', '/api/client/permissions/sync', [
            'auth' => [(string) $this->config['client_id'], (string) $this->config['client_secret']],
            'json' => ['full' => $full, 'items' => array_values($items)],
        ]);
    }

    /** GET /api/client/users?branch= (server-to-server, untuk mirror tabel user lokal). */
    public function clientUsers(string $branch, $updatedSince = null): array
    {
        $query = ['branch' => $branch];
        if ($updatedSince) {
            $query['updated_since'] = $updatedSince;
        }

        return $this->send('GET', '/api/client/users', [
            'auth' => [(string) $this->config['client_id'], (string) $this->config['client_secret']],
            'query' => $query,
        ]);
    }

    /** URL IAM absolut untuk path tertentu. */
    public function url(string $path, array $query = []): string
    {
        $url = rtrim((string) $this->config['base_url'], '/') . '/' . ltrim($path, '/');

        return $query ? $url . '?' . http_build_query($query) : $url;
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * @param bool $unwrap true = kembalikan field "data" (format BaseController IAM)
     */
    private function send(string $method, string $path, array $options, bool $unwrap = true): array
    {
        $response = $this->raw($method, $path, $options);
        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getBody(), true);

        if ($status >= 500) {
            throw new IamUnavailableException('IAM error ' . $status . ' pada ' . $path, $status, $json);
        }
        if ($status >= 400) {
            $message = is_array($json) ? (isset($json['message']) ? $json['message'] : (isset($json['error_description']) ? $json['error_description'] : null)) : null;
            throw new IamRequestException($message ?: ('IAM menolak permintaan ' . $path . ' (' . $status . ')'), $status, $json);
        }
        if (! is_array($json)) {
            throw new IamRequestException('Respons IAM bukan JSON pada ' . $path, $status);
        }
        if (! $unwrap) {
            return $json;
        }

        return isset($json['data']) && is_array($json['data']) ? $json['data'] : [];
    }

    private function raw(string $method, string $path, array $options)
    {
        $options = array_merge([
            'http_errors' => false,
            'connect_timeout' => $this->config['http']['connect_timeout'],
            'timeout' => $this->config['http']['timeout'],
            'verify' => $this->config['http']['verify'],
        ], $options);
        $options['headers'] = array_merge(['Accept' => 'application/json'], isset($options['headers']) ? $options['headers'] : []);

        try {
            return $this->http->request($method, $this->url($path), $options);
        } catch (TransferException $e) {
            throw new IamUnavailableException('IAM tidak dapat dihubungi: ' . $e->getMessage(), 0);
        }
    }
}
