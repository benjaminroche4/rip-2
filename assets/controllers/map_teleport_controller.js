import { Controller } from '@hotwired/stimulus'

/**
 * Toggle l'affichage d'un conteneur de carte en plein écran sur mobile
 * sans démonter/déplacer la map (ce qui casserait Google Maps).
 */
export default class extends Controller {
    static targets = ['container']
    static classes = ['open']

    open() {
        if (!this.hasContainerTarget) return
        document.body.style.overflow = 'hidden'
        this.containerTarget.classList.add(...this.openClasses)
        // Force Google Maps à redessiner après le changement de taille
        requestAnimationFrame(() => {
            window.dispatchEvent(new Event('resize'))
        })
    }

    close() {
        if (!this.hasContainerTarget) return
        document.body.style.overflow = ''
        this.containerTarget.classList.remove(...this.openClasses)
        requestAnimationFrame(() => {
            window.dispatchEvent(new Event('resize'))
        })
    }
}
