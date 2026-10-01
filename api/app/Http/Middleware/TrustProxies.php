<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Read TRUSTED_PROXIES from the process environment ("*" or a comma-separated list).
     * getenv() is used instead of env() so it still works after `php artisan config:cache`.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $trusted = getenv('TRUSTED_PROXIES');

        if ($trusted === false || $trusted === '') {
            return $this->proxies;
        }

        return $trusted === '*' ? '*' : array_map('trim', explode(',', $trusted));
    }
}
