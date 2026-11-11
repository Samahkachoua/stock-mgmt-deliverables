<?php

namespace App\Console\Commands;

use GeoIp2\Database\Reader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PharData;
use Throwable;

/**
 * Downloads MaxMind's free GeoLite2 Country file (the country of a sign-in,
 * App\Support\Auth\IpCountry). Weekly (routes/console.php); MaxMind updates
 * it twice a week and requires an up-to-date copy. Needs MAXMIND_ACCOUNT_ID
 * and MAXMIND_LICENSE_KEY in .env (free account at maxmind.com). The new file
 * replaces the old one only once it opens correctly.
 */
class UpdateGeoIp extends Command
{
    protected $signature = 'geoip:update';

    protected $description = 'Download the MaxMind GeoLite2 Country file used to find the country of each sign-in.';

    private const URL = 'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download?suffix=tar.gz';

    public function handle(): int
    {
        $account = (string) config('services.maxmind.account_id');
        $key = (string) config('services.maxmind.license_key');
        $target = (string) config('services.maxmind.database');

        if ($account === '' || $key === '') {
            $this->warn('MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY are not set; the sign-in country stays empty.');

            return self::SUCCESS;
        }

        $work = storage_path('app/private/geoip/download-'.uniqid());
        File::ensureDirectoryExists($work);

        try {
            $archive = $work.'/GeoLite2-Country.tar.gz';
            Http::withBasicAuth($account, $key)->timeout(120)->sink($archive)->get(self::URL)->throw();

            (new PharData($archive))->extractTo($work);
            $file = collect(File::allFiles($work))->first(fn ($f) => $f->getFilename() === 'GeoLite2-Country.mmdb');

            if ($file === null) {
                throw new \RuntimeException('GeoLite2-Country.mmdb not found in the download.');
            }

            // Refuse a broken file before it replaces the working one.
            (new Reader($file->getPathname()))->close();

            File::ensureDirectoryExists(dirname($target));
            File::move($file->getPathname(), $target);
        } catch (Throwable $e) {
            Log::error('geoip:update failed: '.$e->getMessage());
            $this->error('Download failed; the current file is kept. See the log.');

            return self::FAILURE;
        } finally {
            File::deleteDirectory($work);
        }

        $this->info('GeoLite2 Country file updated.');

        return self::SUCCESS;
    }
}
