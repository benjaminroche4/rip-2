/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Six-box OTP input that keeps a single hidden form field in sync with the visible
 * digit boxes. The visible <input data-otp-input-target="digit"> elements have no
 * `name` attribute — they are pure UI. The submitted value lives on
 * <input type="hidden" data-otp-input-target="hidden" name="…[code]">, which the
 * controller updates on every keystroke and paste.
 *
 * Keyboard model mirrors what users already know from iOS/Android OTP fields:
 *   - typing a digit advances to the next box
 *   - Backspace on an empty box jumps back and lets the user delete the previous one
 *   - ArrowLeft / ArrowRight navigate between boxes
 *   - Pasting a 6-digit code from the email fills all boxes at once and focuses
 *     the last filled cell so a casual Enter submits the form
 *
 * Non-digit characters are stripped on input to keep the visible boxes coherent
 * with the validated server-side payload (Personal DTO requires /^\d{6}$/).
 */
export default class extends Controller {
    static targets = ['digit', 'hidden', 'submit', 'submitLabel', 'submitLoading']

    connect() {
        // Restore the visible boxes from any preserved value — happens when the
        // server returns a 422 after a wrong/expired code and re-renders the form
        // with the previous submission still on the hidden input.
        const preserved = (this.hiddenTarget.value || '').replace(/\D/g, '')
        this.digitTargets.forEach((input, i) => {
            input.value = preserved[i] || ''
        })
    }

    onInput(event) {
        const target = event.target
        // Strip anything non-numeric and keep only the last typed digit so users
        // who type fast or hold a key don't end up with "12" in one box.
        target.value = target.value.replace(/\D/g, '').slice(-1)
        this.#syncHidden()
        if (target.value !== '') this.#focusNext(target)
        this.#trySubmit()
    }

    onKeydown(event) {
        const target = event.target

        if (event.key === 'Backspace' && target.value === '') {
            event.preventDefault()
            this.#focusPrev(target)

            return
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault()
            this.#focusPrev(target)

            return
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault()
            this.#focusNext(target)
        }
    }

    onPaste(event) {
        const raw = event.clipboardData?.getData('text') ?? ''
        const digits = raw.replace(/\D/g, '').slice(0, this.digitTargets.length)
        if (digits === '') return

        event.preventDefault()
        this.digitTargets.forEach((input, i) => {
            input.value = digits[i] || ''
        })
        this.#syncHidden()
        const lastIndex = Math.min(digits.length, this.digitTargets.length) - 1
        const focusTarget = this.digitTargets[lastIndex]
        focusTarget?.focus()
        focusTarget?.select?.()
        this.#trySubmit()
    }

    #syncHidden() {
        this.hiddenTarget.value = this.digitTargets.map((i) => i.value).join('')
    }

    // Auto-submit once all six boxes are filled — saves the user from clicking
    // the button when the OTP is complete. Sets a busy state to swap the button
    // label for a spinner and lock the inputs while Turbo navigates.
    #trySubmit() {
        if (this.hiddenTarget.value.length !== this.digitTargets.length) return
        if (this.element.dataset.otpInputSubmitting === '1') return

        this.element.dataset.otpInputSubmitting = '1'
        this.#setBusy(true)
        this.element.requestSubmit()
    }

    #setBusy(busy) {
        this.digitTargets.forEach((i) => {
            i.disabled = busy
        })

        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = busy
        }
        if (this.hasSubmitLabelTarget) {
            this.submitLabelTarget.hidden = busy
        }
        if (this.hasSubmitLoadingTarget) {
            this.submitLoadingTarget.hidden = !busy
            this.submitLoadingTarget.classList.toggle('inline-flex', busy)
        }
    }

    #focusNext(current) {
        const idx = this.digitTargets.indexOf(current)
        if (idx >= 0 && idx < this.digitTargets.length - 1) {
            const next = this.digitTargets[idx + 1]
            next.focus()
            next.select?.()
        }
    }

    #focusPrev(current) {
        const idx = this.digitTargets.indexOf(current)
        if (idx > 0) {
            const prev = this.digitTargets[idx - 1]
            prev.focus()
            prev.select?.()
        }
    }
}
