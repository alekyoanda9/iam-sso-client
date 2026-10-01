<?php

namespace Sd1\IamSso;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Sd1\IamSso\Console\PermissionPushCommand;
use Sd1\IamSso\Contracts\LoginHook;
use Sd1\IamSso\Http\IamClient;
use Sd1\IamSso\Http\Middleware\Authenticate;
use Sd1\IamSso\Http\Middleware\Authorize;
use Sd1\IamSso\Http\Middleware\RegisterBranchConnection;
use Sd1\IamSso\Console\MirrorUsersCommand;
use Sd1\IamSso\Jwt\JwtVerifier;
use Sd1\IamSso\Jwt\PublicKeyProvider;

class SsoServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sso.php', 'sso');

        // Guzzle dipisah supaya bisa diganti (mis. MockHandler di test).
        $this->app->bind('sso.http', function () {
            return new GuzzleClient();
        });

        $this->app->singleton(IamClient::class, function ($app) {
            return new IamClient($app->make('sso.http'), $app['config']->get('sso'));
        });

        $this->app->singleton(PublicKeyProvider::class, function ($app) {
            return new PublicKeyProvider($app->make(IamClient::class), $app['cache.store'], $app['config']->get('sso.jwt'));
        });

        $this->app->singleton(JwtVerifier::class, function ($app) {
            return new JwtVerifier(
                $app->make(PublicKeyProvider::class),
                $app['config']->get('sso.jwt'),
                (string) $app['config']->get('sso.client_id')
            );
        });

        $this->app->singleton(SsoManager::class, function ($app) {
            return new SsoManager(
                $app->make(IamClient::class),
                $app->make(JwtVerifier::class),
                $app['session.store'],
                $app['config']->get('sso'),
                $app['encrypter']
            );
        });
        $this->app->alias(SsoManager::class, 'sso');

        $this->app->bind(LoginHook::class, function ($app) {
            return $app->make($app['config']->get('sso.hook') ?: \Sd1\IamSso\Support\NullLoginHook::class);
        });
    }

    public function boot()
    {
        $this->publishes([__DIR__ . '/../config/sso.php' => config_path('sso.php')], 'sso-config');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'sso');

        if ($this->app['config']->get('sso.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/sso.php');
        }

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('sso.auth', Authenticate::class);
        $router->aliasMiddleware('sso.can', Authorize::class);
        $router->aliasMiddleware('sso.branch', RegisterBranchConnection::class);

        // @ssocan('BO190') ... @endssocan
        Blade::if('ssocan', function ($code) {
            return app('sso')->can($code);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([PermissionPushCommand::class, MirrorUsersCommand::class]);
        }
    }
}
