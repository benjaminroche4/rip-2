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

        this.observer = new IntersectionObserver(
            (entries) => this.onIntersect(entries),
            { rootMargin: '-80px 0px -40% 0px', threshold: 0 }
        );

        this.sections.forEach(({ section }) => this.observer.observe(section));
    }

    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
        }
    }

    onIntersect(entries) {
        entries.forEach(entry => {
            const item = this.sections.find(s => s.section === entry.target);
            if (!item) return;

            if (entry.isIntersecting) {
                this.activate(item.id);
            }
        });
    }

    activate(id) {
        this.sections.forEach(({ link }) => {
            link.classList.remove('text-primary', 'font-medium');
            link.classList.add('text-neutral-600', 'font-light');
        });

        const active = this.sections.find(s => s.id === id);
        if (active) {
            active.link.classList.remove('text-neutral-600', 'font-light');
            active.link.classList.add('text-primary', 'font-medium');
        }
    }
}