/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Hero video: autoplays muted; clicking the video toggles play/pause (a
 * centered play button shows while paused, driven by `data-playing` on the
 * root, and stays reachable by keyboard) and the top-right buttons toggle the
 * sound and enter or leave fullscreen. Icons are swapped by toggling the
 * `hidden` class on paired targets. Visitors who prefer reduced motion get
 * the poster instead of an autoplaying loop. iOS Safari has no Fullscreen API
 * on <video> but exposes webkitEnterFullscreen(), used as a fallback.
 */
export default class extends Controller {
    static targets = ['video', 'soundOn', 'soundOff', 'soundButton', 'playButton', 'enterFullscreen', 'exitFullscreen'];
    static values = { playLabel: String, pauseLabel: String, soundOnLabel: String, soundOffLabel: String };

    connect() {
        this.boundSyncFullscreen = this.syncFullscreen.bind(this);
        document.addEventListener('fullscreenchange', this.boundSyncFullscreen);
        document.addEventListener('webkitfullscreenchange', this.boundSyncFullscreen);

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this.videoTarget.pause();
        }

        this.syncSound();
        this.syncPlay();
    }

    disconnect() {
        document.removeEventListener('fullscreenchange', this.boundSyncFullscreen);
        document.removeEventListener('webkitfullscreenchange', this.boundSyncFullscreen);
    }

    togglePlay() {
        if (this.videoTarget.paused) {
            this.videoTarget.play();
        } else {
            this.videoTarget.pause();
        }
    }

    toggleSound() {
        this.videoTarget.muted = !this.videoTarget.muted;
        if (!this.videoTarget.muted && this.videoTarget.paused) {
            this.videoTarget.play();
        }
        this.syncSound();
    }

    toggleFullscreen() {
        if (document.fullscreenElement || document.webkitFullscreenElement) {
            (document.exitFullscreen || document.webkitExitFullscreen).call(document);
            return;
        }

        if (this.element.requestFullscreen) {
            this.element.requestFullscreen();
        } else if (this.element.webkitRequestFullscreen) {
            this.element.webkitRequestFullscreen();
        } else if (this.videoTarget.webkitEnterFullscreen) {
            this.videoTarget.webkitEnterFullscreen();
        }
    }

    syncPlay() {
        const paused = this.videoTarget.paused;
        this.element.dataset.playing = paused ? 'false' : 'true';
        this.playButtonTarget.setAttribute('aria-label', paused ? this.playLabelValue : this.pauseLabelValue);
    }

    syncSound() {
        const muted = this.videoTarget.muted;
        this.soundOnTarget.classList.toggle('hidden', muted);
        this.soundOffTarget.classList.toggle('hidden', !muted);
        this.soundButtonTarget.setAttribute('aria-pressed', String(!muted));
        this.soundButtonTarget.setAttribute('aria-label', muted ? this.soundOnLabelValue : this.soundOffLabelValue);
    }

    syncFullscreen() {
        const active = (document.fullscreenElement || document.webkitFullscreenElement) === this.element;
        this.enterFullscreenTarget.classList.toggle('hidden', active);
        this.exitFullscreenTarget.classList.toggle('hidden', !active);
    }
}
