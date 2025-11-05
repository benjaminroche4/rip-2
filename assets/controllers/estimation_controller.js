import { Controller } from '@hotwired/stimulus';

/**
 * Estimation Controller
 *
 * Gère le calculateur d'estimation de loyer avec slider interactif,
 * services additionnels et formatage en temps réel du montant en euros
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
        this.updateDisplay();
        this.updateSliderBackground();
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

        // Mise à jour du montant principal
        this.displayTarget.textContent = this.formatCurrency(totalValue);

        // Mise à jour des détails si les targets existent
        if (this.hasBaseAmountTarget) {
            this.baseAmountTarget.textContent = this.formatCurrency(baseValue);
        }

        if (this.hasServicesAmountTarget) {
            this.servicesAmountTarget.textContent = this.formatCurrency(servicesTotal);
        }
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
     */
    updateSliderBackground() {
        const value = parseInt(this.sliderTarget.value, 10);
        const percentage = ((value - this.minValue) / (this.maxValue - this.minValue)) * 100;

        this.sliderTarget.style.background = `linear-gradient(to right, #71172e 0%, #71172e ${percentage}%, #e5e7eb ${percentage}%, #e5e7eb 100%)`;
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
