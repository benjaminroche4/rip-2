/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['dialog']

    open() {
        this.dialogTarget.showModal()
        this.dialogTarget.focus()
        document.body.style.overflow = 'hidden'
        document.addEventListener('keydown', this.#handleKeydown)
        requestAnimationFrame(() => {
            window.dispatchEvent(new Event('resize'))
        })
    }

    close() {
        this.dialogTarget.close()
        document.body.style.overflow = ''
        document.removeEventListener('keydown', this.#handleKeydown)

        // Prevent focus ring from showing on the trigger (large clickable map area)
        if (document.activeElement instanceof HTMLElement) {
            document.activeElement.blur()
        }
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
