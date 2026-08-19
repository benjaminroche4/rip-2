/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Zone d'upload de photos : dépôt par glisser-déposer sur toute la section
 * et squelettes de chargement pendant le POST. À poser sur le conteneur de
 * la section, avec :
 *   - data-photo-upload-target="input" sur l'<input type=file> (dans son
 *     <form> multipart, soumis via requestSubmit)
 *   - data-photo-upload-target="grid" sur la grille des vignettes (option :
 *     sans grille, une grille éphémère est créée pour les squelettes)
 *   - data-action="dragover->photo-upload#over dragleave->photo-upload#leave
 *     drop->photo-upload#drop change->photo-upload#changed"
 * Le POST classique suit son cours (redirect + rechargement Turbo) : les
 * squelettes ne vivent que le temps de la requête.
 */
export default class extends Controller {
    static targets = ['input', 'grid']

    static HIGHLIGHT = ['ring-2', 'ring-primary/40', 'bg-primary/5']

    /** Nécessaire pour autoriser le drop (comportement navigateur). */
    over(event) {
        if (!this.#carriesFiles(event)) {
            return
        }
        event.preventDefault()
        this.element.classList.add(...this.constructor.HIGHLIGHT)
    }

    leave(event) {
        // Ignore les dragleave internes (passage sur un enfant).
        if (event.relatedTarget && this.element.contains(event.relatedTarget)) {
            return
        }
        this.element.classList.remove(...this.constructor.HIGHLIGHT)
    }

    drop(event) {
        if (!this.#carriesFiles(event)) {
            return
        }
        event.preventDefault()
        this.element.classList.remove(...this.constructor.HIGHLIGHT)
        const images = [...event.dataTransfer.files].filter(file => ['image/jpeg', 'image/png', 'image/webp'].includes(file.type))
        if (images.length === 0) {
            return
        }
        const transfer = new DataTransfer()
        images.forEach(file => transfer.items.add(file))
        this.inputTarget.files = transfer.files
        this.#send(images.length)
    }

    /** Sélection via le label : même chemin que le drop. */
    changed(event) {
        if (event.target === this.inputTarget && this.inputTarget.files.length > 0) {
            this.#send(this.inputTarget.files.length)
        }
    }

    #send(count) {
        this.#showSkeletons(count)
        // La zone entière signale l'envoi en cours et gèle les re-clics.
        this.element.setAttribute('aria-busy', 'true')
        this.inputTarget.closest('form').requestSubmit()
    }

    #showSkeletons(count) {
        const grid = this.hasGridTarget ? this.gridTarget : this.#ephemeralGrid()
        for (let i = 0; i < count; i++) {
            const tile = document.createElement(grid.tagName === 'UL' ? 'li' : 'div')
            tile.className = 'aspect-[4/3] w-full animate-pulse rounded-xl bg-neutral-200/80'
            tile.dataset.testid = 'photo-upload-skeleton'
            grid.appendChild(tile)
        }
    }

    /** Pas encore de grille (zéro photo) : une grille juste pour les squelettes. */
    #ephemeralGrid() {
        const grid = document.createElement('div')
        grid.className = 'mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4'
        this.element.appendChild(grid)

        return grid
    }

    #carriesFiles(event) {
        return [...(event.dataTransfer?.types ?? [])].includes('Files')
    }
}
