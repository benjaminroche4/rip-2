import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['track', 'prev', 'next'];
    static values = {
        current: { type: Number, default: 0 },
        max: { type: Number, default: 0 },
        autoplayDelay: { type: Number, default: 4000 }
    };

    connect() {
        this.isDragging = false;
        this.isVerticalScroll = false;
        this.isHovering = false;
        this.startX = 0;
        this.startY = 0;
        this.baseOffset = 0;
        this.step = 0;
        this.autoplayTimer = null;

        this.setupEvents();
        this.updateStep();
        this.render(false);
        this.startAutoplay();

        this.boundHandleResize = () => { this.updateStep(); this.render(false); };
        window.addEventListener('resize', this.boundHandleResize);

        this.boundPause  = () => { this.isHovering = true;  this.stopAutoplay(); };
        this.boundResume = () => { this.isHovering = false; this.startAutoplay(); };
        this.element.addEventListener('mouseenter', this.boundPause);
        this.element.addEventListener('mouseleave', this.boundResume);
    }

    disconnect() {
        this.stopAutoplay();
        window.removeEventListener('resize', this.boundHandleResize);
        window.removeEventListener('mousemove', this.boundHandleMouseMove);
        window.removeEventListener('mouseup', this.boundHandleMouseUp);
        this.element.removeEventListener('mouseenter', this.boundPause);
        this.element.removeEventListener('mouseleave', this.boundResume);

        if (this.hasTrackTarget) {
            this.trackTarget.removeEventListener('touchstart', this.boundHandleTouchStart);
            this.trackTarget.removeEventListener('touchmove', this.boundHandleTouchMove);
            this.trackTarget.removeEventListener('touchend', this.boundHandleTouchEnd);
            this.trackTarget.removeEventListener('mousedown', this.boundHandleMouseDown);
        }
    }

    startAutoplay() {
        this.stopAutoplay();
        if (this.isHovering || this.maxValue === 0) return;
        this.autoplayTimer = setInterval(() => this.tick(), this.autoplayDelayValue);
    }

    stopAutoplay() {
        if (this.autoplayTimer) {
            clearInterval(this.autoplayTimer);
            this.autoplayTimer = null;
        }
    }

    tick() {
        if (this.currentValue >= this.maxValue) {
            this.currentValue = 0;
        } else {
            this.currentValue++;
        }
        this.render(true);
    }

    updateStep() {
        if (!this.hasTrackTarget) return;
        const card = this.trackTarget.firstElementChild;
        if (!card) return;
        const gap = parseFloat(window.getComputedStyle(this.trackTarget).gap) || 20;
        this.step = card.offsetWidth + gap;

        // Compute how many cards fit and update maxValue dynamically
        const containerWidth = this.trackTarget.parentElement.offsetWidth;
        const visibleCards = Math.max(1, Math.round(containerWidth / this.step));
        const totalCards = this.trackTarget.children.length;
        this.maxValue = Math.max(0, totalCards - visibleCards);

        if (this.currentValue > this.maxValue) {
            this.currentValue = this.maxValue;
        }

        // Restart autoplay now that maxValue is known
        this.startAutoplay();
    }

    getCurrentOffset() {
        return -(this.currentValue * this.step);
    }

    prev() {
        if (this.currentValue > 0) this.currentValue--;
        this.render(true);
        this.startAutoplay();
    }

    next() {
        if (this.currentValue < this.maxValue) this.currentValue++;
        this.render(true);
        this.startAutoplay();
    }

    render(animate = true) {
        if (!this.hasTrackTarget) return;

        this.trackTarget.style.transition = animate ? 'transform 500ms ease-in-out' : 'none';
        this.trackTarget.style.transform = `translateX(${this.getCurrentOffset()}px)`;

        const atStart = this.currentValue === 0;
        const atEnd = this.currentValue === this.maxValue;

        this.prevTargets.forEach(btn => {
            btn.disabled = atStart;
            btn.classList.toggle('opacity-30', atStart);
            btn.classList.toggle('cursor-not-allowed', atStart);
            btn.classList.toggle('pointer-events-none', atStart);
        });

        this.nextTargets.forEach(btn => {
            btn.disabled = atEnd;
            btn.classList.toggle('opacity-30', atEnd);
            btn.classList.toggle('cursor-not-allowed', atEnd);
            btn.classList.toggle('pointer-events-none', atEnd);
        });
    }

    setupEvents() {
        if (!this.hasTrackTarget) return;

        this.boundHandleTouchStart = this.handleTouchStart.bind(this);
        this.boundHandleTouchMove  = this.handleTouchMove.bind(this);
        this.boundHandleTouchEnd   = this.handleTouchEnd.bind(this);
        this.boundHandleMouseDown  = this.handleMouseDown.bind(this);
        this.boundHandleMouseMove  = this.handleMouseMove.bind(this);
        this.boundHandleMouseUp    = this.handleMouseUp.bind(this);

        this.trackTarget.addEventListener('touchstart', this.boundHandleTouchStart, { passive: true });
        this.trackTarget.addEventListener('touchmove',  this.boundHandleTouchMove,  { passive: false });
        this.trackTarget.addEventListener('touchend',   this.boundHandleTouchEnd,   { passive: true });
        this.trackTarget.addEventListener('mousedown',  this.boundHandleMouseDown);
    }

    handleMouseDown(event) {
        event.preventDefault();
        this.stopAutoplay();
        this.isDragging = false;
        this.startX = event.clientX;
        this.baseOffset = this.getCurrentOffset();
        this.trackTarget.style.transition = 'none';
        this.trackTarget.style.cursor = 'grabbing';

        window.addEventListener('mousemove', this.boundHandleMouseMove);
        window.addEventListener('mouseup',   this.boundHandleMouseUp);
    }

    handleMouseMove(event) {
        const deltaX = event.clientX - this.startX;
        if (!this.isDragging && Math.abs(deltaX) > 5) this.isDragging = true;
        if (!this.isDragging) return;
        this.trackTarget.style.transform = `translateX(${this.baseOffset + deltaX}px)`;
    }

    handleMouseUp(event) {
        window.removeEventListener('mousemove', this.boundHandleMouseMove);
        window.removeEventListener('mouseup',   this.boundHandleMouseUp);
        this.trackTarget.style.cursor = '';
        if (!this.isDragging) return;

        const deltaX = event.clientX - this.startX;
        const threshold = this.step * 0.25;
        if      (deltaX < -threshold) this.next();
        else if (deltaX >  threshold) this.prev();
        else { this.render(true); this.startAutoplay(); }
    }

    handleTouchStart(event) {
        this.stopAutoplay();
        this.isDragging = false;
        this.isVerticalScroll = false;
        this.startX = event.changedTouches[0].clientX;
        this.startY = event.changedTouches[0].clientY;
        this.baseOffset = this.getCurrentOffset();
        this.trackTarget.style.transition = 'none';
    }

    handleTouchMove(event) {
        const deltaX = event.changedTouches[0].clientX - this.startX;
        const deltaY = Math.abs(event.changedTouches[0].clientY - this.startY);

        if (!this.isDragging && !this.isVerticalScroll) {
            if (deltaY > Math.abs(deltaX) && deltaY > 8) { this.isVerticalScroll = true; return; }
            if (Math.abs(deltaX) > 8) this.isDragging = true;
        }

        if (this.isVerticalScroll || !this.isDragging) return;
        event.preventDefault();
        this.trackTarget.style.transform = `translateX(${this.baseOffset + deltaX}px)`;
    }

    handleTouchEnd(event) {
        if (this.isVerticalScroll || !this.isDragging) return;
        const deltaX = event.changedTouches[0].clientX - this.startX;
        const threshold = this.step * 0.25;
        if      (deltaX < -threshold) this.next();
        else if (deltaX >  threshold) this.prev();
        else { this.render(true); this.startAutoplay(); }
    }
}