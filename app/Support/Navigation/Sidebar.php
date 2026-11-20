<?php

namespace App\Support\Navigation;

use App\Models\User;
use App\Support\Format;
use App\Support\Transactions\PendingApprovals;

/**
 * The sidebar for one user (config/ui.php `nav`): its sections, each with the
 * entries the user may open. Sections the user can see nothing of are left out.
 *
 * @phpstan-type Item array{key: string, label: string, url: string, icon: string, active: bool, pending: int, pendingText: string}
 * @phpstan-type Section array{label: ?string, items: list<Item>}
 */
final class Sidebar
{
    /**
     * @return list<Section>
     */
    public static function for(User $user): array
    {
        // Documents waiting for this user's approval, per sidebar route.
        $pendingApprovals = PendingApprovals::bySlug($user);
        $sections = [];

        foreach (config('ui.nav') as $section) {
            $items = [];

            foreach ($section['items'] as $item) {
                if (! $user->can($item['permission'])) {
                    continue;
                }

                $pending = (int) ($pendingApprovals[$item['route']] ?? 0);

                $items[] = [
                    'key' => $item['route'],
                    'label' => __($item['label']),
                    'url' => route($item['route']),
                    'icon' => $item['icon'],
                    // A page under the entry (a report, a settings tab, a document) keeps it marked.
                    'active' => request()->routeIs($item['route'], $item['route'].'.*'),
                    'pending' => $pending,
                    'pendingText' => $pending > 0 ? __('nav.pending_count', ['count' => Format::qty($pending)]) : '',
                ];
            }

            if ($items !== []) {
                $sections[] = ['label' => $section['label'] === null ? null : __($section['label']), 'items' => $items];
            }
        }

        return $sections;
    }

    /**
     * Every sidebar entry's config, in order, without sections (for the
     * placeholder routes and the Home tiles' order). Reads only config.
     *
     * @return list<array<string, mixed>>
     */
    public static function flatItems(): array
    {
        return array_merge(...array_map(fn (array $section) => $section['items'], config('ui.nav')));
    }
}
