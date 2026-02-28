import { Controller } from '@hotwired/stimulus';

/**
 * Controller Stimulus pour le carousel d'avis
 * Affiche 3 avis à la fois et les fait glisser un par un
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
        this.totalCards = this.cardTargets.length;
        this.visibleCards = 3; // Desktop par défaut

        // Cache pour les dimensions
        this.cardWidth = 0;
        this.gap = 24;

        // Variables pour le drag
        this.touchStartX = 0;
        this.touchStartY = 0;
        this.baseOffset = 0;
        this.isDragging = false;
        this.isVerticalScroll = false;

        this.handleResize();
        this.updateCarousel(false);

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
     * Configure les événements tactiles et souris
     */
    setupTouchEvents() {
        if (!this.hasTrackTarget) return;

        this.boundHandleTouchStart = this.handleTouchStart.bind(this);
        this.boundHandleTouchMove = this.handleTouchMove.bind(this);
        this.boundHandleTouchEnd = this.handleTouchEnd.bind(this);

        this.trackTarget.addEventListener('touchstart', this.boundHandleTouchStart, { passive: true });
        this.trackTarget.addEventListener('touchmove', this.boundHandleTouchMove, { passive: false });
        this.trackTarget.addEventListener('touchend', this.boundHandleTouchEnd, { passive: true });

        this.boundHandleMouseDown = this.handleMouseDown.bind(this);
        this.boundHandleMouseMove = this.handleMouseMove.bind(this);
        this.boundHandleMouseUp = this.handleMouseUp.bind(this);

        this.trackTarget.addEventListener('mousedown', this.boundHandleMouseDown);
    }

    handleMouseDown(event) {
        if (this.isTransitioning) return;
        event.preventDefault();

        this.touchStartX = event.clientX;
        this.isDragging = false;
        this.baseOffset = -(this.currentIndexValue * (this.cardWidth + this.gap));
        this.trackTarget.style.transition = 'none';
        this.trackTarget.style.cursor = 'grabbing';

        window.addEventListener('mousemove', this.boundHandleMouseMove);
        window.addEventListener('mouseup', this.boundHandleMouseUp);
    }

    handleMouseMove(event) {
        const deltaX = event.clientX - this.touchStartX;

        if (!this.isDragging && Math.abs(deltaX) > 5) {
            this.isDragging = true;
        }
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

        if (deltaX < -threshold) {
            this.next();
        } else if (deltaX > threshold) {
            this.prev();
        } else {
            this.updateCarousel(true);
        }
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

        // Mémoriser l'offset courant comme base du drag
        this.baseOffset = -(this.currentIndexValue * (this.cardWidth + this.gap));
        this.trackTarget.style.transition = 'none';
    }

    /**
     * Suit le doigt en temps réel
     */
    handleTouchMove(event) {
        const deltaX = event.changedTouches[0].clientX - this.touchStartX;
        const deltaY = Math.abs(event.changedTouches[0].clientY - this.touchStartY);

        // Détecter la direction dominante au premier mouvement significatif
        if (!this.isDragging && !this.isVerticalScroll) {
            if (deltaY > Math.abs(deltaX) && deltaY > 8) {
                this.isVerticalScroll = true;
                return;
            }
            if (Math.abs(deltaX) > 8) {
                this.isDragging = true;
            }
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

        if (this.autoplayValue) {
            this.stopAutoplay();
        }

        if (deltaX < -threshold) {
            this.next();
        } else if (deltaX > threshold) {
            this.prev();
        } else {
            // Snap back
            this.updateCarousel(true);
        }
    }

    /**
     * Gestion du responsive
     */
    handleResize() {
        const width = window.innerWidth;
        if (width < 768) {
            this.visibleCards = 1;
        } else if (width < 1024) {
            this.visibleCards = 2;
        } else {
            this.visibleCards = 3;
        }
        this.currentIndexValue = 0; // Reset à 0 lors du resize
        this.cardWidth = 0; // Forcer le recalcul
        this.updateCarousel(false);
    }

    /**
     * Démarre le défilement automatique
     */
    startAutoplay() {
        this.autoplayInterval = setInterval(() => {
            this.next();
        }, this.autoplayDelayValue);
    }

    /**
     * Arrête le défilement automatique
     */
    stopAutoplay() {
        if (this.autoplayInterval) {
            clearInterval(this.autoplayInterval);
        }
    }

    /**
     * Navigue vers l'avis précédent
     */
    prev() {
        if (this.isTransitioning) return;

        const maxIndex = this.totalCards - this.visibleCards;
        this.currentIndexValue = this.currentIndexValue > 0 ? this.currentIndexValue - 1 : maxIndex;
        this.updateCarousel(true);
    }

    /**
     * Navigue vers l'avis suivant
     */
    next() {
        if (this.isTransitioning) return;

        // Limite : on ne peut pas aller plus loin que le dernier groupe complet
        const maxIndex = this.totalCards - this.visibleCards;

        if (this.currentIndexValue < maxIndex) {
            this.currentIndexValue++;
            this.updateCarousel(true);
        } else {
            // Retour au début (effet carousel infini)
            this.currentIndexValue = 0;
            this.updateCarousel(true);
        }
    }

    /**
     * Navigue vers un avis spécifique
     */
    goTo(event) {
        if (this.isTransitioning) return;

        const targetIndex = parseInt(event.currentTarget.dataset.index);
        const maxIndex = this.totalCards - this.visibleCards;

        // S'assurer que l'index est dans les limites
        if (targetIndex >= 0 && targetIndex <= maxIndex) {
            this.currentIndexValue = targetIndex;
            this.updateCarousel(true);
        }
    }

    /**
     * Met à jour le carousel
     */
    updateCarousel(animate = true) {
        if (!this.hasTrackTarget || this.cardTargets.length === 0) return;

        // Calculer les dimensions si nécessaire
        if (!animate || this.cardWidth === 0) {
            const trackStyle = window.getComputedStyle(this.trackTarget);
            const trackPaddingLeft = parseFloat(trackStyle.paddingLeft) || 0;
            const trackPaddingRight = parseFloat(trackStyle.paddingRight) || 0;
            const containerWidth = this.element.offsetWidth - trackPaddingLeft - trackPaddingRight;
            this.gap = parseFloat(trackStyle.gap) || 24;
            this.cardWidth = (containerWidth - (this.gap * (this.visibleCards - 1))) / this.visibleCards;

            // Appliquer la largeur aux cartes
            this.cardTargets.forEach(card => {
                card.style.width = `${this.cardWidth}px`;
                card.style.minWidth = `${this.cardWidth}px`;
            });
        }

        // Calculer le décalage total
        const offset = -(this.currentIndexValue * (this.cardWidth + this.gap));

        if (animate) {
            this.isTransitioning = true;
            this.trackTarget.style.transition = 'transform 400ms cubic-bezier(0.25, 0.46, 0.45, 0.94)';

            setTimeout(() => {
                this.isTransitioning = false;
            }, 400);
        } else {
            this.trackTarget.style.transition = 'none';
        }

        this.trackTarget.style.transform = `translateX(${offset}px)`;

        // Mettre en avant la carte du milieu
        this.updateActiveCards();

        // Mettre à jour les indicateurs
        this.updateIndicators();
    }

    /**
     * Met en avant la carte centrale (scale + opacité)
     */
    updateActiveCards() {
        if (this.visibleCards !== 3) {
            this.cardTargets.forEach(card => {
                card.style.transform = '';
                card.style.opacity = '';
            });
            return;
        }

        const centerIndex = this.currentIndexValue + 1;

        this.cardTargets.forEach((card, index) => {
            card.style.transition = 'transform 400ms cubic-bezier(0.25, 0.46, 0.45, 0.94), opacity 400ms ease';
            if (index === centerIndex) {
                card.style.transform = 'scale(1)';
                card.style.opacity = '1';
            } else {
                card.style.transform = 'scale(0.93)';
                card.style.opacity = '0.5';
            }
        });
    }

    /**
     * Met à jour les indicateurs
     */
    updateIndicators() {
        if (!this.hasIndicatorTarget) return;

        const maxIndex = this.totalCards - this.visibleCards;

        this.indicatorTargets.forEach((indicator, index) => {
            if (index > maxIndex) {
                indicator.style.display = 'none';
                return;
            }
            indicator.style.display = '';

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
