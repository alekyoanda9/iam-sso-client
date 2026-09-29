<?php

namespace Sd1\IamSso\Tests\Unit;

use Sd1\IamSso\Exceptions\InvalidTokenException;
use Sd1\IamSso\Jwt\JwtVerifier;
use Sd1\IamSso\Tests\TestCase;

class JwtVerifierTest extends TestCase
{
    private function verifier(): JwtVerifier
    {
        return $this->app->make(JwtVerifier::class);
    }

    public function test_valid_token(): void
    {
        $claims = $this->verifier()->verify($this->jwt($this->claims()));
        $this->assertSame('MYS', $claims['ias_user_code']);
    }

    public function test_rejects_bad_tokens(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($other, $otherPem);
        $good = $this->jwt($this->claims());
        [$h, , $s] = explode('.', $good);
        $tamperedBody = rtrim(strtr(base64_encode(json_encode($this->claims(['ias_user_code' => 'ADM']))), '+/', '-_'), '=');

        $cases = [
            'signature lain' => $this->jwt($this->claims(), $otherPem),
            'payload diubah' => $h . '.' . $tamperedBody . '.' . $s,
            'alg none' => rtrim(strtr(base64_encode('{"alg":"none"}'), '+/', '-_'), '=') . '.' . $tamperedBody . '.',
            'alg HS256' => $this->jwt($this->claims(), null, 'HS256'),
            'kedaluwarsa' => $this->jwt($this->claims(['exp' => time() - 120])),
            'aud lain' => $this->jwt($this->claims(['aud' => '99'])),
            'iss lain' => $this->jwt($this->claims(['iss' => 'http://evil.test'])),
            'format' => 'abc.def',
        ];
        foreach ($cases as $name => $token) {
            try {
                $this->verifier()->verify($token);
                $this->fail("Token '$name' seharusnya ditolak");
            } catch (InvalidTokenException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_remote_key_is_cached_and_refetched_once_on_rotation(): void
    {
        config(['sso.jwt.public_key' => null]);
        $old = openssl_pkey_new(['private_key_bits' => 2048]);
        $oldPub = openssl_pkey_get_details($old)['key'];

        $this->mockIam([
            new \GuzzleHttp\Psr7\Response(200, [], $oldPub),        // cache awal: kunci lama
            new \GuzzleHttp\Psr7\Response(200, [], $this->publicKey), // setelah rotasi
        ]);
        $claims = $this->verifier()->verify($this->jwt($this->claims()));
        $this->assertSame('MYS', $claims['ias_user_code']);
        $this->assertCount(2, $this->history);

        $this->verifier()->verify($this->jwt($this->claims()));
        $this->assertCount(2, $this->history, 'kunci baru sudah di-cache');
    }
}
