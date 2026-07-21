/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * The address must come from the Google suggestions list: the picked place id
 * travels in a hidden field, set on selection and cleared as soon as the user
 * types again. The steps gate (hidden field tagged as a section field) and
 * the server-side NotBlank both require it, so a free-typed address can
 * neither unlock the next section nor pass submission.
 */
export default class extends Controller {
    static targets = ['input', 'address', 'hint']

    // places-autocomplete:select fires after the input event it triggers on
    // the visible field, so this always runs last and wins over clear().
    set(event) {
        this.inputTarget.value = event.detail?.placeId ?? ''
        this.#sync()
    }

    clear() {
        if ('' === this.inputTarget.value) {
            this.#toggleHint()

            return
        }
        this.inputTarget.value = ''
        this.#sync()
    }

    #sync() {
        this.#toggleHint()
        // Re-run the steps completeness check against the new value.
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }))
    }

    // "Pick an address from the list": only while there is text but no
    // confirmed selection.
    #toggleHint() {
        if (!this.hasHintTarget || !this.hasAddressTarget) return
        const pending = '' === this.inputTarget.value && '' !== this.addressTarget.value.trim()
        this.hintTarget.classList.toggle('hidden', !pending)
    }
}
