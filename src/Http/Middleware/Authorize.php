<?php

namespace Sd1\IamSso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sd1\IamSso\SsoManager;

/**
 * sso.can               -> izinkan bila Sso::canUrl(path request) (semantik isAccessible IAS)
 * sso.can:BO190         -> izinkan bila punya permission BO190
 * sso.can:BO190,BO191   -> izinkan bila punya salah satunya
 * Pasang SETELAH sso.auth.
 */
class Authorize
{
    /** @var SsoManager */
    private $sso;

    public function __construct(SsoManager $sso)
    {
        $this->sso = $sso;
    }

    public function handle(Request $request, Closure $next, ...$codes)
    {
        $allowed = $codes
            ? $this->sso->canAny($codes)
            : $this->sso->canUrl('/' . ltrim($request->path(), '/'));

        if (! $allowed) {
            if ($request->expectsJson()) {
                return response()->json(['status' => 'error', 'message' => 'Anda tidak memiliki akses.'], 403);
            }
            abort(403);
        }

        return $next($request);
    }
}
