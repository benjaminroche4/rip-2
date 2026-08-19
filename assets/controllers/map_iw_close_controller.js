/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Ferme les InfoWindows d'une carte UX Map au clic en dehors de la bulle :
 * sur le fond de carte (événement click de la map Google) comme partout
 * ailleurs sur la page (clic hors de l'élément carte). À poser sur
 * l'élément ux_map, en complément du masquage du bouton fermer natif
 * (classe .map-tidy-iw). Le clic sur un autre pin ferme déjà la bulle
 * courante via l'autoClose du bridge; un clic dans la bulle elle-même ne
 * déclenche ni l'un ni l'autre.
 */
export default class extends Controller {
    #infoWindows = []
    #mapListener = null

    connect() {
        this.element.addEventListener('ux:map:connect', this.#onMapConnect)
        this.element.addEventListener('ux:map:info-window:after-create', this.#onInfoWindowCreated)
        document.addEventListener('click', this.#onDocumentClick)
        // Ce controller est lazy : le controller UX Map (même élément) a pu
        // se connecter avant nous, typiquement après une navigation Turbo.
        // Rattrapage : carte + bulles déjà créées, sinon rien ne se ferme.
        for (const identifier of (this.element.dataset.controller ?? '').split(/\s+/)) {
            const controller = this.application.getControllerForElementAndIdentifier(this.element, identifier)
            if (controller?.map) {
                this.#mapListener = controller.map.addListener('click', this.#close)
                this.#infoWindows.push(...(controller.infoWindows ?? []))
                break
            }
        }
    }

    disconnect() {
        this.element.removeEventListener('ux:map:connect', this.#onMapConnect)
        this.element.removeEventListener('ux:map:info-window:after-create', this.#onInfoWindowCreated)
        document.removeEventListener('click', this.#onDocumentClick)
        if (this.#mapListener) {
            google.maps.event.removeListener(this.#mapListener)
            this.#mapListener = null
        }
        this.#infoWindows = []
    }

    #close = () => {
        this.#infoWindows.forEach(iw => iw.close())
    }

    #onMapConnect = (event) => {
        this.#mapListener = event.detail.map.addListener('click', this.#close)
    }

    #onInfoWindowCreated = (event) => {
        this.#infoWindows.push(event.detail.infoWindow)
    }

    /** Clic n'importe où hors de la carte (la bulle vit dedans) : fermeture. */
    #onDocumentClick = (event) => {
        if (!this.element.contains(event.target)) {
            this.#close()
        }
    }
}
