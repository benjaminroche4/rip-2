/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Searchable country dropdown built on top of a Symfony CountryType <select>.
 * Keeps the native <select> in the DOM (hidden) so form submission and
 * server-side validation work unchanged; the visible UI is a combobox that
 * filters the option list as the user types and supports full keyboard nav
 * (Up/Down, Enter, Escape, Home/End).
 *
 * Markup contract (set by the Twig template):
 *   <div data-controller="country-select">
 *     <select data-country-select-target="select" class="sr-only" ...>...</select>
 *     <button data-country-select-target="button" data-action="click->country-select#toggle">
 *       <span data-country-select-target="display">…placeholder…</span>
 *     </button>
 *     <div data-country-select-target="panel" hidden>
 *       <input data-country-select-target="search"
 *              data-action="input->country-select#filter
 *                           keydown->country-select#keydown" />
 *       <ul data-country-select-target="list"
 *           data-action="click->country-select#choose"></ul>
 *     </div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['select', 'button', 'display', 'panel', 'search', 'list'];
    static values = {
        searchPlaceholder: { type: String, default: 'Rechercher…' },
        emptyLabel: { type: String, default: 'Aucun résultat' },
    };

    connect() {
        // Skip the empty placeholder and the Symfony separator (rendered as
        // <option disabled>------</option> between preferred_choices and the
        // rest of the list — exposing it would show a broken flag SVG and a
        // row of dashes inside the dropdown).
        this.options = Array.from(this.selectTarget.options)
            .filter((opt) => opt.value !== '' && !opt.disabled)
            .map((opt) => ({
                value: opt.value,
                label: opt.textContent.trim(),
                flag: this.#flagFor(opt.value),
            }));

        this.activeIndex = -1;
        this.#renderList(this.options);
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
        this.searchTarget.value = '';
        this.#renderList(this.options);
        // Defer focus so the click that opened us doesn't immediately re-close.
        requestAnimationFrame(() => this.searchTarget.focus());
    }

    close() {
        this.panelTarget.hidden = true;
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        this.activeIndex = -1;
    }

    filter() {
        const query = this.searchTarget.value.trim().toLowerCase();
        const filtered = query === ''
            ? this.options
            : this.options.filter((opt) => opt.label.toLowerCase().includes(query));
        this.activeIndex = filtered.length > 0 ? 0 : -1;
        this.#renderList(filtered);
    }

    choose(event) {
        const item = event.target.closest('[data-country-value]');
        if (!item) return;
        this.#select(item.dataset.countryValue);
        this.close();
        this.buttonTarget.focus();
    }

    keydown(event) {
        const items = this.listTarget.querySelectorAll('[data-country-value]');
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
                event.preventDefault();
                if (this.activeIndex < 0 || !items[this.activeIndex]) return;
                this.#select(items[this.activeIndex].dataset.countryValue);
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
            this.displayTarget.innerHTML = `${this.#flagImg(selected, 'mr-2 size-5 shrink-0')}<span>${this.#escape(selected.label)}</span>`;
            this.displayTarget.classList.remove('text-gray-400');
            this.displayTarget.classList.add('flex', 'items-center');
        }
    }

    #renderList(items) {
        if (items.length === 0) {
            this.listTarget.innerHTML = `<li class="px-3 py-2 text-sm text-gray-500">${this.emptyLabelValue}</li>`;
            return;
        }

        const selectedValue = this.selectTarget.value;
        this.listTarget.innerHTML = items
            .map((opt, idx) => {
                const isSelected = opt.value === selectedValue;
                const isActive = idx === this.activeIndex;
                return `<li role="option"
                           data-country-value="${opt.value}"
                           aria-selected="${isSelected}"
                           class="cursor-pointer select-none flex items-center gap-2 px-3 py-2 text-sm text-gray-900 hover:bg-neutral-100 ${isActive ? 'bg-neutral-100' : ''} ${isSelected ? 'font-semibold' : ''}">${this.#flagImg(opt, 'size-5 shrink-0')}<span class="flex-grow">${this.#escape(opt.label)}</span>${isSelected ? this.#checkIcon() : ''}</li>`;
            })
            .join('');
    }

    #checkIcon() {
        return `<svg aria-hidden="true" class="size-4 shrink-0 text-primary" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 1 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>`;
    }

    /**
     * Build the <img> tag for the country flag. SVG comes from the local
     * circle-flags collection under /flags/circle/. `loading="lazy"` keeps the
     * initial dropdown cheap — only the visible flags are fetched.
     */
    #flagImg(opt, classes) {
        return `<img src="${opt.flag}" alt="" loading="lazy" decoding="async" class="${classes} rounded-full" width="20" height="20" />`;
    }

    #flagFor(iso) {
        if (typeof iso !== 'string' || iso.length !== 2) return '';
        return `/flags/circle/${iso.toLowerCase()}.svg`;
    }

    #escape(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    #highlight(items) {
        items.forEach((el, idx) => {
            el.classList.toggle('bg-neutral-100', idx === this.activeIndex);
            if (idx === this.activeIndex) el.scrollIntoView({ block: 'nearest' });
        });
    }
}
