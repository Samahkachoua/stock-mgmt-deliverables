<?php

namespace App\Livewire;

use App\Support\Search\GlobalSearch as Search;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The search box in the top bar (App\Support\Search\GlobalSearch): results
 * grouped by kind, each one a link. The text is never put in the URL.
 */
class GlobalSearch extends Component
{
    public string $search = '';

    /**
     * @return list<array{key: string, label: string, rows: list<array{title: string, code: string|null, meta: string, url: string, icon: string, tone: string}>}>
     */
    #[Computed]
    public function groups(): array
    {
        return Search::run($this->search);
    }

    public function render(): View
    {
        return view('livewire.global-search', [
            'ready' => mb_strlen(trim($this->search)) >= Search::MIN_LENGTH,
        ]);
    }
}
