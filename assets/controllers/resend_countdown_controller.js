/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Disables the OTP resend button for the duration of the server-side rate-limit
 * window (60s by default) and surfaces a "(59s)" hint next to the trigger so the
 * user understands why nothing happens when they click again. The deadline comes
 * from the session (otp_resend_available_at) so a page reload during the cooldown
 * picks up where it left off.
 *
 * Markup contract:
 *   <form data-controller="resend-countdown"
 *         data-resend-countdown-available-at-value="{{ unixTimestamp }}">
 *       <button data-resend-countdown-target="button">Renvoyer un code</button>
 *       <span   data-resend-countdown-target="remaining" hidden></span>
 *   </form>
 *
 * The button is re-enabled and the remaining hint hidden once the deadline is
 * reached. A submit on the form re-arms a default 60 s cooldown so the user
 * gets immediate UI feedback before the page reloads.
 */
export default class extends Controller {
    static targets = ['button', 'remaining']
    static values = {
        availableAt: { type: Number, default: 0 }, // unix timestamp seconds
        cooldown: { type: Number, default: 60 },   // seconds
    }

    connect() {
        this.boundSubmit = () => this.#armForNextSubmit()
        this.element.addEventListener('submit', this.boundSubmit)

        if (this.availableAtValue > 0 && this.#secondsLeft() > 0) {
            this.#startTicking()
        }
    }

    disconnect() {
        this.element.removeEventListener('submit', this.boundSubmit)
        if (this.tickHandle) clearInterval(this.tickHandle)
    }

    #armForNextSubmit() {
        // The form is about to POST and the page will reload — set the deadline
        // locally so the (now → +cooldown) gap doesn't fall through cracks even
        // if the server-side session write is delayed.
        this.availableAtValue = Math.floor(Date.now() / 1000) + this.cooldownValue
    }

    #startTicking() {
        this.#tick()
        this.tickHandle = setInterval(() => this.#tick(), 1000)
    }

    #tick() {
        const remaining = this.#secondsLeft()
        if (remaining <= 0) {
            this.#finish()
            return
        }
        this.buttonTarget.disabled = true
        this.buttonTarget.classList.add('opacity-50', 'cursor-not-allowed')
        this.buttonTarget.classList.remove('cursor-pointer', 'hover:text-primary')
        this.remainingTarget.textContent = `(${remaining}s)`
        this.remainingTarget.hidden = false
    }

    #finish() {
        if (this.tickHandle) {
            clearInterval(this.tickHandle)
            this.tickHandle = null
        }
        this.buttonTarget.disabled = false
        this.buttonTarget.classList.remove('opacity-50', 'cursor-not-allowed')
        this.buttonTarget.classList.add('cursor-pointer', 'hover:text-primary')
        this.remainingTarget.hidden = true
    }

    #secondsLeft() {
        return Math.max(0, this.availableAtValue - Math.floor(Date.now() / 1000))
    }
}
