/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        delayDuration: Number,
        // Using targets does not work if the elements are moved in the DOM (document.body.appendChild)
        // and using outlets does not work either if elements are children of the controller element.
        wrapperSelector: String,
        contentSelector: String,
        arrowSelector: String,
    };
    static targets = ['trigger', 'wrapper'];

    connect() {
        this.initialized = false;
        this.wrapperElement = document.querySelector(this.wrapperSelectorValue);
        this.contentElement = document.querySelector(this.contentSelectorValue);
        this.arrowElement = document.querySelector(this.arrowSelectorValue);

        if (!this.wrapperElement || !this.contentElement || !this.arrowElement) {
            return;
        }

        this.side = this.wrapperElement.getAttribute('data-side') || 'top';
        this.sideOffset = parseInt(this.wrapperElement.getAttribute('data-side-offset'), 10) || 0;

        this.showTimeout = null;
        this.hideTimeout = null;

        document.body.appendChild(this.wrapperElement);
        this.initialized = true;
    }

    disconnect() {
        this.#clearTimeouts();

        if (this.wrapperElement && this.wrapperElement.parentNode === document.body) {
            this.element.appendChild(this.wrapperElement);
        }
    }

    wrapperTargetConnected() {
        // This case appear when live component rerender.
        // Because original wrapper is moved on body, the Smart rerender algorithm recreate a new wrapper.
        if (this.wrapperElement) {
            this.wrapperElement.remove();
            this.connect();
        }
    }

    show() {
        if (!this.initialized) {
            return;
        }

        this.#clearTimeouts();

        const delay = this.hasDelayDurationValue ? this.delayDurationValue : 0;

        this.showTimeout = setTimeout(() => {
            this.wrapperElement.setAttribute('open', '');
            this.contentElement.setAttribute('open', '');
            this.arrowElement.setAttribute('open', '');
            this.#positionElements();
            this.showTimeout = null;
        }, delay);
    }

    hide() {
        if (!this.initialized) {
            return;
        }

        this.#clearTimeouts();
        this.wrapperElement.removeAttribute('open');
        this.contentElement.removeAttribute('open');
        this.arrowElement.removeAttribute('open');
    }

    #clearTimeouts() {
        if (this.showTimeout) {
            clearTimeout(this.showTimeout);
            this.showTimeout = null;
        }
        if (this.hideTimeout) {
            clearTimeout(this.hideTimeout);
            this.hideTimeout = null;
        }
    }

    #positionElements() {
        const triggerRect = this.triggerTarget.getBoundingClientRect();
        // offsetWidth/offsetHeight: the untransformed layout box. Measuring
        // with getBoundingClientRect during the opening transition (scale-95)
        // returns a shrunken box and the tooltip lands off-center.
        const contentWidth = this.contentElement.offsetWidth;
        const contentHeight = this.contentElement.offsetHeight;
        const arrowSize = this.arrowElement.offsetWidth;

        let wrapperLeft = 0;
        let wrapperTop = 0;
        const scrollX = window.scrollX;
        const scrollY = window.scrollY;

        switch (this.side) {
            case 'left':
                wrapperLeft = triggerRect.left + scrollX - contentWidth - arrowSize / 2 - this.sideOffset;
                wrapperTop = triggerRect.top + scrollY - contentHeight / 2 + triggerRect.height / 2;
                break;
            case 'top':
                wrapperLeft = triggerRect.left + scrollX - contentWidth / 2 + triggerRect.width / 2;
                wrapperTop = triggerRect.top + scrollY - contentHeight - arrowSize / 2 - this.sideOffset;
                break;
            case 'right':
                wrapperLeft = triggerRect.right + scrollX + arrowSize / 2 + this.sideOffset;
                wrapperTop = triggerRect.top + scrollY - contentHeight / 2 + triggerRect.height / 2;
                break;
            case 'bottom':
                wrapperLeft = triggerRect.left + scrollX - contentWidth / 2 + triggerRect.width / 2;
                wrapperTop = triggerRect.bottom + scrollY + arrowSize / 2 + this.sideOffset;
                break;
        }

        // Keep top/bottom tooltips inside the viewport (e.g. a trigger close
        // to the screen edge) and slide the arrow so it stays pointed at the
        // trigger even when the body is clamped.
        if ('top' === this.side || 'bottom' === this.side) {
            const margin = 8;
            const minLeft = scrollX + margin;
            const maxLeft = scrollX + document.documentElement.clientWidth - contentWidth - margin;
            const clampedLeft = Math.min(Math.max(wrapperLeft, minLeft), Math.min(maxLeft, Infinity));
            const shift = wrapperLeft - clampedLeft;
            this.arrowElement.style.marginLeft = shift ? `${shift}px` : '';
            wrapperLeft = clampedLeft;
        }

        this.wrapperElement.style.transform = `translate3d(${wrapperLeft}px, ${wrapperTop}px, 0)`;
    }
}
