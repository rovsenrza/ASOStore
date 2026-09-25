<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for responses produced by Laravel (IMPLEMENTATION_PLAN
 * P8-SEC-01). Static files served directly by the web server get the same
 * set from public/.htaccess. The CSP is enforced; STOREFRONT_CSP_REPORT_ONLY
 * switches it back to report-only while diagnosing a breakage.
 */
class SecurityHeaders
{
    /** Pages load their own scripts and styles, plus Google Fonts. No inline code. */
    public const CSP = "default-src 'self'; img-src 'self' data: blob:; style-src 'self' https://fonts.googleapis.com; "
        ."font-src 'self' https://fonts.gstatic.com; script-src 'self'; connect-src 'self'; object-src 'none'; "
        ."frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set(config('storefront.security.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', self::CSP);

        // HSTS only over HTTPS, so a local http:// setup never pins itself.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.config('storefront.security.hsts_max_age').'; includeSubDomains');
        }

        return $response;
    }
}
