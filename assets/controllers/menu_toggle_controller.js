import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['content', 'icon', 'button'];

    connect() {
        const isMobile = window.innerWidth < 768;

        this.contentTarget.addEventListener('transitionend', () => {
            if (this.open) {
                this.contentTarget.style.maxHeight = 'none';
            }
        });

        if (isMobile) {
            this.open = false;
            this.contentTarget.style.maxHeight = '0';
            this.contentTarget.style.opacity = '0';
            this.buttonTarget.setAttribute('aria-expanded', 'false');
        } else {
            this.open = true;
            this.iconTarget.classList.add('rotate-180');
        }
    }

    toggle() {
        this.open = !this.open;
        this.buttonTarget.setAttribute('aria-expanded', this.open);

        if (this.open) {
            this.contentTarget.style.maxHeight = this.contentTarget.scrollHeight + 'px';
            this.contentTarget.style.opacity = '1';
            this.iconTarget.classList.add('rotate-180');
        } else {
            this.contentTarget.style.maxHeight = this.contentTarget.scrollHeight + 'px';
            requestAnimationFrame(() => {
                this.contentTarget.style.maxHeight = '0';
                this.contentTarget.style.opacity = '0';
            });
            this.iconTarget.classList.remove('rotate-180');
        }
    }
}
