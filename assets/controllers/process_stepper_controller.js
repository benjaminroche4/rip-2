/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Animated vertical process stepper.
 *
 * As the user scrolls through the steps, the step crossing the viewport's
 * middle becomes "active": its number circle turns primary, its card gets a
 * primary border, and the progress rail fills up to that step.
 */
export default class extends Controller {
    static targets = ['step', 'circle', 'card', 'progressBar'];

    connect() {
        this.activeIndex = -1;
        this.#activate(0);

        this.observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    const index = this.stepTargets.indexOf(entry.target);
                    if (index !== -1) this.#activate(index);
                });
            },
            { rootMargin: '-50% 0px -45% 0px', threshold: 0 },
        );

        this.stepTargets.forEach((step) => this.observer.observe(step));
    }

    disconnect() {
        this.observer?.disconnect();
    }

    #activate(index) {
        if (index === this.activeIndex) return;
        this.activeIndex = index;

        this.stepTargets.forEach((step, i) => {
            // Cumulative fill: every step up to the current one stays coloured,
            // so the timeline fills progressively instead of a single highlight
            // jumping around. Only the current step's card gets the accent border.
            const reached = i <= index;
            const current = i === index;
            const circle = this.circleTargets[i];
            const card = this.cardTargets[i];

            circle.classList.toggle('bg-primary', reached);
            circle.classList.toggle('text-white', reached);
            circle.classList.toggle('bg-neutral-200', !reached);
            circle.classList.toggle('text-black', !reached);

            card.classList.toggle('border-primary', current);
            card.classList.toggle('border-transparent', !current);
            card.classList.toggle('shadow-[0_4px_20px_rgba(113,23,46,0.08)]', current);

            if (current) step.setAttribute('aria-current', 'step');
            else step.removeAttribute('aria-current');
        });

        if (this.hasProgressBarTarget) {
            const pct = ((index + 1) / this.stepTargets.length) * 100;
            this.progressBarTarget.style.height = `${pct}%`;
        }
    }
}
