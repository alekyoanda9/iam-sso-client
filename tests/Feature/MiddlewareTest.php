<?php

namespace Sd1\IamSso\Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Support\Facades\Route;
use Sd1\IamSso\Facades\Sso;
use Sd1\IamSso\Tests\RecordingHook;
use Sd1\IamSso\Tests\TestCase;

class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'sso.auth'])->group(function () {
            Route::get('/t/home', function () {
                return 'home';
            });
            Route::get('/fo/laporan-kasir/penjualan/detail', function () {
                return 'detail';
            })->middleware('sso.can');
            Route::get('/bo/proses/monthend', function () {
                return 'monthend';
            })->middleware('sso.can');
            Route::get('/t/export', function () {
                return 'export';
            })->middleware('sso.can:BO190.EXPORT,BO999');
        });
        app('router')->getRoutes()->refreshNameLookups();
    }

    private function expire(): void
    {
        Sso::session()->put(['checked_at' => time() - 3600]);
    }

    public function test_guest_is_sent_to_sso_login_and_intended_is_kept(): void
    {
        $this->get('/t/home')->assertRedirect(route('sso.login'));
        $this->assertStringEndsWith('/t/home', session('url.intended'));
        $this->getJson('/t/home')->assertStatus(401);
    }

    public function test_sso_can_url_and_code_modes(): void
    {
        $this->loginAs([], ['FO005', 'BO190.EXPORT']);
        $this->get('/t/home')->assertOk();
        $this->get('/fo/laporan-kasir/penjualan/detail')->assertOk();
        $this->get('/bo/proses/monthend')->assertForbidden();
        $this->get('/t/export')->assertOk();
    }

    public function test_no_iam_call_within_check_interval(): void
    {
        $this->loginAs();
        $calls = count($this->history);
        $this->get('/t/home')->assertOk();
        $this->assertCount($calls, $this->history);
    }

    public function test_changed_perm_version_refreshes_access_without_logout(): void
    {
        $this->loginAs([], ['FO005']);
        $this->get('/bo/proses/monthend')->assertForbidden();

        $this->expire();
        $this->mockIam([
            $this->ok(['perm_version' => '2.1', 'active' => true]),
            $this->ok(['token' => $this->jwt($this->claims())]),
            $this->ok($this->accessPayload(['FO005', 'BO027'], '2.1')),
        ]);
        $this->get('/bo/proses/monthend')->assertOk();
        $this->assertSame('2.1', Sso::permVersion());
        $this->assertSame('refreshed', end(RecordingHook::$calls)[0]);
    }

    public function test_same_version_only_one_cheap_call(): void
    {
        $this->loginAs();
        $this->expire();
        $this->mockIam([$this->ok(['perm_version' => '1.1', 'active' => true])]);
        $this->get('/t/home')->assertOk();
        $this->assertCount(1, $this->history);
        $this->assertStringEndsWith('/api/me/access/version', (string) $this->history[0]['request']->getUri());
    }

    public function test_access_refresh_disabled_never_calls_iam(): void
    {
        config(['sso.access_refresh' => false]);
        $this->mockIam([]);
        $this->loginAs([], ['FO005']);
        $calls = count($this->history);

        $this->expire(); // sudah lewat 1 jam sejak cek terakhir
        $this->get('/t/home')->assertOk();
        $this->get('/fo/laporan-kasir/penjualan/detail')->assertOk();

        $this->assertCount($calls, $this->history, 'tidak ada panggilan ke IAM selama sesi');
        $this->assertNotContains(['refreshed', '2020000006', ['FO005']], RecordingHook::$calls);
    }

    public function test_access_refresh_disabled_forces_relogin_when_jwt_expired(): void
    {
        config(['sso.access_refresh' => false]);
        $this->loginAs(['exp' => time() + 120]);
        $calls = count($this->history);
        $claims = Sso::session()->get('claims');
        Sso::session()->put(['claims' => array_merge($claims, ['exp' => time() - 1])]);

        $this->get('/t/home')->assertRedirect(route('sso.login'));
        $this->assertFalse(Sso::check());
        $this->assertSame(['logout'], end(RecordingHook::$calls));
        $this->assertCount($calls, $this->history, 'dicek lokal, tanpa IAM');
    }

    public function test_inactive_user_is_logged_out(): void
    {
        $this->loginAs();
        $this->expire();
        $this->mockIam([$this->ok(['perm_version' => '1.2', 'active' => false])]);
        $this->get('/t/home')->assertRedirect(route('sso.login'))->assertSessionHas('sso_message');
        $this->assertFalse(Sso::check());
        $this->assertSame(['logout'], end(RecordingHook::$calls));
    }

    public function test_iam_down_fail_open_keeps_session(): void
    {
        $this->loginAs();
        $this->expire();
        $this->mockIam([new ConnectException('down', new PsrRequest('GET', 'http://iam.test'))]);
        $this->get('/t/home')->assertOk();

        config(['sso.fail_open' => false]);
        $this->forgetManager();
        $this->expire();
        $this->mockIam([new ConnectException('down', new PsrRequest('GET', 'http://iam.test'))]);
        $this->get('/t/home')->assertRedirect(route('sso.login'));
    }

    public function test_expired_access_token_is_refreshed(): void
    {
        $this->loginAs();
        $this->expire();
        $tokens = Sso::session()->get('tokens');
        Sso::session()->put(['tokens' => array_merge($tokens, ['expires_at' => time() - 1])]);
        $this->mockIam([
            $this->jsonResponse(['access_token' => 'AT2', 'refresh_token' => 'RT2', 'expires_in' => 3600]),
            $this->ok(['perm_version' => '1.1', 'active' => true]),
        ]);
        $this->get('/t/home')->assertOk();
        parse_str((string) $this->history[0]['request']->getBody(), $form);
        $this->assertSame(['refresh_token', 'RT1'], [$form['grant_type'], $form['refresh_token']]);
        $this->assertSame('Bearer AT2', $this->history[1]['request']->getHeaderLine('Authorization'));
    }

    public function test_refresh_token_rejected_forces_relogin(): void
    {
        $this->loginAs();
        $this->expire();
        $tokens = Sso::session()->get('tokens');
        Sso::session()->put(['tokens' => array_merge($tokens, ['expires_at' => time() - 1])]);
        $this->mockIam([$this->jsonResponse(['error' => 'invalid_grant'], 400)]);
        $this->get('/t/home')->assertRedirect(route('sso.login'));
    }

    public function test_branch_type_switch_for_head_office(): void
    {
        $this->loginAs(['role_scope' => 'HO', 'branch_code' => '00', 'branch_type' => null], ['FO005', 'BO068']);
        $this->assertTrue(Sso::user()->isHeadOffice());
        $this->assertFalse(Sso::can('BO068'));
        Sso::setBranchType('IGR');
        $this->assertTrue(Sso::can('BO068'));
        Sso::setBranchType('SPI');
        $this->assertFalse(Sso::can('BO068'));
    }

    private function forgetManager(): void
    {
        foreach ([\Sd1\IamSso\SsoManager::class, 'sso'] as $a) {
            $this->app->forgetInstance($a);
        }
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('sso');
    }
}
