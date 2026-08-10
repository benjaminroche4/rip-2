/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

/**
 * Re-visits the current page through Turbo when a watched event fires.
 * With `turbo-refresh-method: morph` (admin base layout), the DOM is
 * patched in place — no flash, scroll preserved. Used on the user profile
 * so granting/revoking back-office access reveals or hides the staff-only
 * cards without a manual refresh.
 */
export default class extends Controller {
    refresh() {
        Turbo.visit(window.location.href, { action: 'replace' });
    }
}
