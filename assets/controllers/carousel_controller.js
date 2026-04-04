import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['track', 'dot']

    #index = 0
    #startX = 0
    #currentX = 0
    #dragging = false

    connect() {
        this.trackTarget.addEventListener('pointerdown', this.#onPointerDown)
        window.addEventListener('pointermove', this.#onPointerMove)
        window.addEventListener('pointerup', this.#onPointerUp)
    }

    disconnect() {
        this.trackTarget.removeEventListener('pointerdown', this.#onPointerDown)
        window.removeEventListener('pointermove', this.#onPointerMove)
        window.removeEventListener('pointerup', this.#onPointerUp)
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
        this.#dragging = true
        this.#startX = e.clientX
        this.#currentX = e.clientX
        this.trackTarget.style.transition = 'none'
        this.trackTarget.setPointerCapture(e.pointerId)
    }

    #onPointerMove = (e) => {
        if (!this.#dragging) return
        this.#currentX = e.clientX
        const diff = this.#currentX - this.#startX
        const base = -this.#index * this.trackTarget.offsetWidth
        this.trackTarget.style.transform = `translateX(${base + diff}px)`
    }

    #onPointerUp = () => {
        if (!this.#dragging) return
        this.#dragging = false

        const diff = this.#currentX - this.#startX
        const threshold = this.trackTarget.offsetWidth * 0.2

        if (diff < -threshold && this.#index < this.count - 1) {
            this.#slideTo(this.#index + 1)
        } else if (diff > threshold && this.#index > 0) {
            this.#slideTo(this.#index - 1)
        } else {
            this.#slideTo(this.#index)
        }
    }
}
