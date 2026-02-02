import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        popoverId: String
    };

    connect() {
        this.popover = document.getElementById(this.popoverIdValue);
        this.boundHandleToggle = this.handleToggle.bind(this);

        if (this.popover) {
            this.popover.addEventListener('toggle', this.boundHandleToggle);
        }
    }

    disconnect() {
        if (this.popover) {
            this.popover.removeEventListener('toggle', this.boundHandleToggle);
        }
    }

    handleToggle(e) {
        const svg = this.element.querySelector('svg');
        if (!svg) return;

        if (e.newState === 'open') {
            svg.style.transform = 'rotate(180deg)';
        } else {
            svg.style.transform = 'rotate(0deg)';
        }
    }
}
