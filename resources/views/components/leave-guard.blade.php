{{--
    Leaving a form with unsaved changes asks first. The root element of a
    Livewire component that uses App\Livewire\Concerns\WarnsBeforeLeaving:
    <x-leave-guard class="flex flex-col gap-section"> ...the form... </x-leave-guard>

    - A link inside the app (wire:navigate: Cancel, the back link, the sidebar)
      is held and handed to $wire.leave(), which goes on at once or opens the
      shared confirmation ("Discard changes" / "Keep it").
    - Closing or reloading the tab gets the browser's own "Leave site?"
      question: there a browser allows no other.
    The server says whether anything changed ($wire.unsaved, false again in the
    answer to Save before its redirect). Typing it hasn't received yet counts
    as a change until its next answer arrives.

    The behaviour is written here, not as Alpine.data() in resources/js/app.js:
    registered there it is not ready when the first page's x-data starts
    ("leaveGuard is not defined"), like the other inline x-data components.
--}}
<div
    x-data="{
        typed: false,
        init() {
            const id = $wire.$id;
            this.unhook = Livewire.hook('commit', ({ component, respond }) => {
                if (component.id === id) respond(() => (this.typed = false));
            });
            this.onNavigate = (event) => {
                // Back / forward have already changed the address: let them through.
                if (event.detail.history || ! this.unsaved()) return;
                event.preventDefault();
                $wire.leave(event.detail.url.href);
            };
            this.onUnload = (event) => {
                if (! this.unsaved()) return;
                event.preventDefault();
                event.returnValue = '';
            };
            document.addEventListener('livewire:navigate', this.onNavigate);
            window.addEventListener('beforeunload', this.onUnload);
        },
        destroy() {
            this.unhook();
            document.removeEventListener('livewire:navigate', this.onNavigate);
            window.removeEventListener('beforeunload', this.onUnload);
        },
        unsaved() {
            return this.typed || $wire.unsaved;
        },
    }"
    x-on:input="typed = true"
    x-on:change="typed = true"
    {{ $attributes }}
>
    {{ $slot }}
</div>
