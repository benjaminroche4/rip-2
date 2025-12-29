import { Controller } from '@hotwired/stimulus';

/**
 * Controller Stimulus pour les boutons incrémentation/décrémentation
 *
 * Permet d'augmenter ou diminuer la valeur d'un champ numérique
 * avec des boutons + et -
 * Supporte le maintien du bouton pour incrémenter/décrémenter en continu
 */
export default class extends Controller {
    static targets = ['input'];

    static values = {
        min: { type: Number, default: 0 },
        max: { type: Number, default: 100 },
        step: { type: Number, default: 1 }
    };

    connect() {
        console.log('Number stepper controller connected');

        // Variables pour le hold
        this.holdTimer = null;
        this.holdInterval = null;

        // S'assurer que la valeur initiale est valide
        this.ensureValidValue();
    }

    disconnect() {
        this.stopHold();
    }

    /**
     * Incrémente la valeur
     */
    increment() {
        const currentValue = parseInt(this.inputTarget.value) || this.minValue;
        const newValue = currentValue + this.stepValue;

        if (newValue <= this.maxValue) {
            this.inputTarget.value = newValue;
            this.triggerChangeEvent();
        }
    }

    /**
     * Décrémente la valeur
     */
    decrement() {
        const currentValue = parseInt(this.inputTarget.value) || this.minValue;
        const newValue = currentValue - this.stepValue;

        if (newValue >= this.minValue) {
            this.inputTarget.value = newValue;
            this.triggerChangeEvent();
        }
    }

    /**
     * Commence à incrémenter en continu au maintien du bouton
     */
    startIncrementHold(event) {
        // Premier incrémentation immédiat
        this.increment();

        // Attendre 300ms avant de commencer la répétition
        this.holdTimer = setTimeout(() => {
            this.holdInterval = setInterval(() => {
                this.increment();
            }, 100); // Répéter toutes les 100ms
        }, 300);

        // Empêcher la sélection de texte
        event.preventDefault();
    }

    /**
     * Commence à décrémenter en continu au maintien du bouton
     */
    startDecrementHold(event) {
        // Premier décrémentation immédiat
        this.decrement();

        // Attendre 300ms avant de commencer la répétition
        this.holdTimer = setTimeout(() => {
            this.holdInterval = setInterval(() => {
                this.decrement();
            }, 100); // Répéter toutes les 100ms
        }, 300);

        // Empêcher la sélection de texte
        event.preventDefault();
    }

    /**
     * Arrête l'incrémentation/décrémentation continue
     */
    stopHold() {
        if (this.holdTimer) {
            clearTimeout(this.holdTimer);
            this.holdTimer = null;
        }
        if (this.holdInterval) {
            clearInterval(this.holdInterval);
            this.holdInterval = null;
        }
    }

    /**
     * Assure que la valeur est dans les limites min/max
     */
    ensureValidValue() {
        let value = parseInt(this.inputTarget.value) || this.minValue;

        if (value < this.minValue) {
            value = this.minValue;
        } else if (value > this.maxValue) {
            value = this.maxValue;
        }

        this.inputTarget.value = value;
    }

    /**
     * Déclenche un événement change sur l'input
     * pour que les autres scripts puissent réagir
     */
    triggerChangeEvent() {
        const event = new Event('change', { bubbles: true });
        this.inputTarget.dispatchEvent(event);
    }
}
