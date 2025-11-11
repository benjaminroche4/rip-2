import { Controller } from '@hotwired/stimulus';

/**
 * Vertical Timeline Controller
 *
 * Gère la timeline verticale avec :
 * - Barre de progression qui se remplit au scroll
 * - Activation des étapes quand elles entrent dans le viewport
 * - Animations fluides des dots, numéros et textes
 * - Support ARIA pour l'accessibilité
 */
export default class extends Controller {
    static targets = ['step', 'dot', 'dotInner', 'stepNumber', 'progressBar', 'rail'];

    static values = {
        threshold: { type: Number, default: 0.5 }
    };

    /**
     * Initialisation du controller
     */
    connect() {
        console.log('Vertical timeline controller connected');
        this.activeSteps = new Set();
        this.setupIntersectionObserver();
        this.setupScrollListener();
    }

    /**
     * Nettoyage à la déconnexion
     */
    disconnect() {
        if (this.stepObserver) {
            this.stepObserver.disconnect();
        }
        if (this.railObserver) {
            this.railObserver.disconnect();
        }
        window.removeEventListener('scroll', this.boundUpdateProgressBar);
    }

    /**
     * Configure l'Intersection Observer pour les étapes
     */
    setupIntersectionObserver() {
        const options = {
            root: null,
            rootMargin: '0px',
            threshold: this.thresholdValue
        };

        this.stepObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                const stepIndex = this.stepTargets.indexOf(entry.target);

                if (entry.isIntersecting) {
                    // Activer l'étape
                    this.activateStep(entry.target, stepIndex);
                    this.activeSteps.add(stepIndex);
                } else {
                    // Désactiver l'étape quand elle sort du viewport
                    this.deactivateStep(entry.target, stepIndex);
                    this.activeSteps.delete(stepIndex);
                }

                this.updateProgressBar();
            });
        }, options);

        // Observer chaque étape
        this.stepTargets.forEach(step => {
            this.stepObserver.observe(step);
        });
    }

    /**
     * Configure le listener pour le scroll (pour une progression plus fluide)
     */
    setupScrollListener() {
        this.boundUpdateProgressBar = this.updateProgressBar.bind(this);
        window.addEventListener('scroll', this.boundUpdateProgressBar, { passive: true });
    }

    /**
     * Active une étape
     * @param {HTMLElement} step - L'élément étape
     * @param {number} index - L'indice de l'étape
     */
    activateStep(step, index) {
        // Trouver les éléments associés à cette étape
        const dot = this.dotTargets[index];
        const dotInner = this.dotInnerTargets[index];
        const stepNumber = this.stepNumberTargets[index];
        const content = step.querySelector('[class*="transition-opacity"]');

        // Animer le dot
        if (dot) {
            dot.style.borderColor = 'rgb(113, 23, 46)'; // primary color
            dot.style.transform = 'scale(1.2)';
        }

        // Animer le dot intérieur
        if (dotInner) {
            dotInner.style.backgroundColor = 'rgb(113, 23, 46)'; // primary color
            dotInner.style.transform = 'scale(1.3)';
        }

        // Animer le numéro
        if (stepNumber) {
            stepNumber.style.color = 'rgb(113, 23, 46)'; // primary color
            stepNumber.style.backgroundColor = 'rgb(254, 242, 242)'; // red-50 / primary light
        }

        // Animer le contenu
        if (content) {
            content.style.opacity = '1';
        }

        // Gérer les expandable contents dans ce step
        const expandables = step.querySelectorAll('[data-vertical-timeline-target="expandableContent"]');
        expandables.forEach(expandable => {
            expandable.style.maxHeight = expandable.scrollHeight + 'px';
            expandable.style.opacity = '1';
        });

        // Ajouter l'attribut ARIA
        step.setAttribute('aria-current', 'step');
    }

    /**
     * Désactive une étape
     * @param {HTMLElement} step - L'élément étape
     * @param {number} index - L'indice de l'étape
     */
    deactivateStep(step, index) {
        const dot = this.dotTargets[index];
        const dotInner = this.dotInnerTargets[index];
        const stepNumber = this.stepNumberTargets[index];
        const content = step.querySelector('[class*="transition-opacity"]');

        // Réinitialiser le dot
        if (dot) {
            dot.style.borderColor = 'rgb(209, 213, 219)'; // gray-300
            dot.style.transform = 'scale(1)';
        }

        // Réinitialiser le dot intérieur
        if (dotInner) {
            dotInner.style.backgroundColor = 'rgb(209, 213, 219)'; // gray-300
            dotInner.style.transform = 'scale(1)';
        }

        // Réinitialiser le numéro
        if (stepNumber) {
            stepNumber.style.color = 'rgba(113, 23, 46, 0.4)'; // primary/40
            stepNumber.style.backgroundColor = 'rgb(249, 250, 251)'; // gray-50
        }

        // Réinitialiser le contenu
        if (content) {
            content.style.opacity = '0.4';
        }

        // Gérer les expandable contents dans ce step
        const expandables = step.querySelectorAll('[data-vertical-timeline-target="expandableContent"]');
        expandables.forEach(expandable => {
            expandable.style.maxHeight = '0';
            expandable.style.opacity = '0';
        });

        // Retirer l'attribut ARIA
        step.removeAttribute('aria-current');
    }

    /**
     * Met à jour la barre de progression
     */
    updateProgressBar() {
        if (!this.hasProgressBarTarget || !this.hasRailTarget) return;

        // Calculer la position basée sur l'étape la plus haute actuellement active
        let progress = 0;

        if (this.activeSteps.size > 0) {
            const totalSteps = this.stepTargets.length;

            // Trouver l'indice de l'étape active la plus haute
            const highestActiveIndex = Math.max(...Array.from(this.activeSteps));

            // Calculer le pourcentage de progression
            // On ajoute 1 car l'indice commence à 0
            progress = ((highestActiveIndex + 1) / totalSteps) * 100;

            // Limiter à 100%
            progress = Math.min(100, progress);
        }

        // Animer la barre de progression
        this.progressBarTarget.style.height = `${progress}%`;

        // Mettre à jour l'attribut ARIA
        this.progressBarTarget.setAttribute('aria-valuenow', Math.round(progress));
        this.progressBarTarget.setAttribute('aria-valuemax', 100);
    }
}
