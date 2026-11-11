<?php

namespace App\Support\Auth;

use GeoIp2\Database\Reader;
use Locale;
use Throwable;

/**
 * The country of an IP address (ISO code, "LB"), from MaxMind's GeoLite2
 * Country file on this server (config services.maxmind.database; downloaded by
 * `php artisan geoip:update`). Nothing is sent to an outside service.
 *
 * Null when the file is missing, the address is local or private (office
 * network, 127.0.0.1), or it is not in the file.
 */
class IpCountry
{
    private ?Reader $reader = null;

    public function lookup(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        try {
            $code = $this->reader()?->country($ip)->country->isoCode;
        } catch (Throwable) {
            // Not in the file (AddressNotFoundException) or an unreadable file.
            return null;
        }

        return is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * The country's name in the current language ("Lebanon", "لبنان", "Liban").
     */
    public static function name(string $code): string
    {
        $name = Locale::getDisplayRegion('-'.$code, app()->getLocale());

        return $name === '' || $name === $code ? $code : $name;
    }

    private function reader(): ?Reader
    {
        $path = (string) config('services.maxmind.database');

        if ($this->reader === null && is_file($path)) {
            $this->reader = new Reader($path);
        }

        return $this->reader;
    }
}
