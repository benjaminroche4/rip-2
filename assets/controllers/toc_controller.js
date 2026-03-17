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
            entries => {
                entries.forEach(entry => {
                    const match = this.sections.find(s => s.section === entry.target);
                    if (match) match.isVisible = entry.isIntersecting;
                });

                const visible = this.sections.filter(s => s.isVisible);
                const active = visible.length > 0 ? visible[0] : this.sections[0];
                if (active) this.activate(active.id);
            },
            { rootMargin: '-120px 0px -40% 0px', threshold: 0 }
        );

        this.sections.forEach(({ section }) => this.observer.observe(section));
    }

    disconnect() {
        if (this.observer) this.observer.disconnect();
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