<?php

namespace App\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

/**
 * Unexpected errors (CLAUDE.md "Feedback messages" 3): the user sees
 * "Something went wrong and nothing was saved. Reference: ERR-7F3A2C", never
 * the error itself; the log has the full error under that reference, with the
 * user, the URL and what was sent.
 */
final class ErrorReference
{
    /** @var WeakMap<Throwable, string>|null */
    private static ?WeakMap $references = null;

    /**
     * The reference of an error: the same one for the log, the toast and the error page.
     */
    public static function for(Throwable $e): string
    {
        // The 500 page gets the error wrapped in an HTTP exception.
        if ($e instanceof HttpExceptionInterface && $e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        self::$references ??= new WeakMap;

        return self::$references[$e] ??= 'ERR-'.strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * Called from bootstrap/app.php.
     */
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->report(function (Throwable $e): void {
            Log::error(self::for($e).' '.$e::class.': '.$e->getMessage(), [
                'reference' => self::for($e),
                'user_id' => Auth::id(),
                'method' => request()->method(),
                'url' => request()->fullUrl(),
                'input' => self::input(request()),
                'exception' => $e,
            ]);
        })->stop();

        // Livewire shows whatever a failed request returns in a dialog. Outside
        // debug mode it gets a toast instead (resources/js/app.js).
        $exceptions->render(function (Throwable $e, Request $request): ?Response {
            if (config('app.debug') || ! $request->hasHeader('X-Livewire') || ! app(ExceptionHandler::class)->shouldReport($e)) {
                return null;
            }

            return response()->json(['toast' => [
                'type' => 'error',
                'message' => __('feedback.errors.unexpected', ['reference' => self::for($e)]),
            ]], 500);
        });
    }

    /**
     * What the request sent, without passwords or tokens. Of a Livewire request:
     * the changed properties and the actions called, not the whole page state.
     *
     * @return array<array-key, mixed>
     */
    private static function input(Request $request): array
    {
        $input = $request->hasHeader('X-Livewire')
            ? array_map(fn ($component) => [
                'updates' => $component['updates'] ?? [],
                'calls' => $component['calls'] ?? [],
            ], (array) $request->input('components', []))
            : $request->except(['_token']);

        array_walk_recursive($input, function (mixed &$value, string|int $key): void {
            if (is_string($key) && preg_match('/password|token|secret/i', $key)) {
                $value = '[hidden]';
            }
        });

        return $input;
    }
}
