/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

// Keyboard-driven triage on the leads list: ArrowUp/ArrowDown move the
// focus ring between cards, Enter opens the focused card's detail page,
// and 1-6 apply the matching status (the keys mirror the dropdown order)
// by clicking the card's own dropdown option, so the whole live flow
// (modals, animations, badges) stays identical to a mouse click.
export default class extends Controller {
    static classes = ['focus']

    #focusedId = null
    #onKeydown = null
    #onRender = null

    connect() {
        this.#onKeydown = (event) => this.#handleKey(event)
        this.#onRender = () => this.#reapplyFocus()
        window.addEventListener('keydown', this.#onKeydown)
        document.addEventListener('live:render', this.#onRender, true)
    }

    disconnect() {
        window.removeEventListener('keydown', this.#onKeydown)
        document.removeEventListener('live:render', this.#onRender, true)
    }

    #cards() {
        return [...this.element.querySelectorAll('[data-testid="contact-card"]')]
    }

    #handleKey(event) {
        const target = event.target
        if (target instanceof HTMLElement && (target.matches('input, textarea, select') || target.isContentEditable)) {
            return
        }
        // Never fight a modal or an open dropdown.
        if (document.querySelector('[data-testid="contact-create-modal"], [data-testid="qualify-modal"], [data-testid="recall-modal"], details[open] > div')) {
            return
        }

        const cards = this.#cards()
        if (cards.length === 0) {
            return
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault()
            const index = this.#focusedIndex(cards)
            const next = event.key === 'ArrowDown'
                ? Math.min(index + 1, cards.length - 1)
                : Math.max(index - 1, index === -1 ? 0 : 0)
            this.#focusCard(cards, index === -1 ? 0 : next)

            return
        }

        const focused = cards.find(card => card.id === this.#focusedId)
        if (!focused) {
            return
        }

        if (event.key === 'Enter') {
            const link = focused.querySelector('[data-testid="contact-card-details"]')
            if (link) {
                event.preventDefault()
                link.click()
            }

            return
        }

        if (/^[1-6]$/.test(event.key)) {
            const options = focused.querySelectorAll('[data-live-action-param="changeStatus"]')
            const option = options[Number(event.key) - 1]
            if (option) {
                event.preventDefault()
                option.click()
            }
        }
    }

    #focusedIndex(cards) {
        return cards.findIndex(card => card.id === this.#focusedId)
    }

    #focusCard(cards, index) {
        cards.forEach(card => card.classList.remove(...this.focusClasses))
        const card = cards[index]
        if (card) {
            this.#focusedId = card.id
            card.classList.add(...this.focusClasses)
            card.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
        }
    }

    // Cards are fully rewritten on live re-renders (data-skip-morph):
    // re-apply the ring on the same card, or clear if it left the list.
    #reapplyFocus() {
        if (null === this.#focusedId) {
            return
        }
        const card = document.getElementById(this.#focusedId)
        if (card) {
            card.classList.add(...this.focusClasses)
        }
    }
}
