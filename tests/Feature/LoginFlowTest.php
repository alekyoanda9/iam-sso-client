<?php

namespace Sd1\IamSso\Tests\Feature;

use Sd1\IamSso\Facades\Sso;
use Sd1\IamSso\Tests\RecordingHook;
use Sd1\IamSso\Tests\TestCase;

class LoginFlowTest extends TestCase
{
    public function test_login_redirects_to_iam_with_state(): void
    {
        $res = $this->get('/sso/login');
        $location = $res->headers->get('Location');
        $this->assertStringStartsWith('http://iam.test/oauth/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame('7', $q['client_id']);
        $this->assertSame('http://app.test/sso/callback', $q['redirect_uri']);
        $this->assertSame(40, strlen($q['state']));
    }

    public function test_callback_success_stores_verified_claims_and_calls_hook(): void
    {
        $this->loginAs([], ['FO005', 'BO190.EXPORT'])->assertRedirect('/');

        $this->assertTrue(Sso::check());
        $this->assertSame('MYS', Sso::user()->ias_user_code);
        $this->assertTrue(Sso::can('FO005'));
        $this->assertTrue(Sso::can('BO190.EXPORT'));
        $this->assertFalse(Sso::canUrl('/bo/proses/monthend'));
        $this->assertSame([['login', '2020000006', ['FO005', 'BO190.EXPORT']]], RecordingHook::$calls);

        $tokenRequest = $this->history[0]['request'];
        parse_str((string) $tokenRequest->getBody(), $form);
        $this->assertSame(['authorization_code', '7', 'rahasia', 'XYZ'], [$form['grant_type'], $form['client_id'], $form['client_secret'], $form['code']]);
        $this->assertSame('Bearer AT1', $this->history[1]['request']->getHeaderLine('Authorization'));
    }

    public function test_callback_with_wrong_state_is_rejected(): void
    {
        $this->get('/sso/login');
        $this->get('/sso/callback?code=XYZ&state=' . str_repeat('a', 40))->assertRedirect(route('sso.error'));
        $this->assertFalse(Sso::check());
        $this->assertCount(0, $this->history, 'code tidak pernah ditukar');
    }

    public function test_state_cannot_be_replayed(): void
    {
        $this->mockIam([
            $this->jsonResponse(['access_token' => 'AT1', 'expires_in' => 3600]),
            $this->ok(['token' => $this->jwt($this->claims())]),
            $this->ok($this->accessPayload()),
        ]);
        parse_str(parse_url($this->get('/sso/login')->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->get('/sso/callback?code=XYZ&state=' . $q['state'])->assertRedirect('/');
        $this->get('/sso/callback?code=XYZ&state=' . $q['state'])->assertRedirect(route('sso.error'));
    }

    public function test_forged_jwt_from_iam_response_is_rejected(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($other, $otherPem);
        $this->mockIam([
            $this->jsonResponse(['access_token' => 'AT1', 'expires_in' => 3600]),
            $this->ok(['token' => $this->jwt($this->claims(), $otherPem)]),
        ]);
        parse_str(parse_url($this->get('/sso/login')->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->get('/sso/callback?code=XYZ&state=' . $q['state'])->assertRedirect(route('sso.error'));
        $this->assertFalse(Sso::check());
        $this->followingRedirects()->get('/sso/error')->assertStatus(403);
    }

    public function test_iam_denies_access_shows_friendly_error(): void
    {
        $this->mockIam([
            $this->jsonResponse(['access_token' => 'AT1', 'expires_in' => 3600]),
            $this->jsonResponse(['status' => 'error', 'message' => 'Failed to get user data. User tidak memiliki akses ke aplikasi ini.'], 403),
        ]);
        parse_str(parse_url($this->get('/sso/login')->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->get('/sso/callback?code=XYZ&state=' . $q['state'])
            ->assertRedirect(route('sso.error'))
            ->assertSessionHas('sso_error', 'Akun Anda belum memiliki akses ke aplikasi ini.');
    }

    public function test_hook_can_redirect_after_login(): void
    {
        RecordingHook::$loginResponse = redirect('/pilih-cabang');
        $this->loginAs()->assertRedirect('/pilih-cabang');
    }

    public function test_logout_calls_hook_clears_session_and_goes_to_iam_logout(): void
    {
        config(['sso.post_logout_redirect' => 'http://app.test/login']);
        $this->loginAs();
        $res = $this->get('/sso/logout');
        $this->assertSame(
            'http://iam.test/logout?client_id=7&redirect_back=' . urlencode('http://app.test/login'),
            $res->headers->get('Location')
        );
        $this->assertFalse(Sso::check());
        $this->assertSame(['logout'], end(RecordingHook::$calls));
    }
}
