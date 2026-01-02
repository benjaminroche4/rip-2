import { Controller } from '@hotwired/stimulus';

/**
 * Controller Stimulus pour les tabs de tarification
 *
 * Permet de basculer entre court terme et long terme
 * et met à jour le pourcentage affiché
 */
export default class extends Controller {
    static targets = ['percentage', 'tab', 'description'];

    static values = {
        shortTermRate: { type: Number, default: 20 },
        longTermRate: { type: Number, default: 15 },
        shortTermText: { type: String, default: 'sur les revenus générés' },
        longTermText: { type: String, default: 'sur le chiffre d\'affaires généré' },
        activeTab: { type: String, default: 'shortTerm' }
    };

    connect() {
        console.log('Pricing tabs controller connected');
        this.updateDisplay();
    }

    /**
     * Change l'onglet actif
     */
    switchTab(event) {
        const tab = event.currentTarget.dataset.tab;
        this.activeTabValue = tab;
        this.updateDisplay();
    }

    /**
     * Met à jour l'affichage du pourcentage et des tabs
     */
    updateDisplay() {
        // Mettre à jour le pourcentage
        const percentage = this.activeTabValue === 'shortTerm'
            ? this.shortTermRateValue
            : this.longTermRateValue;

        this.percentageTarget.textContent = percentage;

        // Mettre à jour le texte de description
        const description = this.activeTabValue === 'shortTerm'
            ? this.shortTermTextValue
            : this.longTermTextValue;

        this.descriptionTarget.textContent = description;

        // Mettre à jour les styles des tabs
        this.tabTargets.forEach(tab => {
            const tabType = tab.dataset.tab;
            const isActive = tabType === this.activeTabValue;

            if (isActive) {
                tab.classList.add('bg-primary', 'text-white');
                tab.classList.remove('bg-white', 'text-gray-700', 'hover:bg-gray-50');
            } else {
                tab.classList.remove('bg-primary', 'text-white');
                tab.classList.add('bg-white', 'text-gray-700', 'hover:bg-gray-50');
            }
        });
    }
}
