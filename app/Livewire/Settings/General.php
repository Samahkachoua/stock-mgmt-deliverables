<?php

namespace App\Livewire\Settings;

use App\Support\CompanyTime;
use App\Support\Notify;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings › General: the company's time zone (app_setting 'timezone',
 * App\Support\CompanyTime). Every warehouse works in it: it decides what
 * "today" is on every form and report, and how saved times are shown. Times
 * already saved stay as they are (stored in UTC); only how they read changes.
 */
#[Layout('components.layouts.app')]
class General extends Component
{
    public string $timezone = '';

    public function mount(): void
    {
        Gate::authorize('admin.settings.manage');

        $this->timezone = CompanyTime::zone();
    }

    public function updatedTimezone(): void
    {
        $this->validateOnly('timezone');
    }

    public function save(): void
    {
        Gate::authorize('admin.settings.manage');
        $this->validate();

        // app_setting keeps who changed it last, and when.
        $user = Auth::id();
        CompanyTime::set($this->timezone, $user === null ? null : (string) $user);

        Notify::success(__('settings.general.saved', ['zone' => $this->label($this->timezone)]), on: $this);
    }

    /**
     * @return array<string, list<string|Closure>>
     */
    protected function rules(): array
    {
        return [
            'timezone' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! CompanyTime::valid($value)) {
                    $fail(__('settings.general.invalid'));
                }
            }],
        ];
    }

    public function render(): View
    {
        $valid = CompanyTime::valid($this->timezone);

        return view('livewire.settings.general', [
            'zones' => $this->zones(),
            // What the clock shows there now: the user sees the choice is right before saving.
            'now' => $valid ? CarbonImmutable::now($this->timezone)->locale(app()->getLocale())->isoFormat('D MMM YYYY, HH:mm') : '',
        ])->title(__('settings.tabs.general'));
    }

    /**
     * Every time zone, "Asia/Beirut (UTC+03:00)", by name.
     *
     * @return array<string, string>
     */
    private function zones(): array
    {
        $zones = [];

        foreach (DateTimeZone::listIdentifiers() as $zone) {
            $zones[$zone] = $this->label($zone);
        }

        return $zones;
    }

    private function label(string $zone): string
    {
        return __('settings.general.zone_option', [
            'zone' => str_replace('_', ' ', $zone),
            'offset' => CarbonImmutable::now($zone)->format('P'),
        ]);
    }
}
