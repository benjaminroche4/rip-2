/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Toggle l'affichage d'un conteneur de carte en plein écran sur mobile
 * sans démonter/déplacer la map (ce qui casserait Google Maps).
 */
export default class extends Controller {
    static targets = ['container']
    static classes = ['open']

    #isOpen = false

    #handleKeydown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault()
            this.close()
        }
    }

    open() {
        if (!this.hasContainerTarget) return
        this.#isOpen = true
        document.body.style.overflow = 'hidden'
        this.containerTarget.classList.add(...this.openClasses)
        document.addEventListener('keydown', this.#handleKeydown)
        requestAnimationFrame(() => {
            window.dispatchEvent(new Event('resize'))
        })
    }

    close() {
        if (!this.hasContainerTarget || !this.#isOpen) return
        this.#isOpen = false
        document.body.style.overflow = ''
        this.containerTarget.classList.remove(...this.openClasses)
        document.removeEventListener('keydown', this.#handleKeydown)
        requestAnimationFrame(() => {
            window.dispatchEvent(new Event('resize'))
        })
    }
}
