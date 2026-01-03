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

        this.handleResize();
        this.updateCarousel(false);

        if (this.autoplayValue) {
            this.startAutoplay();
        }

        window.addEventListener('resize', () => this.handleResize());
    }

    disconnect() {
        this.stopAutoplay();
        window.removeEventListener('resize', () => this.handleResize());
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

        if (this.currentIndexValue > 0) {
            this.currentIndexValue--;
            this.updateCarousel(true);
        }
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
            const containerWidth = this.trackTarget.parentElement.offsetWidth;
            this.gap = parseFloat(window.getComputedStyle(this.trackTarget).gap) || 24;
            this.cardWidth = (containerWidth - (this.gap * (this.visibleCards - 1))) / this.visibleCards;

            // Appliquer la largeur aux cartes
            this.cardTargets.forEach(card => {
                card.style.width = `${this.cardWidth}px`;
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

        // Mettre à jour les indicateurs
        this.updateIndicators();
    }

    /**
     * Met à jour les indicateurs
     */
    updateIndicators() {
        if (!this.hasIndicatorTarget) return;

        this.indicatorTargets.forEach((indicator, index) => {
            if (index === this.currentIndexValue) {
                indicator.classList.remove('bg-gray-300');
                indicator.classList.add('bg-primary');
            } else {
                indicator.classList.remove('bg-primary');
                indicator.classList.add('bg-gray-300');
            }
        });
    }
}
