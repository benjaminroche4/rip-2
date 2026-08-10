/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import intlTelInput from 'intl-tel-input';
import 'intl-tel-input/css';

export default class extends Controller {
    static targets = ['input'];
    static values = {
        initialCountry: { type: String, default: 'fr' },
        searchPlaceholder: { type: String, default: 'Rechercher un indicatif' },
    };

    connect() {
        if (!this.hasInputTarget) {
            return;
        }

        // Turbo snapshots the DOM before Stimulus disconnect runs, so a cached
        // page can restore the .iti wrapper that the previous instance built.
        // If that fossil wrapper is here, unwrap the input before re-initialising,
        // otherwise intlTelInput refuses to wrap and the field becomes inert.
        const fossil = this.inputTarget.closest('.iti');
        if (fossil && fossil.parentElement) {
            fossil.parentElement.insertBefore(this.inputTarget, fossil);
            fossil.remove();
        }

        this.iti = intlTelInput(this.inputTarget, {
            initialCountry: this.initialCountryValue,
            preferredCountries: ['fr', 'ch', 'be', 'gb', 'us'],
            separateDialCode: true,
            countrySearch: true,
            formatAsYouType: true,
            nationalMode: false,
            autoPlaceholder: 'off',
            i18n: {
                searchPlaceholder: this.searchPlaceholderValue,
            },
        });

        // intl-tel-input measures the dial-code box at init to compute the
        // input's left padding. On a hard load its stylesheet (injected async
        // by the importmap) can land AFTER that measurement: the padding stays
        // too small and the digits start under the dial code. Re-selecting the
        // current country once everything is loaded re-runs the measurement.
        this.boundPaddingFix = () => {
            if (!this.iti) return;
            const container = this.inputTarget.closest('.iti')?.querySelector('.iti__country-container');
            if (!container) return;
            const width = container.getBoundingClientRect().width;
            if (width > 0) this.inputTarget.style.paddingLeft = `${Math.ceil(width) + 6}px`;
        };
        if ('complete' === document.readyState) {
            this.paddingTimer = setTimeout(this.boundPaddingFix, 100);
        } else {
            window.addEventListener('load', this.boundPaddingFix, { once: true });
            // Safety net if load never fires (Turbo visit edge cases).
            this.paddingTimer = setTimeout(this.boundPaddingFix, 800);
        }
        // Dial codes vary in width (+1, +33, +377...): recompute after every
        // country switch, once the box has been re-rendered.
        this.boundCountryChange = () => requestAnimationFrame(this.boundPaddingFix);
        this.inputTarget.addEventListener('countrychange', this.boundCountryChange);

        this.form = this.inputTarget.closest('form');
        this.boundSubmit = () => this.syncE164();
        if (this.form) {
            this.form.addEventListener('submit', this.boundSubmit, { capture: true });
        }
        // Modal forms without a <form> (live action buttons) never fire a
        // submit: sync the E.164 value when the field loses focus instead.
        // Idempotent, so doing both never double-prefixes the dial code.
        this.inputTarget.addEventListener('blur', this.boundSubmit);

        // Tear down BEFORE Turbo caches the page so the snapshot doesn't include
        // the .iti wrapper. Without this, a back-nav restores a fossil wrapper
        // that re-init can't drive (input becomes unwritable).
        this.boundBeforeCache = () => {
            if (this.iti) {
                this.iti.destroy();
                this.iti = null;
            }
        };
        document.addEventListener('turbo:before-cache', this.boundBeforeCache);
    }

    syncE164() {
        if (!this.iti) {
            return;
        }

        const data = this.iti.getSelectedCountryData();
        if (!data || !data.dialCode) {
            return;
        }

        // Build E.164 from raw digits, idempotent across multiple submits.
        // The visible input may already contain a previous E.164 value
        // (server re-renders the canonical form on validation failure),
        // so blindly prepending the dial code would yield "+33+33612...".
        // Strip every leading occurrence of the current dial code, then any
        // national leading zeros, then prefix once.
        let digits = this.inputTarget.value.replace(/\D/g, '');
        while (digits.startsWith(data.dialCode)) {
            digits = digits.slice(data.dialCode.length);
        }
        digits = digits.replace(/^0+/, '');

        if (digits) {
            this.inputTarget.value = `+${data.dialCode}${digits}`;
            // Inside a LiveComponent form the model syncs on `change`: without
            // this dispatch the server would keep the raw national digits.
            // No-op for classic Turbo form posts (value is read from the DOM).
            this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    disconnect() {
        if (this.boundPaddingFix) {
            window.removeEventListener('load', this.boundPaddingFix);
        }
        if (this.boundCountryChange && this.hasInputTarget) {
            this.inputTarget.removeEventListener('countrychange', this.boundCountryChange);
        }
        if (this.boundSubmit && this.hasInputTarget) {
            this.inputTarget.removeEventListener('blur', this.boundSubmit);
        }
        clearTimeout(this.paddingTimer);
        if (this.form && this.boundSubmit) {
            this.form.removeEventListener('submit', this.boundSubmit, { capture: true });
        }
        if (this.boundBeforeCache) {
            document.removeEventListener('turbo:before-cache', this.boundBeforeCache);
        }
        if (this.iti) {
            this.iti.destroy();
            this.iti = null;
        }
    }
}
