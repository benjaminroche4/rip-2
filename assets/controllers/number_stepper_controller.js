import { Controller } from '@hotwired/stimulus';

/**
 * Controller Stimulus pour les boutons incrémentation/décrémentation
 *
 * Permet d'augmenter ou diminuer la valeur d'un champ numérique
 * avec des boutons + et -
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

        // S'assurer que la valeur initiale est valide
        this.ensureValidValue();
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
