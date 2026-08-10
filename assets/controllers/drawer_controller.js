/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Right-hand sliding drawer: a trigger opens a fixed panel that slides in
 * from the right over a dimmed backdrop. Esc or a backdrop/close click
 * slides it back out. The panel content stays in the DOM (LiveComponents
 * inside keep their state across open/close).
 */
export default class extends Controller {
    static targets = ['root', 'panel', 'backdrop'];

    disconnect() {
        clearTimeout(this.timer);
        cancelAnimationFrame(this.frame);
    }

    open() {
        clearTimeout(this.timer);
        this.rootTarget.hidden = false;
        this.frame = requestAnimationFrame(() => {
            this.frame = requestAnimationFrame(() => {
                this.panelTarget.classList.remove('translate-x-full');
                this.backdropTarget.classList.remove('opacity-0');
            });
        });
    }

    close() {
        if (this.rootTarget.hidden) {
            return;
        }
        cancelAnimationFrame(this.frame);
        this.panelTarget.classList.add('translate-x-full');
        this.backdropTarget.classList.add('opacity-0');
        this.timer = setTimeout(() => {
            this.rootTarget.hidden = true;
        }, 200);
    }
}
