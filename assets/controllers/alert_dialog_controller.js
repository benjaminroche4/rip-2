/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    static targets = ['trigger', 'dialog'];

    connect() {
        this.onSubmitStart = this.onSubmitStart.bind(this);
        document.addEventListener('turbo:submit-start', this.onSubmitStart);
    }

    disconnect() {
        document.removeEventListener('turbo:submit-start', this.onSubmitStart);
    }

    /**
     * A dialog opened with showModal() lives in the top layer, above every
     * z-index: leaving it open while its own form is in flight hides the
     * page underneath, so any progress overlay (lead -> dossier conversion)
     * stays invisible and the modal looks stuck. Close it as soon as the
     * submission starts; the page below takes over the feedback.
     */
    onSubmitStart(event) {
        const form = event.target;
        if (form instanceof HTMLFormElement && this.element.contains(form) && this.dialogTarget.open) {
            this.close();
        }
    }

    async open() {
        this.dialogTarget.showModal();

        if (this.hasTriggerTarget) {
            if (this.dialogTarget.getAnimations().length > 0) {
                this.dialogTarget.addEventListener('transitionend', () => {
                    this.triggerTarget.setAttribute('aria-expanded', 'true');
                }, { once: true })
            } else {
                this.triggerTarget.setAttribute('aria-expanded', 'true');
            }
        }
    }

    async close() {
        this.dialogTarget.close();

        if (this.hasTriggerTarget) {
            if (this.dialogTarget.getAnimations().length > 0) {
                this.dialogTarget.addEventListener('transitionend', () => {
                    this.triggerTarget.setAttribute('aria-expanded', 'false');
                }, { once: true })
            } else {
                this.triggerTarget.setAttribute('aria-expanded', 'false');
            }
        }
    }
}
