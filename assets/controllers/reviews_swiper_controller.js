/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Carousel d'avis — infinite loop avec clones
 * La carte active est toujours centrée.
 * Structure : [clone_dernier | real_0 … real_N-1 | clone_premier]
 */
export default class extends Controller {
    static targets = ['track', 'card', 'indicator'];

    static values = {
        currentIndex: { type: Number, default: 0 },
        autoplay: { type: Boolean, default: false },
        autoplayDelay: { type: Number, default: 5000 }
    };

    connect() {
        this.isTransitioning = false;
        this.originalCards = [...this.cardTargets];
        this.totalCards = this.originalCards.length;
        this.visibleCards = 3;
        this.cardWidth = 0;
        this.gap = 24;
        this.trackPaddingLeft = 0;
        this.internalIndex = 1; // 0=clone_last | 1..N=vrais | N+1=clone_first

        // Variables pour le drag
        this.touchStartX = 0;
        this.touchStartY = 0;
        this.baseOffset = 0;
        this.isDragging = false;
        this.isVerticalScroll = false;

        this.setupClones();

        // GPU hint
        this.trackTarget.style.willChange = 'transform';

        this.handleResize();
        this.updateCarousel(false);
        this.prewarmBoundaries();

        if (this.autoplayValue) {
            this.startAutoplay();
        }

        this.setupTouchEvents();
        this.boundHandleResize = () => this.handleResize();
        window.addEventListener('resize', this.boundHandleResize);
    }

    disconnect() {
        this.stopAutoplay();
        window.removeEventListener('resize', this.boundHandleResize);

        if (this.hasTrackTarget) {
            this.trackTarget.removeEventListener('touchstart', this.boundHandleTouchStart);
            this.trackTarget.removeEventListener('touchmove', this.boundHandleTouchMove);
            this.trackTarget.removeEventListener('touchend', this.boundHandleTouchEnd);
            this.trackTarget.removeEventListener('mousedown', this.boundHandleMouseDown);
        }
        window.removeEventListener('mousemove', this.boundHandleMouseMove);
        window.removeEventListener('mouseup', this.boundHandleMouseUp);
    }

    /**
     * Force le browser à rasteriser clone_first et clone_last avant que l'utilisateur
     * n'y arrive. On déplace le track sur les deux extrêmes dans le même task JS
     * (avant tout paint) — l'utilisateur ne voit jamais les positions intermédiaires.
     */
    prewarmBoundaries() {
        const track = this.trackTarget;

        // Aller au dernier réel (clone_first devient adjacent et est rasterisé)
        this.internalIndex = this.totalCards;
        track.style.transition = 'none';
        track.style.transform = `translateX(${this.getCurrentOffset()}px)`;

        // Forcer un layout synchrone (flush) pour déclencher la rasterisation
        void track.offsetHeight;

        // Revenir au premier réel — toujours dans le même task, aucun frame peint
        this.internalIndex = 1;
        track.style.transform = `translateX(${this.getCurrentOffset()}px)`;
    }

    /**
     * Crée 1 clone de chaque côté.
     * Les images des clones passent en eager pour éviter le flash au premier affichage.
     */
    setupClones() {
        const lastClone  = this.originalCards[this.totalCards - 1].cloneNode(true);
        const firstClone = this.originalCards[0].cloneNode(true);

        [lastClone, firstClone].forEach(clone => {
            clone.removeAttribute('data-reviews-swiper-target');
            clone.setAttribute('aria-hidden', 'true');
            clone.querySelectorAll('img').forEach(img => img.removeAttribute('loading'));
        });

        this.trackTarget.prepend(lastClone);
        this.trackTarget.append(firstClone);

        // allCards = [clone_last, real_0 … real_N-1, clone_first]
        this.allCards = [lastClone, ...this.originalCards, firstClone];

        // Promote chaque carte en layer GPU dès le départ → pas de flash au premier affichage
        this.allCards.forEach(card => { card.style.willChange = 'transform, opacity'; });
    }

    /**
     * Configure les événements tactiles et souris
     */
    setupTouchEvents() {
        if (!this.hasTrackTarget) return;

        this.boundHandleTouchStart = this.handleTouchStart.bind(this);
        this.boundHandleTouchMove  = this.handleTouchMove.bind(this);
        this.boundHandleTouchEnd   = this.handleTouchEnd.bind(this);

        this.trackTarget.addEventListener('touchstart', this.boundHandleTouchStart, { passive: true });
        this.trackTarget.addEventListener('touchmove',  this.boundHandleTouchMove,  { passive: false });
        this.trackTarget.addEventListener('touchend',   this.boundHandleTouchEnd,   { passive: true });

        this.boundHandleMouseDown = this.handleMouseDown.bind(this);
        this.boundHandleMouseMove = this.handleMouseMove.bind(this);
        this.boundHandleMouseUp   = this.handleMouseUp.bind(this);

        this.trackTarget.addEventListener('mousedown', this.boundHandleMouseDown);
    }

    /**
     * Calcule l'offset pour centrer la carte active (internalIndex) dans le conteneur
     */
    getCurrentOffset() {
        const containerWidth = this.element.offsetWidth;
        return (containerWidth - this.cardWidth) / 2 - this.trackPaddingLeft - this.internalIndex * (this.cardWidth + this.gap);
    }

    handleMouseDown(event) {
        if (this.isTransitioning) return;
        event.preventDefault();

        this.touchStartX = event.clientX;
        this.isDragging = false;
        this.baseOffset = this.getCurrentOffset();
        this.trackTarget.style.transition = 'none';
        this.trackTarget.style.cursor = 'grabbing';

        window.addEventListener('mousemove', this.boundHandleMouseMove);
        window.addEventListener('mouseup', this.boundHandleMouseUp);
    }

    handleMouseMove(event) {
        const deltaX = event.clientX - this.touchStartX;
        if (!this.isDragging && Math.abs(deltaX) > 5) this.isDragging = true;
        if (!this.isDragging) return;
        this.trackTarget.style.transform = `translateX(${this.baseOffset + deltaX}px)`;
    }

    handleMouseUp(event) {
        window.removeEventListener('mousemove', this.boundHandleMouseMove);
        window.removeEventListener('mouseup', this.boundHandleMouseUp);
        this.trackTarget.style.cursor = '';
        if (!this.isDragging) return;

        const deltaX = event.clientX - this.touchStartX;
        const threshold = this.cardWidth * 0.2;

        if (this.autoplayValue) this.stopAutoplay();

        if (deltaX < -threshold)      this.next();
        else if (deltaX > threshold)  this.prev();
        else                          this.updateCarousel(true);
    }

    /**
     * Gère le début du touch — mémorise la position de départ
     */
    handleTouchStart(event) {
        if (this.isTransitioning) return;

        this.touchStartX = event.changedTouches[0].clientX;
        this.touchStartY = event.changedTouches[0].clientY;
        this.isDragging = false;
        this.isVerticalScroll = false;

        this.baseOffset = this.getCurrentOffset();
        this.trackTarget.style.transition = 'none';
    }

    /**
     * Suit le doigt en temps réel
     */
    handleTouchMove(event) {
        const deltaX = event.changedTouches[0].clientX - this.touchStartX;
        const deltaY = Math.abs(event.changedTouches[0].clientY - this.touchStartY);

        if (!this.isDragging && !this.isVerticalScroll) {
            if (deltaY > Math.abs(deltaX) && deltaY > 8) { this.isVerticalScroll = true; return; }
            if (Math.abs(deltaX) > 8) this.isDragging = true;
        }

        if (this.isVerticalScroll) return;
        if (!this.isDragging) return;

        event.preventDefault();
        this.trackTarget.style.transform = `translateX(${this.baseOffset + deltaX}px)`;
    }

    /**
     * Snap vers la carte la plus proche au relâchement
     */
    handleTouchEnd(event) {
        if (this.isVerticalScroll || !this.isDragging) return;

        const deltaX = event.changedTouches[0].clientX - this.touchStartX;
        const threshold = this.cardWidth * 0.2;

        if (this.autoplayValue) this.stopAutoplay();

        if (deltaX < -threshold)      this.next();
        else if (deltaX > threshold)  this.prev();
        else                          this.updateCarousel(true);
    }

    /**
     * Gestion du responsive
     */
    handleResize() {
        const width = window.innerWidth;
        if (width < 768)       this.visibleCards = 1;
        else if (width < 1024) this.visibleCards = 2;
        else                   this.visibleCards = 3;

        this.currentIndexValue = 0;
        this.internalIndex = 1;
        this.cardWidth = 0;
        this.updateCarousel(false);
    }

    /**
     * Démarre le défilement automatique
     */
    startAutoplay() {
        this.autoplayInterval = setInterval(() => this.next(), this.autoplayDelayValue);
    }

    /**
     * Arrête le défilement automatique
     */
    stopAutoplay() {
        if (this.autoplayInterval) clearInterval(this.autoplayInterval);
    }

    /**
     * Navigue vers l'avis précédent
     */
    prev() {
        if (this.isTransitioning) return;
        this.currentIndexValue = (this.currentIndexValue - 1 + this.totalCards) % this.totalCards;
        this.internalIndex--;
        this.updateCarousel(true);
    }

    /**
     * Navigue vers l'avis suivant
     */
    next() {
        if (this.isTransitioning) return;
        this.currentIndexValue = (this.currentIndexValue + 1) % this.totalCards;
        this.internalIndex++;
        this.updateCarousel(true);
    }

    /**
     * Navigue vers un avis spécifique
     */
    goTo(event) {
        if (this.isTransitioning) return;

        const targetIndex = parseInt(event.currentTarget.dataset.index);
        if (targetIndex >= 0 && targetIndex < this.totalCards) {
            this.currentIndexValue = targetIndex;
            this.internalIndex = targetIndex + 1;
            this.updateCarousel(true);
        }
    }

    /**
     * Met à jour le carousel
     */
    updateCarousel(animate = true) {
        if (!this.hasTrackTarget || !this.allCards) return;

        if (!animate || this.cardWidth === 0) {
            const trackStyle = window.getComputedStyle(this.trackTarget);
            this.trackPaddingLeft = parseFloat(trackStyle.paddingLeft) || 0;
            const trackPaddingRight = parseFloat(trackStyle.paddingRight) || 0;
            const containerWidth = this.element.offsetWidth - this.trackPaddingLeft - trackPaddingRight;
            this.gap = parseFloat(trackStyle.gap) || 24;
            this.cardWidth = (containerWidth - (this.gap * (this.visibleCards - 1))) / this.visibleCards;

            this.allCards.forEach(card => {
                card.style.width = `${this.cardWidth}px`;
                card.style.minWidth = `${this.cardWidth}px`;
            });
        }

        if (animate) {
            this.isTransitioning = true;
            this.trackTarget.style.transition = 'transform 400ms cubic-bezier(0.25, 0.46, 0.45, 0.94)';
            setTimeout(() => {
                this.isTransitioning = false;
                this.handleLoopBoundary();
            }, 400);
        } else {
            this.trackTarget.style.transition = 'none';
        }

        this.trackTarget.style.transform = `translateX(${this.getCurrentOffset()}px)`;

        this.updateActiveCards(animate);
        this.updateIndicators();
    }

    /**
     * Après la transition sur un clone → jump instantané vers la vraie carte (invisible visuellement).
     * internalIndex 0           → clone_last  → jump vers totalCards (dernier réel)
     * internalIndex totalCards+1 → clone_first → jump vers 1 (premier réel)
     */
    handleLoopBoundary() {
        if (this.internalIndex === 0) {
            this.internalIndex = this.totalCards;
        } else if (this.internalIndex === this.totalCards + 1) {
            this.internalIndex = 1;
        } else {
            return;
        }

        this.trackTarget.style.transition = 'none';
        this.trackTarget.style.transform = `translateX(${this.getCurrentOffset()}px)`;
        this.updateActiveCards(false);
    }

    /**
     * Met en avant la carte centrale (scale + opacité)
     * animate=false → transition: none (rendu initial, loop boundary, resize)
     */
    updateActiveCards(animate = true) {
        if (!this.allCards) return;

        if (this.visibleCards !== 3) {
            this.allCards.forEach(card => {
                card.style.transition = 'none';
                card.style.transform = 'scale(1)';
                card.style.opacity = '1';
            });
            return;
        }

        const transition = animate
            ? 'transform 400ms cubic-bezier(0.25, 0.46, 0.45, 0.94), opacity 400ms ease'
            : 'none';

        this.allCards.forEach((card, index) => {
            card.style.transition = transition;
            card.style.transform = index === this.internalIndex ? 'scale(1)'  : 'scale(0.93)';
            card.style.opacity   = index === this.internalIndex ? '1'         : '0.5';
        });
    }

    /**
     * Met à jour les indicateurs
     */
    updateIndicators() {
        if (!this.hasIndicatorTarget) return;

        this.indicatorTargets.forEach((indicator, index) => {
            if (index === this.currentIndexValue) {
                indicator.classList.remove('bg-neutral-300', 'w-2');
                indicator.classList.add('bg-primary', 'w-4');
            } else {
                indicator.classList.remove('bg-primary', 'w-4');
                indicator.classList.add('bg-neutral-300', 'w-2');
            }
        });
    }
}
