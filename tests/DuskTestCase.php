<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use Symfony\Component\Process\Process;

/**
 * Browser tests (tests/Browser), run with `php artisan dusk` after `npm run build`.
 *
 * They never touch the development database or .env: the test run and the
 * app it drives both use the stock_test database of phpunit.dusk.xml. The app
 * is served by this class on its own port (DUSK_SERVER_PORT, default 8090)
 * with those settings in its environment, so no .env.dusk file is needed.
 *
 * Chrome: the one installed, or DUSK_CHROME_BINARY (e.g. a Chromium that
 * matches vendor/laravel/dusk/bin's driver: `php artisan dusk:chrome-driver <major>`).
 */
abstract class DuskTestCase extends BaseTestCase
{
    /** Phone, tablet and desktop of CLAUDE.md "Responsive layout" (width, height). */
    public const SIZES = [
        'phone' => [375, 667],
        'tablet' => [768, 1024],
        'desktop' => [1440, 900],
    ];

    private static ?Process $server = null;

    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }

        static::startServer();
    }

    /** The only database browser tests may use (phpunit.dusk.xml). */
    private const DATABASE = 'stock_test';

    protected function setUp(): void
    {
        parent::setUp();

        // These tests rebuild their database: never anywhere but the test database,
        // whatever .env or the environment says.
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if ($database !== self::DATABASE || ! str_ends_with(rtrim((string) config('app.url'), '/'), ':'.self::port())) {
            exit('Browser tests refused to run: database ['.$database.'] and app URL ['.config('app.url').'] are not the test settings of phpunit.dusk.xml ('.self::DATABASE.', port '.self::port().').'.PHP_EOL);
        }
    }

    private static function port(): string
    {
        return (string) (getenv('DUSK_SERVER_PORT') ?: '8090');
    }

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments([
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox',
            '--hide-scrollbars',
            '--window-size=1440,900',
            '--disable-search-engine-choice-screen',
        ]);

        if (filled(env('DUSK_CHROME_BINARY'))) {
            $options->setBinary((string) env('DUSK_CHROME_BINARY'));
        }

        return RemoteWebDriver::create(
            env('DUSK_DRIVER_URL', 'http://localhost:9515'),
            DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options),
        );
    }

    /**
     * Gives the page exactly this viewport (a window can't be made as narrow as a phone).
     */
    protected function viewport(Browser $browser, string $size): Browser
    {
        [$width, $height] = self::SIZES[$size];

        (new ChromeDevToolsDriver($browser->driver))->execute('Emulation.setDeviceMetricsOverride', [
            'width' => $width,
            'height' => $height,
            'deviceScaleFactor' => 1,
            'mobile' => $size === 'phone',
        ]);

        return $browser;
    }

    /**
     * The app under test, on its own port, with the test run's own settings
     * (database, no debug page) in its environment: they win over .env.
     */
    private static function startServer(): void
    {
        if (self::$server !== null) {
            return;
        }

        $root = dirname(__DIR__);
        $port = self::port();
        $keep = ['APP_KEY', 'DB_CONNECTION', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SSLMODE'];
        $environment = [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'http://127.0.0.1:'.$port,
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BCRYPT_ROUNDS' => '4',
        ];

        foreach ($keep as $name) {
            $value = $_ENV[$name] ?? getenv($name);

            if ($value !== false && $value !== null) {
                $environment[$name] = (string) $value;
            }
        }

        if (($environment['DB_DATABASE'] ?? '') !== self::DATABASE) {
            exit('Browser tests refused to start the app: its database would be ['.($environment['DB_DATABASE'] ?? '').'], not '.self::DATABASE.'.'.PHP_EOL);
        }

        self::$server = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
            $root.'/public',
            $environment,
        );
        self::$server->setTimeout(null);
        self::$server->start();

        register_shutdown_function(static function (): void {
            self::$server?->stop(0);
        });

        // Wait until it answers.
        for ($try = 0; $try < 50; $try++) {
            $socket = @fsockopen('127.0.0.1', (int) $port, $errno, $error, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(100_000);
        }

        throw new \RuntimeException('The test server did not start on port '.$port.': '.self::$server->getErrorOutput());
    }
}
