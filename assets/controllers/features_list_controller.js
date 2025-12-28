import { Controller } from '@hotwired/stimulus';

/**
 * Features List Controller
 *
 * Gère une liste de features avec :
 * - Défilement automatique toutes les 5 secondes
 * - Barre de progression verticale qui se remplit progressivement
 * - Activation au click (réinitialise le timer)
 * - Barres grises de fond pour toutes les features
 * - Barre bleue qui se remplit uniquement pour la feature active
 * - Support du redimensionnement de la fenêtre
 */
export default class extends Controller {
    static targets = ['item', 'indicator'];

    /**
     * Initialisation du controller
     */
    connect() {
        console.log('Features list controller connected');

        // Index de l'item actif
        this.activeIndex = 0;

        // Durée pour chaque feature (en ms)
        this.duration = 5000;

        // Timers
        this.autoplayTimer = null;
        this.progressTimer = null;

        // Réinitialiser toutes les barres
        this.resetAllIndicators();

        // Activer le premier item par défaut
        this.activateItemAtIndex(0);

        // Écouter le resize de la fenêtre
        this.boundHandleResize = this.handleResize.bind(this);
        window.addEventListener('resize', this.boundHandleResize);
    }

    /**
     * Nettoyage à la déconnexion
     */
    disconnect() {
        this.stopAutoplay();
        window.removeEventListener('resize', this.boundHandleResize);
    }

    /**
     * Réinitialise toutes les barres de progression à 0
     */
    resetAllIndicators() {
        this.indicatorTargets.forEach(indicator => {
            indicator.style.height = '0%';
        });
    }

    /**
     * Démarre l'autoplay
     */
    startAutoplay() {
        // Arrêter l'autoplay existant
        this.stopAutoplay();

        // Démarrer la progression de la barre
        this.animateProgressBar();

        // Timer pour passer au prochain item
        this.autoplayTimer = setTimeout(() => {
            this.goToNext();
        }, this.duration);
    }

    /**
     * Arrête l'autoplay
     */
    stopAutoplay() {
        if (this.autoplayTimer) {
            clearTimeout(this.autoplayTimer);
            this.autoplayTimer = null;
        }
        if (this.progressTimer) {
            cancelAnimationFrame(this.progressTimer);
            this.progressTimer = null;
        }
    }

    /**
     * Passe au prochain item
     */
    goToNext() {
        const nextIndex = (this.activeIndex + 1) % this.itemTargets.length;
        this.activateItemAtIndex(nextIndex);
    }

    /**
     * Gère le click sur un item
     * @param {Event} event - L'événement click
     */
    activate(event) {
        const item = event.currentTarget;
        const index = this.itemTargets.indexOf(item);

        if (index !== -1 && index !== this.activeIndex) {
            // Activer le nouvel item
            this.activateItemAtIndex(index);
        }
    }

    /**
     * Active un item à un index donné
     * @param {number} index - L'index de l'item à activer
     */
    activateItemAtIndex(index) {
        // Arrêter l'autoplay
        this.stopAutoplay();

        // Désactiver l'ancien item actif
        if (this.activeIndex !== null && this.itemTargets[this.activeIndex]) {
            this.deactivateItem(this.itemTargets[this.activeIndex], this.activeIndex);
        }

        // Activer le nouvel item
        const newActiveItem = this.itemTargets[index];
        if (newActiveItem) {
            this.activateItem(newActiveItem);
            this.activeIndex = index;

            // Redémarrer l'autoplay
            this.startAutoplay();
        }
    }

    /**
     * Active un item
     * @param {HTMLElement} item - L'item à activer
     */
    activateItem(item) {
        item.classList.add('bg-primary/10');
        item.setAttribute('aria-current', 'true');
    }

    /**
     * Désactive un item
     * @param {HTMLElement} item - L'item à désactiver
     * @param {number} index - L'index de l'item
     */
    deactivateItem(item, index) {
        item.classList.remove('bg-primary/10');
        item.removeAttribute('aria-current');

        // Réinitialiser la barre de progression de cet item
        if (this.indicatorTargets[index]) {
            this.indicatorTargets[index].style.height = '0%';
        }
    }

    /**
     * Anime la barre de progression
     */
    animateProgressBar() {
        if (!this.indicatorTargets[this.activeIndex]) {
            return;
        }

        const activeIndicator = this.indicatorTargets[this.activeIndex];

        // Temps de début de l'animation
        const startTime = Date.now();

        // Fonction d'animation
        const animate = () => {
            const elapsed = Date.now() - startTime;
            const progress = Math.min(elapsed / this.duration, 1);

            // Calculer la hauteur en pourcentage
            const heightPercent = progress * 100;

            // Appliquer la hauteur à l'indicator
            activeIndicator.style.height = `${heightPercent}%`;

            // Continuer l'animation si pas terminée
            if (progress < 1) {
                this.progressTimer = requestAnimationFrame(animate);
            }
        };

        // Démarrer l'animation
        animate();
    }

    /**
     * Gère le redimensionnement de la fenêtre
     */
    handleResize() {
        // La barre utilise des pourcentages, pas besoin de recalculer
        // Mais on peut forcer un redraw si nécessaire
    }
}
