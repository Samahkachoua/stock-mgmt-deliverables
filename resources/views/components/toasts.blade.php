@use('App\Support\Notify')

{{--
    The app's only feedback messages (CLAUDE.md "Feedback messages"), sent with
    App\Support\Notify. Top-end corner (left in Arabic), stacked, at most 3;
    full width at the top on a phone.
    Success and warning close after config('ui.toast_seconds') (10 s); errors stay until closed.

    Toasts arrive two ways: flashed in the session for this page (after a
    redirect), or as a `toast` browser event (from a Livewire component, or from
    resources/js/app.js for a lost connection / expired session, by `key`).
--}}
@php
    $looks = [
        'success' => config('ui.statuses.completed'),
        'warning' => config('ui.statuses.pending'),
        'error' => config('ui.statuses.rejected'),
    ];
    // Messages the browser needs when the server can't answer.
    $known = [
        'connection' => ['message' => __('feedback.errors.connection')],
        'not_allowed' => ['message' => __('feedback.errors.not_allowed')],
        'session_expired' => ['message' => __('feedback.errors.session_expired'), 'url' => route('login'), 'link' => __('feedback.sign_in')],
    ];
@endphp

<div
    x-data="{
        items: [],
        next: 1,
        looks: @js($looks),
        seconds: @js((int) config('ui.toast_seconds')),
        known: @js($known),
        add(toast) {
            if (! toast) return;
            if (toast.key) toast = { type: 'error', ...this.known[toast.key] };
            if (this.items.some((item) => item.message === toast.message)) return;

            const item = { url: null, link: null, ...toast, id: this.next++ };
            // The link is pointless on the page it leads to.
            if (item.url && new URL(item.url, location.href).pathname === location.pathname) item.url = null;
            this.items.push(item);

            // At most 3: the oldest goes, an error last.
            while (this.items.length > 3) {
                const oldest = this.items.find((other) => other.type !== 'error' && other.id !== item.id) ?? this.items[0];
                this.close(oldest.id);
            }

            if (item.type !== 'error') setTimeout(() => this.close(item.id), this.seconds * 1000);
        },
        close(id) {
            this.items = this.items.filter((item) => item.id !== id);
        },
    }"
    x-init="@js(Notify::flashed()).forEach((toast) => add(toast))"
    x-on:toast.window="add($event.detail.toast)"
    role="region"
    aria-label="{{ __('feedback.region') }}"
    data-toasts
    class="pointer-events-none fixed inset-x-0 top-0 z-[60] flex flex-col gap-2 p-3 md:inset-x-auto md:end-4 md:top-4 md:w-96 md:gap-3 md:p-0 print:hidden"
>
    <template x-for="toast in items" x-bind:key="toast.id">
        <div
            x-bind:role="toast.type === 'error' ? 'alert' : 'status'"
            x-bind:class="looks[toast.type]"
            x-bind:data-toast="toast.type"
            class="pointer-events-auto flex items-start gap-3 rounded-lg border px-3.5 py-2.5 text-body font-semibold shadow-pop"
        >
            {{-- Icon + text, never colour alone. --}}
            <span class="mt-0.5 shrink-0">
                <x-icon name="check_circle" x-show="toast.type === 'success'" />
                <x-icon name="pending" x-show="toast.type === 'warning'" />
                <x-icon name="report" x-show="toast.type === 'error'" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="break-words" x-text="toast.message"></p>
                <a x-show="toast.url" x-bind:href="toast.url" class="mt-1 inline-block underline underline-offset-2" x-text="toast.link"></a>
            </div>
            <button type="button" x-on:click="close(toast.id)" class="-my-1.5 -me-2 grid size-control shrink-0 place-items-center rounded-lg hover:bg-card-hover/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40">
                <x-icon name="close" class="size-4" />
                <span class="sr-only">{{ __('feedback.close') }}</span>
            </button>
        </div>
    </template>
</div>
