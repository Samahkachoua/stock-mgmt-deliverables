import './bootstrap';

/*
 * A Livewire request that fails becomes a toast (x-toasts) instead of
 * Livewire's own dialogs (CLAUDE.md "Feedback messages"):
 *   - no answer / timeout  -> "Connection lost — your entries are still here, try again."
 *   - 419                  -> "Your session expired — please sign in again"
 *   - 403                  -> "You don't have permission to do this"
 *   - unexpected error     -> the server's message with its ERR- reference
 * In debug mode (local) an unexpected error still opens Livewire's dialog with the details.
 */
document.addEventListener('livewire:init', () => {
    const toast = (detail) => window.dispatchEvent(new CustomEvent('toast', { detail: { toast: detail } }));

    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, content, preventDefault }) => {
            if (content === null || status === 408 || status === 504) {
                preventDefault();

                return toast({ key: 'connection' });
            }

            if (status === 419) {
                preventDefault();

                return toast({ key: 'session_expired' });
            }

            if (status === 403) {
                preventDefault();

                return toast({ key: 'not_allowed' });
            }

            try {
                const body = JSON.parse(content);

                if (body && body.toast) {
                    preventDefault();
                    toast(body.toast);
                }
            } catch {
                // Not ours (e.g. the debug error page): Livewire shows it.
            }
        });
    });
});
