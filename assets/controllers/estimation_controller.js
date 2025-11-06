import { Controller } from '@hotwired/stimulus';

/**
 * Estimation Controller
 *
 * Gère le calculateur d'estimation de loyer avec slider interactif,
 * services additionnels, sticky price et formatage en temps réel
 */
export default class extends Controller {
    static targets = ['slider', 'display', 'baseAmount', 'servicesAmount', 'service'];

    static values = {
        min: { type: Number, default: 800 },
        max: { type: Number, default: 10000 },
        step: { type: Number, default: 100 },
        initial: { type: Number, default: 800 }
    };

    /**
     * Initialisation du controller
     */
    connect() {
        console.log('Estimation controller connected');
        this.previousValue = null;
        this.updateDisplay();
        this.updateSliderBackground();
        this.setupStickyObserver();
    }

    /**
     * Nettoyage à la déconnexion
     */
    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
        }
    }

    /**
     * Appelé lors du changement de valeur du slider
     */
    update() {
        this.updateDisplay();
        this.updateSliderBackground();
    }

    /**
     * Appelé lors du toggle d'un service
     */
    toggleService(event) {
        const checkbox = event.currentTarget.querySelector('input[type="checkbox"]');
        if (checkbox) {
            checkbox.checked = !checkbox.checked;
        }
        this.updateDisplay();
    }

    /**
     * Met à jour l'affichage du montant total formaté
     */
    updateDisplay() {
        const baseValue = parseInt(this.sliderTarget.value, 10);
        const servicesTotal = this.calculateServicesTotal();
        const totalValue = baseValue + servicesTotal;
        const isMaxValue = baseValue >= this.maxValue;

        // Mise à jour du montant principal avec "+" si au maximum
        const formattedTotal = this.formatCurrency(totalValue);
        const displayText = isMaxValue ? `+${formattedTotal}` : formattedTotal;

        // Ajoute l'animation pulse si la valeur a changé
        if (this.previousValue !== null && this.previousValue !== totalValue) {
            this.animatePriceUpdate();
        }

        // Mise à jour de tous les displays
        this.displayTargets.forEach(target => {
            target.textContent = displayText;
        });

        // Mise à jour des détails si les targets existent
        if (this.hasBaseAmountTarget) {
            const formattedBase = this.formatCurrency(baseValue);
            this.baseAmountTarget.textContent = isMaxValue ? `+${formattedBase}` : formattedBase;
        }

        if (this.hasServicesAmountTarget) {
            this.servicesAmountTarget.textContent = this.formatCurrency(servicesTotal);
        }

        this.previousValue = totalValue;
    }

    /**
     * Anime les changements de prix
     */
    animatePriceUpdate() {
        this.displayTargets.forEach(target => {
            target.classList.remove('price-update');
            // Force reflow
            void target.offsetWidth;
            target.classList.add('price-update');

            // Retire la classe après l'animation
            setTimeout(() => {
                target.classList.remove('price-update');
            }, 300);
        });
    }

    /**
     * Calcule le total des services sélectionnés
     * @returns {number} - Somme des prix des services cochés
     */
    calculateServicesTotal() {
        if (!this.hasServiceTarget) return 0;

        return this.serviceTargets.reduce((total, serviceElement) => {
            const checkbox = serviceElement.querySelector('input[type="checkbox"]');
            if (checkbox && checkbox.checked) {
                const price = parseInt(checkbox.dataset.price, 10) || 0;
                return total + price;
            }
            return total;
        }, 0);
    }

    /**
     * Met à jour le gradient de fond du slider
     * Dégradé progressif du bordeaux clair vers le bordeaux foncé
     */
    updateSliderBackground() {
        const value = parseInt(this.sliderTarget.value, 10);
        const percentage = ((value - this.minValue) / (this.maxValue - this.minValue)) * 100;

        // Gradient du bordeaux clair (#d1a3b0) vers le bordeaux foncé (#71172e)
        this.sliderTarget.style.background = `linear-gradient(to right, #d1a3b0 0%, #a85572 ${percentage * 0.5}%, #71172e ${percentage}%, #e5e7eb ${percentage}%, #e5e7eb 100%)`;
    }

    /**
     * Formate un nombre en devise (EUR)
     *
     * @param {number} value - La valeur à formater
     * @returns {string} - La valeur formatée en euros
     */
    formatCurrency(value) {
        return new Intl.NumberFormat('fr-FR', {
            style: 'currency',
            currency: 'EUR',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(value);
    }
}
