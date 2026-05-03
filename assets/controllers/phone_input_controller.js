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

        if (this.inputTarget.closest('.iti')) {
            return;
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
    }

    syncE164() {
        if (!this.iti) {
            return;
        }

        let value = '';
        try {
            value = this.iti.getNumber() || '';
        } catch {
            value = '';
        }

        if (!value || !value.startsWith('+')) {
            const data = this.iti.getSelectedCountryData();
            const digits = this.inputTarget.value.replace(/\D/g, '').replace(/^0+/, '');
            if (data && data.dialCode && digits) {
                value = `+${data.dialCode}${digits}`;
            }
        }

        if (value) {
            this.inputTarget.value = value;
        }
    }

    disconnect() {
        if (this.form && this.boundSubmit) {
            this.form.removeEventListener('submit', this.boundSubmit, { capture: true });
        }
        if (this.iti) {
            this.iti.destroy();
            this.iti = null;
        }
    }
}
