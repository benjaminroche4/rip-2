/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['dialog']

    open() {
        this.dialogTarget.showModal()
        document.body.style.overflow = 'hidden'
        document.addEventListener('keydown', this.#handleKeydown)
    }

    close() {
        this.dialogTarget.close()
        document.body.style.overflow = ''
        document.removeEventListener('keydown', this.#handleKeydown)
    }

    closeOnClickOutside({ target }) {
        if (target === this.dialogTarget) {
            this.close()
        }
    }

    #handleKeydown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault()
            this.close()
        }
    }
}
