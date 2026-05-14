/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Scores a password client-side and reflects the result on a 4-segment indicator.
 * The whole indicator stays hidden (opacity-0) until the user starts typing — the
 * controller toggles the class based on the computed score (0 ⇔ empty input).
 *
 * The input field lives outside the controller's DOM scope (it's the actual password
 * input the user types into), so we resolve it by id and bind a listener in connect().
 * Pure presentation — authoritative validation runs server-side via
 * Assert\PasswordStrength + Assert\NotCompromisedPassword on the DTO.
 */
export default class extends Controller {
    static targets = ['track', 'segment', 'label']
    static values = {
        inputId: String,
        weakLabel: String,
        mediumLabel: String,
        goodLabel: String,
        strongLabel: String,
    }

    #input = null
    #tones = ['', 'bg-red-400', 'bg-amber-400', 'bg-lime-500', 'bg-emerald-500']

    connect() {
        this.#input = document.getElementById(this.inputIdValue)
        if (!this.#input) return
        this.#input.addEventListener('input', this.#onInput)
        this.#render(this.#score(this.#input.value))
    }

    disconnect() {
        this.#input?.removeEventListener('input', this.#onInput)
        this.#input = null
    }

    #onInput = (event) => {
        this.#render(this.#score(event.target.value))
    }

    #score(password) {
        if (!password) return 0

        const length = password.length
        const variety =
            Number(/[a-z]/.test(password)) +
            Number(/[A-Z]/.test(password)) +
            Number(/\d/.test(password)) +
            Number(/[^A-Za-z0-9]/.test(password))

        // length points: <8 → 0, 8-11 → 1, 12+ → 2
        const lengthPoints = length < 8 ? 0 : length < 12 ? 1 : 2
        const total = lengthPoints + variety

        if (total <= 1) return 1
        if (total <= 3) return 2
        if (total <= 5) return 3
        return 4
    }

    #render(score) {
        // Collapse the indicator entirely (display:none) until the user starts typing —
        // `hidden` removes the element from the layout, so no residual margin/space.
        this.element.classList.toggle('hidden', score === 0)

        this.segmentTargets.forEach((segment, i) => {
            // Clear any previously applied tone, then light up the segment if its index
            // falls within the current score. `bg-neutral-200` is the resting state — we
            // toggle it inversely so the tone class isn't shadowed by the cascade
            // (Tailwind's `bg-neutral-*` lives after `bg-amber-*` / `bg-emerald-*` /
            // `bg-lime-*` in the compiled CSS, so leaving it on would override them).
            for (const tone of this.#tones) {
                if (tone) segment.classList.remove(tone)
            }
            if (i < score && this.#tones[score]) {
                segment.classList.remove('bg-neutral-200')
                segment.classList.add(this.#tones[score])
            } else {
                segment.classList.add('bg-neutral-200')
            }
        })

        if (this.hasTrackTarget) {
            this.trackTarget.setAttribute('aria-valuenow', String(score))
        }

        if (this.hasLabelTarget) {
            const labels = [
                '',
                this.weakLabelValue,
                this.mediumLabelValue,
                this.goodLabelValue,
                this.strongLabelValue,
            ]
            this.labelTarget.textContent = labels[score] || ' '
        }
    }
}
