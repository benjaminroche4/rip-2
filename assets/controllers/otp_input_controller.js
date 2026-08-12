/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * 6-box one-time-code input for the 2FA challenge. Digits auto-advance,
 * backspace walks back, pasting a full code fills every box, and the form
 * auto-submits as soon as the 6th digit lands. A toggle swaps to a plain
 * input for recovery codes (8 digits, no auto-submit).
 *
 * Whatever the mode, the value ends up in the hidden `_auth_code` field the
 * firewall reads.
 */
export default class extends Controller {
    static targets = ['digit', 'hidden', 'otpZone', 'recoveryZone', 'recoveryInput']

    #submitted = false

    connect() {
        this.digitTargets[0]?.focus()
    }

    /* ---- OTP boxes ---- */

    input(event) {
        const box = event.target
        box.value = box.value.replace(/\D/g, '').slice(-1)
        if (box.value !== '') {
            const next = this.digitTargets[this.#indexOf(box) + 1]
            next?.focus()
            next?.select()
        }
        this.#syncAndMaybeSubmit()
    }

    keydown(event) {
        const box = event.target
        const index = this.#indexOf(box)
        if (event.key === 'Backspace' && box.value === '' && index > 0) {
            const previous = this.digitTargets[index - 1]
            previous.focus()
            previous.value = ''
            this.#syncAndMaybeSubmit()
            event.preventDefault()
        } else if (event.key === 'ArrowLeft' && index > 0) {
            this.digitTargets[index - 1].focus()
            event.preventDefault()
        } else if (event.key === 'ArrowRight' && index < this.digitTargets.length - 1) {
            this.digitTargets[index + 1].focus()
            event.preventDefault()
        }
    }

    paste(event) {
        const digits = (event.clipboardData?.getData('text') ?? '').replace(/\D/g, '')
        if (digits === '') return
        event.preventDefault()
        this.digitTargets.forEach((box, i) => { box.value = digits[i] ?? '' })
        const focusIndex = Math.min(digits.length, this.digitTargets.length - 1)
        this.digitTargets[focusIndex].focus()
        this.#syncAndMaybeSubmit()
    }

    /* ---- recovery-code mode ---- */

    showRecovery(event) {
        event.preventDefault()
        this.otpZoneTarget.classList.add('hidden')
        this.recoveryZoneTarget.classList.remove('hidden')
        this.hiddenTarget.value = ''
        this.recoveryInputTarget.focus()
    }

    showOtp(event) {
        event.preventDefault()
        this.recoveryZoneTarget.classList.add('hidden')
        this.otpZoneTarget.classList.remove('hidden')
        this.hiddenTarget.value = ''
        this.digitTargets.forEach((box) => { box.value = '' })
        this.digitTargets[0]?.focus()
    }

    syncRecovery() {
        this.hiddenTarget.value = this.recoveryInputTarget.value.trim()
    }

    /* ---- internals ---- */

    #indexOf(box) {
        return this.digitTargets.indexOf(box)
    }

    #syncAndMaybeSubmit() {
        const code = this.digitTargets.map((box) => box.value).join('')
        this.hiddenTarget.value = code
        if (code.length === this.digitTargets.length && !this.#submitted) {
            // Auto-submit on the last digit; the guard avoids double posts.
            this.#submitted = true
            this.element.requestSubmit()
        }
    }
}
