/* stimulusFetch: 'lazy' */
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

            if (this.activeId !== id) {
                this.activeId = id;
                this.revealInList(active.link);
            }
        }
    }

    // Scrolls the nav itself (never the page) so the active link stays visible when the list overflows.
    revealInList(link) {
        const nav = this.element;
        if (nav.scrollHeight <= nav.clientHeight) return;

        const linkTop = link.offsetTop - nav.offsetTop;
        nav.scrollTo({
            top: linkTop - (nav.clientHeight - link.offsetHeight) / 2,
            behavior: 'smooth',
        });
    }
}