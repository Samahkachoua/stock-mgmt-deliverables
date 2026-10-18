<?php

namespace App\Support;

/**
 * Filters, sorting and page of a list, kept in the user's session (CLAUDE.md
 * "List state"): never in the URL, and still there when the user comes back
 * from a details page. One entry per list, under list_state.<key>.
 *
 * The entry also names the user it belongs to, so it is per user even if
 * someone else signs in within the same browser session.
 *
 * @phpstan-type State array{properties: array<string, mixed>, page: int}
 */
final class ListState
{
    /**
     * @return State|null
     */
    public static function get(string $key): ?array
    {
        $state = session(self::sessionKey($key));

        if (! is_array($state) || ! is_array($state['properties'] ?? null)) {
            return null;
        }

        // Kept per user: another login in the same browser session starts clean.
        if (isset($state['user']) && (string) $state['user'] !== (string) auth()->id()) {
            return null;
        }

        return ['properties' => $state['properties'], 'page' => max(1, (int) ($state['page'] ?? 1))];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function put(string $key, array $properties, int $page = 1): void
    {
        session()->put(self::sessionKey($key), [
            'user' => auth()->id(),
            'properties' => $properties,
            'page' => max(1, $page),
        ]);
    }

    public static function forget(string $key): void
    {
        session()->forget(self::sessionKey($key));
    }

    private static function sessionKey(string $key): string
    {
        return 'list_state.'.$key;
    }
}
