/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Native scroll-snap carousel (mobile) with dot indicators, mouse-drag and
 * autoplay.
 *
 * The carousel itself is plain CSS overflow-x scroll-snap. This controller:
 *   - reflects the current slide into the dots (data-active="true|false")
 *   - lets the user drag with the mouse (touch keeps native momentum scroll)
 *   - auto-advances every `interval` ms, pausing on hover/drag and resuming
 *     after; disabled when prefers-reduced-motion is set or when the track
 *     isn't scrollable (i.e. the lg grid layout)
 *
 * Wiring:
 *   <section data-controller="snap-carousel" data-snap-carousel-interval-value="4000">
 *     <div data-snap-carousel-target="track" class="… overflow-x-auto">…cards…</div>
 *     <button data-snap-carousel-target="dot" data-index="0"
 *             data-action="snap-carousel#to">…</button>
 */
export default class extends Controller {
    static targets = ['track', 'dot'];
    static values = { interval: { type: Number, default: 4000 } };

    connect() {
        this._ticking = false;
        this._dragging = false;
        this._timer = null;
        this._reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this._onScroll = () => this._schedule();
        this._onPointerDown = (e) => this._pointerDown(e);
        this._onPointerMove = (e) => this._pointerMove(e);
        this._onPointerUp = () => this._pointerUp();
        this._onEnter = () => this._pauseAutoplay();
        this._onLeave = () => this._resumeAutoplay();
        this._onClickCapture = (e) => this._suppressClickIfDragged(e);

        this.trackTarget.addEventListener('scroll', this._onScroll, { passive: true });
        this.trackTarget.addEventListener('pointerdown', this._onPointerDown);
        window.addEventListener('pointermove', this._onPointerMove);
        window.addEventListener('pointerup', this._onPointerUp);
        window.addEventListener('pointercancel', this._onPointerUp);
        this.element.addEventListener('pointerenter', this._onEnter);
        this.element.addEventListener('pointerleave', this._onLeave);
        this.element.addEventListener('click', this._onClickCapture, true);

        this._update();
        this._startAutoplay();
    }

    disconnect() {
        this.trackTarget.removeEventListener('scroll', this._onScroll);
        this.trackTarget.removeEventListener('pointerdown', this._onPointerDown);
        window.removeEventListener('pointermove', this._onPointerMove);
        window.removeEventListener('pointerup', this._onPointerUp);
        window.removeEventListener('pointercancel', this._onPointerUp);
        this.element.removeEventListener('pointerenter', this._onEnter);
        this.element.removeEventListener('pointerleave', this._onLeave);
        this.element.removeEventListener('click', this._onClickCapture, true);
        this._pauseAutoplay();
    }

    // ── Indicators ───────────────────────────────────────────────────────

    _schedule() {
        if (this._ticking) {
            return;
        }
        this._ticking = true;
        requestAnimationFrame(() => {
            this._ticking = false;
            this._update();
        });
    }

    _update() {
        const index = this._index;
        this.dotTargets.forEach((dot, i) => {
            dot.dataset.active = i === index ? 'true' : 'false';
            dot.setAttribute('aria-current', i === index ? 'true' : 'false');
        });
    }

    get _count() {
        return this.trackTarget.children.length;
    }

    // Distance between two consecutive slides (card width + gap).
    get _step() {
        const children = this.trackTarget.children;
        if (children.length < 2) {
            return this.trackTarget.clientWidth || 1;
        }
        return children[1].offsetLeft - children[0].offsetLeft || 1;
    }

    get _index() {
        return Math.max(0, Math.min(this._count - 1, Math.round(this.trackTarget.scrollLeft / this._step)));
    }

    get _scrollable() {
        return this.trackTarget.scrollWidth - this.trackTarget.clientWidth > 1;
    }

    // ── Navigation ───────────────────────────────────────────────────────

    to(event) {
        this._scrollTo(parseInt(event.currentTarget.dataset.index ?? '0', 10));
        this._restartAutoplay();
    }

    prev() {
        this._scrollTo(this._index - 1);
        this._restartAutoplay();
    }

    next() {
        this._scrollTo(this._index + 1);
        this._restartAutoplay();
    }

    _scrollTo(index) {
        const target = Math.max(0, Math.min(this._count - 1, index));
        const child = this.trackTarget.children[target];
        if (child) {
            this.trackTarget.scrollTo({ left: child.offsetLeft, behavior: 'smooth' });
        }
    }

    // ── Mouse drag (touch keeps native scrolling) ────────────────────────

    _pointerDown(event) {
        if (event.pointerType !== 'mouse' || !this._scrollable) {
            return;
        }
        this._dragging = true;
        this._moved = false;
        this._startX = event.clientX;
        this._startScroll = this.trackTarget.scrollLeft;
        // Suspend snap while dragging so scrollLeft tracks the cursor 1:1.
        this.trackTarget.style.scrollSnapType = 'none';
        this.trackTarget.style.cursor = 'grabbing';
        this.trackTarget.style.userSelect = 'none';
        this._pauseAutoplay();
    }

    _pointerMove(event) {
        if (!this._dragging) {
            return;
        }
        const dx = event.clientX - this._startX;
        if (Math.abs(dx) > 3) {
            this._moved = true;
        }
        this.trackTarget.scrollLeft = this._startScroll - dx;
    }

    _pointerUp() {
        if (!this._dragging) {
            return;
        }
        this._dragging = false;
        this.trackTarget.style.scrollSnapType = '';
        this.trackTarget.style.cursor = '';
        this.trackTarget.style.userSelect = '';
        // Settle on the nearest slide, then resume autoplay.
        this._scrollTo(this._index);
        this._resumeAutoplay();
    }

    // Swallow the click that ends a drag so a dot/card underneath doesn't fire.
    _suppressClickIfDragged(event) {
        if (this._moved) {
            event.preventDefault();
            event.stopPropagation();
            this._moved = false;
        }
    }

    // ── Autoplay ─────────────────────────────────────────────────────────

    _startAutoplay() {
        if (this._reducedMotion || this._timer || this.intervalValue <= 0) {
            return;
        }
        this._timer = window.setInterval(() => this._tick(), this.intervalValue);
    }

    _tick() {
        if (!this._scrollable || this._dragging) {
            return;
        }
        this._scrollTo((this._index + 1) % this._count);
    }

    _pauseAutoplay() {
        if (this._timer) {
            window.clearInterval(this._timer);
            this._timer = null;
        }
    }

    _resumeAutoplay() {
        if (!this._dragging) {
            this._startAutoplay();
        }
    }

    _restartAutoplay() {
        this._pauseAutoplay();
        this._resumeAutoplay();
    }
}
