/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['content', 'chevron']
    static values = {
        open: { type: Boolean, default: false },
        duration: { type: Number, default: 300 },
    }

    connect() {
        const content = this.contentTarget
        content.style.overflow = 'hidden'
        content.style.height = this.openValue ? 'auto' : '0px'
        this.#syncChevron()

        setTimeout(() => {
            content.style.transition = `height ${this.durationValue}ms ease-in-out`
        }, 50)
    }

    toggle() {
        const content = this.contentTarget

        if (this.openValue) {
            content.style.height = `${content.scrollHeight}px`
            void content.offsetHeight
            requestAnimationFrame(() => {
                content.style.height = '0px'
            })
            this.openValue = false
        } else {
            content.style.height = `${content.scrollHeight}px`
            const onEnd = (e) => {
                if (e.propertyName !== 'height') return
                content.style.height = 'auto'
                content.removeEventListener('transitionend', onEnd)
            }
            content.addEventListener('transitionend', onEnd)
            this.openValue = true
        }

        this.#syncChevron()
    }

    #syncChevron() {
        if (!this.hasChevronTarget) return
        this.chevronTarget.classList.toggle('rotate-180', this.openValue)
    }
}
