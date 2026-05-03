import { Controller } from '@hotwired/stimulus';
import intlTelInput from 'intl-tel-input';
import 'intl-tel-input/css';

export default class extends Controller {
    static targets = ['input'];
    static values = {
        initialCountry: { type: String, default: 'fr' },
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
        });

        this.form = this.inputTarget.closest('form');
        this.boundSubmit = () => this.syncE164();
        if (this.form) {
            this.form.addEventListener('submit', this.boundSubmit, { capture: true });
        }

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
        }
    }

    disconnect() {
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
