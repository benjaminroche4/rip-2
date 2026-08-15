/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Segmented one-time-code input. Progressive enhancement over a real text
 * input (the form field, kept for no-JS and submission): on connect the
 * plain input is hidden and the boxes take over, mirroring every change
 * back into it. Pasting a full code anywhere fills all boxes at once.
 *
 * Targets: input (the real form field), box (one per character).
 * All box events are wired through data-action, nothing to clean up here.
 */
export default class extends Controller {
    static targets = ['input', 'box'];

    connect() {
        this.inputTarget.classList.add('hidden');
        this.element.querySelector('[data-otp-boxes]').classList.remove('hidden');
        this.#fill(this.inputTarget.value);
    }

    disconnect() {
        // Restore the plain input so a Turbo cache snapshot stays usable.
        this.inputTarget.classList.remove('hidden');
        this.element.querySelector('[data-otp-boxes]')?.classList.add('hidden');
    }

    input(event) {
        const box = event.target;
        const value = this.#clean(box.value);
        // Typing over a filled box keeps the last typed character.
        box.value = value.slice(-1);
        if (box.value && event.target !== this.boxTargets.at(-1)) {
            this.boxTargets[this.boxTargets.indexOf(box) + 1].focus();
        }
        this.#sync();
    }

    keydown(event) {
        const index = this.boxTargets.indexOf(event.target);
        if ('Backspace' === event.key && '' === event.target.value && index > 0) {
            event.preventDefault();
            const previous = this.boxTargets[index - 1];
            previous.value = '';
            previous.focus();
            this.#sync();
        } else if ('ArrowLeft' === event.key && index > 0) {
            event.preventDefault();
            this.boxTargets[index - 1].focus();
        } else if ('ArrowRight' === event.key && index < this.boxTargets.length - 1) {
            event.preventDefault();
            this.boxTargets[index + 1].focus();
        }
    }

    paste(event) {
        event.preventDefault();
        this.#fill(event.clipboardData?.getData('text') ?? '');
    }

    select(event) {
        event.target.select();
    }

    #fill(raw) {
        const code = this.#clean(raw).slice(0, this.boxTargets.length);
        this.boxTargets.forEach((box, i) => {
            box.value = code[i] ?? '';
        });
        this.#sync();
        if (code) {
            this.boxTargets[Math.min(code.length, this.boxTargets.length - 1)].focus();
        }
    }

    #clean(value) {
        return value.replace(/[^a-z0-9]/gi, '').toUpperCase();
    }

    #sync() {
        this.inputTarget.value = this.boxTargets.map((box) => box.value).join('');
    }
}
