/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['sheet', 'backdrop']
    static classes = ['open']

    #isOpen = false

    open() {
        if (this.#isOpen) return
        this.#isOpen = true
        document.body.style.overflow = 'hidden'
        if (this.hasSheetTarget) {
            this.sheetTarget.classList.add(...this.openClasses)
        }
        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.add(...this.openClasses)
        }
        document.addEventListener('keydown', this.#handleKeydown)
    }

    close() {
        if (!this.#isOpen) return
        this.#isOpen = false
        document.body.style.overflow = ''
        if (this.hasSheetTarget) {
            this.sheetTarget.classList.remove(...this.openClasses)
        }
        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.remove(...this.openClasses)
        }
        document.removeEventListener('keydown', this.#handleKeydown)
    }

    #handleKeydown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault()
            this.close()
        }
    }
}
