<?php
/** Transport policy shared by public endpoint bootstrap and regression checks. */
declare(strict_types=1);

function taskflow_loopback_host(string $hostname): bool {
    return in_array(strtolower(trim($hostname, '[]')), ['localhost','127.0.0.1','::1'], true);
}

/**
 * The public site must use HTTPS even if a reverse proxy forwards requests
 * from 127.0.0.1 and a client spoofs Host: localhost. HTTP is allowed only
 * while an uninstalled app is being set up locally, or for a deliberately
 * configured local DEVELOPMENT environment.
 */
function taskflow_allow_local_http(string $environment, string $appUrl, bool $installed,
                                   bool $loopbackPeer, bool $loopbackRequestHost): bool {
    if (!$loopbackPeer || !$loopbackRequestHost) return false;
    if (!$installed) {
        // Local first-time setup is allowed with an unset or explicitly local
        // APP_URL. Never let a public canonical hostname bypass HTTPS merely
        // because a reverse proxy forwards from 127.0.0.1 during installation.
        if ($appUrl === '') return true;
        $parts = parse_url($appUrl);
        return is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'http'
            && taskflow_loopback_host((string)($parts['host'] ?? ''));
    }
    if ($environment !== 'development') return false;
    $parts = parse_url($appUrl);
    return is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'http'
        && taskflow_loopback_host((string)($parts['host'] ?? ''));
}
