/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Lightweight styled dropdown on top of a native <select>. Same panel chrome
 * as country_select (white bg, gray-300 border, rounded-lg, shadow-lg, item
 * rhythm px-3 py-2 text-sm, hover bg-neutral-100, check icon on the selected
 * option) — but no search input and no flags. Used when the option set is
 * short enough that filtering would be overkill.
 *
 * Markup contract (set by the Twig caller):
 *   <div data-controller="select">
 *     <select data-select-target="select" class="sr-only" ...>...</select>
 *     <button data-select-target="button" data-action="click->select#toggle">
 *       <span data-select-target="display">…placeholder…</span>
 *     </button>
 *     <div data-select-target="panel" hidden>
 *       <ul data-select-target="list"
 *           data-action="click->select#choose keydown->select#keydown"></ul>
 *     </div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['select', 'button', 'display', 'panel', 'list'];

    connect() {
        // Skip empty placeholder and any disabled separator entries (e.g. the
        // Symfony preferred_choices separator).
        this.options = Array.from(this.selectTarget.options)
            .filter((opt) => opt.value !== '' && !opt.disabled)
            .map((opt) => ({ value: opt.value, label: opt.textContent.trim() }));

        this.activeIndex = -1;
        this.#renderList();
        this.#syncDisplay();

        this.boundOutside = (event) => {
            if (!this.element.contains(event.target)) this.close();
        };
        document.addEventListener('click', this.boundOutside);
    }

    disconnect() {
        document.removeEventListener('click', this.boundOutside);
    }

    toggle(event) {
        event.preventDefault();
        this.panelTarget.hidden ? this.open() : this.close();
    }

    open() {
        this.panelTarget.hidden = false;
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        // Pre-select the current value so arrow keys feel anchored.
        const idx = this.options.findIndex((opt) => opt.value === this.selectTarget.value);
        this.activeIndex = idx >= 0 ? idx : 0;
        this.#renderList();
        requestAnimationFrame(() => this.listTarget.focus());
    }

    close() {
        this.panelTarget.hidden = true;
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        this.activeIndex = -1;
    }

    choose(event) {
        const item = event.target.closest('[data-select-value]');
        if (!item) return;
        this.#select(item.dataset.selectValue);
        this.close();
        this.buttonTarget.focus();
    }

    keydown(event) {
        const items = this.listTarget.querySelectorAll('[data-select-value]');
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                if (items.length === 0) return;
                this.activeIndex = (this.activeIndex + 1) % items.length;
                this.#highlight(items);
                break;
            case 'ArrowUp':
                event.preventDefault();
                if (items.length === 0) return;
                this.activeIndex = (this.activeIndex - 1 + items.length) % items.length;
                this.#highlight(items);
                break;
            case 'Home':
                event.preventDefault();
                if (items.length === 0) return;
                this.activeIndex = 0;
                this.#highlight(items);
                break;
            case 'End':
                event.preventDefault();
                if (items.length === 0) return;
                this.activeIndex = items.length - 1;
                this.#highlight(items);
                break;
            case 'Enter':
            case ' ':
                event.preventDefault();
                if (this.activeIndex < 0 || !items[this.activeIndex]) return;
                this.#select(items[this.activeIndex].dataset.selectValue);
                this.close();
                this.buttonTarget.focus();
                break;
            case 'Escape':
                event.preventDefault();
                this.close();
                this.buttonTarget.focus();
                break;
        }
    }

    #select(value) {
        this.selectTarget.value = value;
        this.selectTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.#syncDisplay();
    }

    #syncDisplay() {
        const selected = this.options.find((opt) => opt.value === this.selectTarget.value);
        if (selected) {
            this.displayTarget.textContent = selected.label;
            this.displayTarget.classList.remove('text-gray-400');
        }
    }

    #renderList() {
        const selectedValue = this.selectTarget.value;
        this.listTarget.innerHTML = this.options
            .map((opt, idx) => {
                const isSelected = opt.value === selectedValue;
                const isActive = idx === this.activeIndex;
                return `<li role="option"
                           data-select-value="${opt.value}"
                           aria-selected="${isSelected}"
                           class="cursor-pointer select-none flex items-center gap-2 px-3 py-2 text-sm text-gray-900 hover:bg-neutral-100 ${isActive ? 'bg-neutral-100' : ''} ${isSelected ? 'font-semibold' : ''}"><span class="flex-grow">${this.#escape(opt.label)}</span>${isSelected ? this.#checkIcon() : ''}</li>`;
            })
            .join('');
    }

    #highlight(items) {
        items.forEach((el, idx) => {
            el.classList.toggle('bg-neutral-100', idx === this.activeIndex);
            if (idx === this.activeIndex) el.scrollIntoView({ block: 'nearest' });
        });
    }

    #checkIcon() {
        return `<svg aria-hidden="true" class="size-4 shrink-0 text-primary" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 1 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>`;
    }

    #escape(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
}
