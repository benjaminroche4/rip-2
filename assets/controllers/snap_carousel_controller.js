/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Native scroll-snap carousel (mobile/tablet) with dot indicators, mouse-drag
 * and autoplay.
 *
 * The carousel itself is plain CSS overflow-x scroll-snap. This controller
 * tracks a logical active index (0..count-1) that drives the dots and autoplay.
 * It is decoupled from the raw scroll position on purpose: when several cards
 * are visible at once (e.g. ~2-up on tablet) the last card can never be
 * scrolled flush to the left, so the scroll position alone would top out one
 * step short. With a logical index the dot still advances to the last slide
 * (carousel stays put) and then loops back to the first.
 *
 * Optional targets: dot (toggled via data-active="true|false").
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
        this._active = 0;
        this._ticking = false;
        this._dragging = false;
        this._interacting = false;
        this._autoScrolling = false;
        this._autoScrollTimer = null;
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

        this._render();
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
        if (this._autoScrollTimer) {
            window.clearTimeout(this._autoScrollTimer);
        }
    }

    // ── Indicators ───────────────────────────────────────────────────────

    // Scroll-driven update (user wheel/drag). Ignored while we drive the
    // scroll ourselves, so the logical index set by autoplay/click wins.
    _schedule() {
        if (this._ticking) {
            return;
        }
        this._ticking = true;
        requestAnimationFrame(() => {
            this._ticking = false;
            if (this._autoScrolling) {
                return;
            }
            this._active = this._indexFromScroll;
            this._render();
        });
    }

    _render() {
        this.dotTargets.forEach((dot, i) => {
            dot.dataset.active = i === this._active ? 'true' : 'false';
            dot.setAttribute('aria-current', i === this._active ? 'true' : 'false');
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

    get _indexFromScroll() {
        return Math.max(0, Math.min(this._count - 1, Math.round(this.trackTarget.scrollLeft / this._step)));
    }

    get _scrollable() {
        return this.trackTarget.scrollWidth - this.trackTarget.clientWidth > 1;
    }

    // ── Navigation ───────────────────────────────────────────────────────

    to(event) {
        this._goTo(parseInt(event.currentTarget.dataset.index ?? '0', 10));
        this._restartAutoplay();
    }

    prev() {
        this._goTo(this._active - 1);
        this._restartAutoplay();
    }

    next() {
        this._goTo(this._active + 1);
        this._restartAutoplay();
    }

    // Sets the logical index and scrolls as far as the track allows (the
    // browser clamps scrollLeft, so the last "page" may not move the cards —
    // that's intended: the dot still advances).
    _goTo(index) {
        this._active = Math.max(0, Math.min(this._count - 1, index));
        this._render();

        const child = this.trackTarget.children[this._active];
        if (child) {
            this._beginAutoScroll();
            this.trackTarget.scrollTo({ left: child.offsetLeft, behavior: 'smooth' });
        }
    }

    // Suppress scroll-driven index updates for the duration of a programmatic
    // scroll so a clamped target (no movement) keeps its logical index.
    _beginAutoScroll() {
        this._autoScrolling = true;
        if (this._autoScrollTimer) {
            window.clearTimeout(this._autoScrollTimer);
        }
        this._autoScrollTimer = window.setTimeout(() => {
            this._autoScrolling = false;
            this._autoScrollTimer = null;
        }, 600);
    }

    // ── Mouse drag (touch keeps native scrolling) ────────────────────────

    _pointerDown(event) {
        if (!this._scrollable) {
            return;
        }
        // Any pointer (finger or mouse) pauses autoplay while the user
        // interacts; it resumes on pointer up.
        this._interacting = true;
        this._pauseAutoplay();

        // Only the mouse gets JS drag-to-scroll; touch keeps native momentum
        // scrolling (and snap), which feels better on a phone/tablet.
        if (event.pointerType === 'mouse') {
            this._dragging = true;
            this._moved = false;
            this._startX = event.clientX;
            this._startScroll = this.trackTarget.scrollLeft;
            this.trackTarget.style.scrollSnapType = 'none';
            this.trackTarget.style.cursor = 'grabbing';
            this.trackTarget.style.userSelect = 'none';
        }
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
        if (this._dragging) {
            this._dragging = false;
            this.trackTarget.style.scrollSnapType = '';
            this.trackTarget.style.cursor = '';
            this.trackTarget.style.userSelect = '';
            // Mouse drag disabled snap, so settle on the nearest slide.
            this._goTo(this._indexFromScroll);
        }
        if (this._interacting) {
            this._interacting = false;
            // Touch keeps native snap; just resume autoplay from wherever the
            // scroll landed (the scroll handler keeps the active dot in sync).
            this._resumeAutoplay();
        }
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
        this._goTo((this._active + 1) % this._count);
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
