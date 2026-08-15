/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Morph-proof tabs for sections backed by LiveComponents: the active key
 * lives in [data-active-tab] on this static wrapper and panel visibility is
 * derived from it in CSS (see app.css), so live re-renders inside a panel
 * can never un-hide a masked one. The tab buttons are static page markup.
 */
export default class extends Controller {
    static targets = ['tab', 'indicator'];
    static classes = ['active', 'inactive'];
    /** Query parameter mirroring the active tab, so a copied URL reopens it. */
    static values = { param: String };

    connect() {
        this.apply(this.keyFromUrl() || this.element.dataset.activeTab || this.tabTargets[0]?.dataset.tabsKey);
        // Position the sliding pill once the layout is settled, and follow
        // width changes (viewport resize, fonts).
        this.frame = requestAnimationFrame(() => this.moveIndicatorToActive(false));
        this.onResize = () => this.moveIndicatorToActive(false);
        window.addEventListener('resize', this.onResize);
        // A morph refresh can change [data-active-tab] server-side (e.g.
        // jump to the next step after a validation): follow with the pill.
        this.observer = new MutationObserver(() => this.moveIndicatorToActive(false));
        this.observer.observe(this.element, { attributes: true, attributeFilter: ['data-active-tab'] });
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
        window.removeEventListener('resize', this.onResize);
        this.observer?.disconnect();
    }

    /**
     * Step validated: land on the next step's tab. At this point the next
     * tab is still rendered locked (the unlock happens server-side), so we
     * only mirror the key in the URL; the morph refresh triggered by
     * dossier-progress:changed re-renders the bar and the panel with that
     * tab active.
     */
    goTo(event) {
        const key = event.detail?.next;
        if (!key) {
            return;
        }
        this.writeUrl(key);
    }

    select(event) {
        this.apply(event.currentTarget.dataset.tabsKey);
        this.moveIndicator(event.currentTarget);
        this.writeUrl(event.currentTarget.dataset.tabsKey);
    }

    /** Tab asked for by the URL, ignored when it names no existing tab. */
    keyFromUrl() {
        if (!this.hasParamValue || '' === this.paramValue) {
            return null;
        }
        const key = new URL(window.location.href).searchParams.get(this.paramValue);

        return this.tabTargets.some((tab) => tab.dataset.tabsKey === key) ? key : null;
    }

    /**
     * The tab is view state, not navigation: replaceState keeps the URL
     * copyable without stacking a history entry per click (back leaves the
     * page, as expected).
     */
    writeUrl(key) {
        if (!this.hasParamValue || '' === this.paramValue || !key) {
            return;
        }
        const url = new URL(window.location.href);
        if (key === this.tabTargets[0]?.dataset.tabsKey) {
            url.searchParams.delete(this.paramValue);
        } else {
            url.searchParams.set(this.paramValue, key);
        }
        window.history.replaceState(window.history.state, '', url);
    }

    /** The pill follows the hovered tab... */
    hoverIndicator(event) {
        this.moveIndicator(event.currentTarget);
    }

    /** ...and slides back to the active one when the pointer leaves the bar. */
    resetIndicator() {
        this.moveIndicatorToActive();
    }

    moveIndicatorToActive(animate = true) {
        const active = this.tabTargets.find((tab) => tab.dataset.tabsKey === this.element.dataset.activeTab);
        if (active) {
            this.moveIndicator(active, animate);
        }
    }

    moveIndicator(button, animate = true) {
        if (!this.hasIndicatorTarget) {
            return;
        }
        const pill = this.indicatorTarget;
        const instant = !animate || pill.hidden;
        pill.hidden = false;
        if (instant) {
            pill.style.transitionDuration = '0s';
        }
        // Inset by a few pixels so the pill never touches the separators
        // between tabs nor the card border.
        const inset = 3;
        pill.style.width = `${button.offsetWidth - 2 * inset}px`;
        pill.style.height = `${button.offsetHeight - 2 * inset}px`;
        pill.style.transform = `translate(${button.offsetLeft + inset}px, ${button.offsetTop + inset}px)`;
        if (instant) {
            void pill.offsetWidth; // flush before restoring the transition
            pill.style.transitionDuration = '';
        }
    }

    apply(key) {
        if (!key) {
            return;
        }
        this.element.dataset.activeTab = key;
        for (const tab of this.tabTargets) {
            const active = tab.dataset.tabsKey === key;
            // Per-button classes take precedence so differently styled bars
            // (pills, underline, segmented...) can share one controller.
            const activeClasses = this.classesFor(tab, 'tabsActiveClass', this.hasActiveClass ? this.activeClasses : []);
            const inactiveClasses = this.classesFor(tab, 'tabsInactiveClass', this.hasInactiveClass ? this.inactiveClasses : []);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.classList.remove(...(active ? inactiveClasses : activeClasses));
            tab.classList.add(...(active ? activeClasses : inactiveClasses));
        }
    }

    classesFor(tab, datasetKey, fallback) {
        const own = tab.dataset[datasetKey];

        return own ? own.split(' ').filter(Boolean) : fallback;
    }
}
