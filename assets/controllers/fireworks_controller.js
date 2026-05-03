/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import confetti from 'canvas-confetti';

// Default canvas-confetti runs in a Web Worker spawned from a Blob URL, which
// strict CSPs (without 'unsafe-eval' or blob: in script-src) block. The host's
// CSP on prod refuses both, so we run the confetti synchronously in the main
// thread instead — visually identical for a one-shot success animation.
export default class extends Controller {
    connect() {
        this.fire = confetti.create(undefined, {
            useWorker: false,
            resize: true,
        });
        this.launchFireworks();
    }

    launchFireworks() {
        const colors = ['#8B1538', '#FFD700', '#FF6B6B', '#4ECDC4'];

        this.fire({
            particleCount: 50,
            angle: 60,
            spread: 55,
            origin: { x: 0 },
            colors,
        });

        this.fire({
            particleCount: 50,
            angle: 120,
            spread: 55,
            origin: { x: 1 },
            colors,
        });
    }

    disconnect() {
        if (this.fire && typeof this.fire.reset === 'function') {
            this.fire.reset();
        }
    }
}
