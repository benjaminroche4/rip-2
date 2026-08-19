/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'
import * as Turbo from '@hotwired/turbo'

/**
 * Suppression d'une photo sans rechargement de page. À poser sur le <form>
 * de suppression, APRÈS confirm-dialog dans le data-action :
 *   data-action="submit->confirm-dialog#intercept submit->photo-delete#submit"
 * Le premier submit est intercepté par la modale de confirmation (l'event
 * arrive ici defaultPrevented, on ne fait rien) ; le submit confirmé part en
 * fetch : la vignette est retirée, les index de galerie recalés, et
 * l'événement photo-delete:removed (bubbles) prévient le lightbox pour que
 * le slider perde la photo sans refresh. Cas re-rendus côté serveur
 * (dernière photo, couverture 2x2 de la bibliothèque) : visite Turbo sur
 * place. Échec réseau : soumission native, l'état serveur fait foi.
 */
export default class extends Controller {
    async submit(event) {
        if (event.defaultPrevented) {
            return
        }
        event.preventDefault()

        const form = this.element
        const tile = form.closest('li')

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`)
            }
        } catch {
            form.submit()

            return
        }

        const grid = tile?.parentElement
        // La grille vide et la promotion d'une nouvelle couverture (tuile
        // 2x2 de la bibliothèque) sont des états rendus côté serveur.
        if (!tile || !grid || grid.children.length <= 1 || tile.classList.contains('sm:col-span-2')) {
            Turbo.visit(window.location.href, { action: 'replace' })

            return
        }

        const tiles = [...grid.children].filter((el) => el.tagName === 'LI')
        const index = tiles.indexOf(tile)
        tile.remove()

        // Recale les index d'ouverture du lightbox sur les tuiles restantes.
        // querySelector assumé : les tuiles voisines sont hors du scope de ce
        // controller (posé sur le form), un target ne peut pas les couvrir.
        tiles.filter((el) => el !== tile).forEach((el, i) => {
            const trigger = el.querySelector('[data-gallery-index-param]')
            if (trigger) {
                trigger.dataset.galleryIndexParam = i
            }
        })

        this.dispatch('removed', { detail: { index } })
    }
}
