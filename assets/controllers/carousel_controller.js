/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['track', 'dot']

    #index = 0
    #startX = 0
    #startY = 0
    #currentX = 0
    #dragging = false
    #startTime = 0
    #isHorizontalSwipe = null

    connect() {
        this.trackTarget.style.touchAction = 'pan-y'
        this.trackTarget.addEventListener('pointerdown', this.#onPointerDown)
        window.addEventListener('pointermove', this.#onPointerMove)
        window.addEventListener('pointerup', this.#onPointerUp)
        window.addEventListener('pointercancel', this.#onPointerUp)
    }

    disconnect() {
        this.trackTarget.removeEventListener('pointerdown', this.#onPointerDown)
        window.removeEventListener('pointermove', this.#onPointerMove)
        window.removeEventListener('pointerup', this.#onPointerUp)
        window.removeEventListener('pointercancel', this.#onPointerUp)
    }

    get count() {
        return this.trackTarget.children.length
    }

    goTo(event) {
        const index = parseInt(event.currentTarget.dataset.index, 10)
        this.#slideTo(index)
    }

    #slideTo(index) {
        this.#index = Math.max(0, Math.min(index, this.count - 1))
        this.trackTarget.style.transition = 'transform 300ms ease-out'
        this.trackTarget.style.transform = `translateX(-${this.#index * 100}%)`
        this.#updateDots()
    }

    #updateDots() {
        this.dotTargets.forEach((dot, i) => {
            dot.classList.toggle('bg-white', i === this.#index)
            dot.classList.toggle('bg-white/50', i !== this.#index)
        })
    }

    #onPointerDown = (e) => {
        if (e.pointerType === 'mouse' && e.button !== 0) return

        this.#dragging = true
        this.#startX = e.clientX
        this.#startY = e.clientY
        this.#currentX = e.clientX
        this.#startTime = Date.now()
        this.#isHorizontalSwipe = null
        this.trackTarget.style.transition = 'none'
        this.trackTarget.setPointerCapture(e.pointerId)
    }

    #onPointerMove = (e) => {
        if (!this.#dragging) return

        const diffX = e.clientX - this.#startX
        const diffY = e.clientY - this.#startY

        // Determine swipe direction on first significant movement
        if (this.#isHorizontalSwipe === null && (Math.abs(diffX) > 5 || Math.abs(diffY) > 5)) {
            this.#isHorizontalSwipe = Math.abs(diffX) > Math.abs(diffY)

            if (this.#isHorizontalSwipe) {
                // Take over from browser — disable vertical scroll
                this.trackTarget.style.touchAction = 'none'
            } else {
                // Vertical scroll — let the browser handle it
                this.#dragging = false
                return
            }
        }

        if (!this.#isHorizontalSwipe) return

        e.preventDefault()
        this.#currentX = e.clientX

        const base = -this.#index * this.trackTarget.offsetWidth
        // Add resistance at edges
        let offset = diffX
        if ((this.#index === 0 && offset > 0) || (this.#index === this.count - 1 && offset < 0)) {
            offset *= 0.3
        }
        this.trackTarget.style.transform = `translateX(${base + offset}px)`
    }

    #onPointerUp = () => {
        if (!this.#dragging) return
        this.#dragging = false

        // Restore touch-action for next interaction
        this.trackTarget.style.touchAction = 'pan-y'

        const diff = this.#currentX - this.#startX
        const elapsed = Date.now() - this.#startTime
        const velocity = Math.abs(diff) / elapsed

        // Swipe threshold: either 15% of width OR fast flick (velocity > 0.3px/ms)
        const threshold = this.trackTarget.offsetWidth * 0.15
        const shouldAdvance = Math.abs(diff) > threshold || velocity > 0.3

        if (shouldAdvance && diff < 0 && this.#index < this.count - 1) {
            this.#slideTo(this.#index + 1)
        } else if (shouldAdvance && diff > 0 && this.#index > 0) {
            this.#slideTo(this.#index - 1)
        } else {
            this.#slideTo(this.#index)
        }

        this.#isHorizontalSwipe = null
    }
}
