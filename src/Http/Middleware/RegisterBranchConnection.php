<?php

namespace Sd1\IamSso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sd1\IamSso\Branch\BranchConnectionRegistrar;
use Sd1\IamSso\SsoManager;

/**
 * sso.branch — daftarkan koneksi DB cabang terpilih tanpa cek sesi SSO.
 * sso.auth sudah melakukan ini; pakai sso.branch hanya untuk route yang tidak lewat sso.auth
 * tetapi memakai koneksi cabang.
 */
class RegisterBranchConnection
{
    /** @var SsoManager */
    private $sso;

    /** @var BranchConnectionRegistrar */
    private $registrar;

    public function __construct(SsoManager $sso, BranchConnectionRegistrar $registrar)
    {
        $this->sso = $sso;
        $this->registrar = $registrar;
    }

    public function handle(Request $request, Closure $next)
    {
        if ($this->sso->check()) {
            $this->registrar->register($this->sso->branch());
        }

        return $next($request);
    }
}
