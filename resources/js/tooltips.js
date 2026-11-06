/*
 * Tooltips for icon-only buttons (Bootstrap's Tooltip; its styles live in app.css, not Bootstrap's CSS).
 * Mark a button: data-bs-toggle="tooltip" data-bs-title="{{ __('settings.edit') }}"
 * One delegated instance on <body> covers buttons Livewire adds later.
 */
import Tooltip from 'bootstrap/js/dist/tooltip';

const SELECTOR = '[data-bs-toggle="tooltip"]';

const start = () => {
    Tooltip.getOrCreateInstance(document.body, {
        selector: SELECTOR,
        trigger: 'hover focus',
        placement: 'top',
        container: 'body',
        delay: { show: 250, hide: 0 },
    });
};

// A tooltip whose button is gone (row re-rendered, page changed) would stay on screen.
const removeOrphans = (all = false) => {
    document.querySelectorAll('.tooltip').forEach((tip) => {
        if (all || !document.querySelector(`[aria-describedby="${tip.id}"]`)) {
            tip.remove();
        }
    });
};

// Clicking the button (delete opens a dialog, edit opens a form) closes its tooltip.
document.addEventListener('click', (event) => {
    const trigger = event.target.closest?.(SELECTOR);

    if (trigger) {
        Tooltip.getInstance(trigger)?.hide();
    }
});

document.addEventListener('livewire:navigated', start);
document.addEventListener('livewire:navigating', () => removeOrphans(true));
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ succeed }) => succeed(() => setTimeout(() => removeOrphans())));
});
