/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'admin-sidebar-collapsed';

/**
 * Collapses the admin sidebar into an icon rail (labels hidden through
 * `data-collapsed` + group variants, width animated in CSS) and remembers
 * the choice across pages via localStorage. Attach to the <aside>; the
 * toggle button targets `sidebar-collapse#toggle`.
 *
 * Collapsed-only tooltips: items carrying data-sidebar-tooltip get a single
 * floating label appended to <body> (never clipped by the nav overflow) on
 * hover/focus, wired via mouseenter/mouseleave/focus/blur actions.
 */
export default class extends Controller {
    static targets = ['toggle'];

    #tip = null;
    #tipLabel = null;

    connect() {
        let collapsed = false;
        try {
            collapsed = '1' === localStorage.getItem(STORAGE_KEY);
        } catch {
            // Storage unavailable (private mode): default to expanded.
        }
        this.#apply(collapsed);
    }

    disconnect() {
        this.#tip?.remove();
        this.#tip = null;
    }

    toggle() {
        const collapsed = !this.element.hasAttribute('data-collapsed');
        this.#apply(collapsed);
        this.hideTip();
        try {
            localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
        } catch {
            // Not persisted, the session still toggles.
        }
    }

    showTip(event) {
        if (!this.element.hasAttribute('data-collapsed')) {
            return;
        }
        const label = event.currentTarget.dataset.sidebarTooltip;
        if (!label) {
            return;
        }

        this.#tip ??= this.#createTip();
        this.#tipLabel.textContent = label;
        const rect = event.currentTarget.getBoundingClientRect();
        this.#tip.style.transform = `translate3d(${Math.round(rect.right + window.scrollX + 10)}px, ${Math.round(rect.top + window.scrollY + rect.height / 2)}px, 0) translateY(-50%)`;
        this.#tip.style.opacity = '1';
    }

    hideTip() {
        if (this.#tip) {
            this.#tip.style.opacity = '0';
        }
    }

    #apply(collapsed) {
        this.element.toggleAttribute('data-collapsed', collapsed);
        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    }

    /**
     * Single portal node, styled inline: it lives outside any Twig template
     * and would otherwise be invisible to the Tailwind source scan. A small
     * rotated square on the left edge points back at the hovered icon.
     */
    #createTip() {
        const el = document.createElement('div');
        el.setAttribute('role', 'presentation');
        el.style.cssText = [
            'position: absolute',
            'top: 0',
            'left: 0',
            'z-index: 50',
            'pointer-events: none',
            'white-space: nowrap',
            'background: #1f2937',
            'color: #fff',
            'font-size: 12px',
            'font-weight: 500',
            'line-height: 1',
            'padding: 6px 10px',
            'border-radius: 6px',
            'box-shadow: 0 4px 12px rgb(0 0 0 / 0.15)',
            'opacity: 0',
            'transition: opacity 100ms ease-out',
        ].join(';');

        this.#tipLabel = document.createElement('span');
        el.appendChild(this.#tipLabel);

        const arrow = document.createElement('span');
        arrow.setAttribute('aria-hidden', 'true');
        arrow.style.cssText = [
            'position: absolute',
            'left: -3px',
            'top: 50%',
            'width: 8px',
            'height: 8px',
            'background: #1f2937',
            'border-radius: 2px',
            'transform: translateY(-50%) rotate(45deg)',
        ].join(';');
        el.appendChild(arrow);

        document.body.appendChild(el);

        return el;
    }
}
