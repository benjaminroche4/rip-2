/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

/**
 * User-triggered card removal inside a LiveComponent: collapses and fades
 * the card client-side, then fires the `removePerson` live action so the
 * server-side removal lands on an already-hidden element. Attach to the
 * card; the remove button targets `card-remove#remove`. (Distinct from
 * card-exit, which self-removes a stale card on connect.)
 */
export default class extends Controller {
    static values = { key: Number };

    async remove() {
        if (this.removing) {
            return;
        }
        this.removing = true;

        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            await this.collapse();
        }

        const host = this.element.closest('[data-controller~="live"]');
        if (host) {
            const component = await getComponent(host);
            component.action('removePerson', { key: this.keyValue });
        }
    }

    collapse() {
        const card = this.element;
        card.style.height = `${card.offsetHeight}px`;
        card.style.overflow = 'hidden';

        return new Promise((resolve) => {
            requestAnimationFrame(() => {
                card.style.transition = 'height 0.25s ease, opacity 0.2s ease, transform 0.25s ease';
                card.style.height = '0px';
                card.style.opacity = '0';
                card.style.transform = 'translateY(-4px) scale(0.98)';
                card.addEventListener('transitionend', resolve, { once: true });
                // Safety net if transitionend never fires (hidden ancestors...).
                setTimeout(resolve, 320);
            });
        });
    }
}
