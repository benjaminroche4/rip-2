/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Video testimonial card: click toggles play/pause. Lives inside the shared
 * slider carousel, so clicks that end a drag gesture are ignored (pointer
 * moved more than a few px). Only one card plays at a time: each play
 * broadcasts a window event that pauses every other card.
 */
export default class extends Controller {
    static targets = ['video'];

    connect() {
        this.pointerDownX = 0;
        this.pointerDownY = 0;
        this.boundPauseFromOutside = this.pauseFromOutside.bind(this);
        window.addEventListener('video-card:play', this.boundPauseFromOutside);
    }

    disconnect() {
        window.removeEventListener('video-card:play', this.boundPauseFromOutside);
        if (this.hasVideoTarget) this.videoTarget.pause();
    }

    pointerDown(event) {
        this.pointerDownX = event.clientX;
        this.pointerDownY = event.clientY;
    }

    toggle(event) {
        const movedDistance = Math.hypot(event.clientX - this.pointerDownX, event.clientY - this.pointerDownY);
        if (movedDistance > 10) return;

        if (this.videoTarget.paused) {
            this.play();
        } else {
            this.pause();
        }
    }

    play() {
        window.dispatchEvent(new CustomEvent('video-card:play', { detail: { source: this.element } }));
        this.videoTarget.play();
        this.element.dataset.playing = 'true';
    }

    pause() {
        this.videoTarget.pause();
        this.element.dataset.playing = 'false';
    }

    pauseFromOutside(event) {
        if (event.detail.source === this.element) return;
        this.pause();
    }

    ended() {
        this.pause();
        this.videoTarget.currentTime = 0;
    }
}
