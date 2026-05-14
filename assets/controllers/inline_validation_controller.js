/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Lightweight client-side validation that mirrors the visual style of Symfony Form
 * error rendering ("text-xs text-red-600" below the field). Used on forms where the
 * server-side handler (e.g. Symfony's form_login firewall) doesn't expose per-field
 * validation natively.
 *
 * Activation: put `data-controller="inline-validation" novalidate` on the <form> and
 *   `data-action="submit->inline-validation#validate"` to intercept submission.
 *
 * Per-field setup:
 *   - the <input> must have `required` and a stable `id`
 *   - sibling <p data-inline-validation-target="error" data-error-for="<inputId>"
 *       class="hidden text-xs text-red-600">...</p> for the message
 *
 * On submit:
 *   - empty required inputs flip their matching error <p> to visible and block submit
 *   - filled inputs hide their error <p>
 *   - the first empty input is focused so the user can fix it without hunting
 */
export default class extends Controller {
    static targets = ['error']

    connect() {
        this.element.querySelectorAll('input[required], textarea[required], select[required]').forEach((field) => {
            field.addEventListener('input', this.#onInput)
            field.addEventListener('blur', this.#onBlur)
        })
    }

    disconnect() {
        this.element.querySelectorAll('input[required], textarea[required], select[required]').forEach((field) => {
            field.removeEventListener('input', this.#onInput)
            field.removeEventListener('blur', this.#onBlur)
        })
    }

    validate(event) {
        // Honour the native `formnovalidate` opt-out on the clicked submit button.
        // Used by the Previous button on multi-step forms so going back never blocks
        // on still-empty fields.
        if (event.submitter?.hasAttribute('formnovalidate')) return

        let firstInvalid = null
        this.element.querySelectorAll('input[required], textarea[required], select[required]').forEach((field) => {
            const empty = this.#isEmpty(field)
            this.#toggleError(field, empty)
            if (empty && !firstInvalid) firstInvalid = field
        })

        if (firstInvalid) {
            event.preventDefault()
            firstInvalid.focus()
        }
    }

    #onInput = (event) => {
        // Clear the error once the user starts fixing the field.
        if (!this.#isEmpty(event.target)) this.#toggleError(event.target, false)
    }

    #onBlur = (event) => {
        // Only surface the error on blur if the user actually interacted with the field
        // (touched + left empty). Avoids yelling at users who just tabbed through.
        if (this.#isEmpty(event.target) && event.target.dataset.touched === 'true') {
            this.#toggleError(event.target, true)
        }
        event.target.dataset.touched = 'true'
    }

    #isEmpty(field) {
        // Checkboxes & radios count as "empty" when not checked — covers
        // Assert\IsTrue constraints (e.g. terms acceptance).
        if (field.type === 'checkbox' || field.type === 'radio') return !field.checked
        return !field.value.trim()
    }

    #toggleError(field, show) {
        const error = this.errorTargets.find((e) => e.dataset.errorFor === field.id)
        if (!error) return
        error.classList.toggle('hidden', !show)
        field.setAttribute('aria-invalid', show ? 'true' : 'false')
    }
}
