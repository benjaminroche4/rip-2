/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Decorative background video (muted autoplay loop, no controls).
 * Visitors who prefer reduced motion get the poster instead of the loop.
 */
export default class extends Controller {
    static targets = ['video'];

    connect() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this.videoTarget.pause();
        }
    }
}
