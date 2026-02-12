import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['link'];

    connect() {
        this.sections = [];
        this.linkTargets.forEach(link => {
            const id = link.getAttribute('href').substring(1);
            const section = document.getElementById(id);
            if (section) {
                this.sections.push({ id, section, link });
            }
        });

        this.onScroll = this.onScroll.bind(this);
        window.addEventListener('scroll', this.onScroll, { passive: true });
        this.onScroll();
    }

    disconnect() {
        window.removeEventListener('scroll', this.onScroll);
    }

    onScroll() {
        const offset = 120;
        let activeId = null;

        for (let i = this.sections.length - 1; i >= 0; i--) {
            const { id, section } = this.sections[i];
            if (section.getBoundingClientRect().top <= offset) {
                activeId = id;
                break;
            }
        }

        if (!activeId && this.sections.length > 0) {
            activeId = this.sections[0].id;
        }

        this.activate(activeId);
    }

    activate(id) {
        this.sections.forEach(({ link }) => {
            link.classList.remove('text-primary', 'font-medium', 'border-primary');
            link.classList.add('text-neutral-600', 'font-light', 'border-transparent');
        });

        const active = this.sections.find(s => s.id === id);
        if (active) {
            active.link.classList.remove('text-neutral-600', 'font-light', 'border-transparent');
            active.link.classList.add('text-primary', 'font-medium', 'border-primary');
        }
    }
}