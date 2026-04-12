/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['dialog', 'image', 'counter', 'thumbnails']

    #currentIndex = 0
    #photos = []
    #thumbnailsRendered = false
    #swipeStartX = 0
    #swiping = false
    #didSwipe = false

    connect() {
        this.#photos = JSON.parse(this.element.dataset.galleryPhotosValue || '[]')
    }

    open({ params: { index } }) {
        this.#currentIndex = index ?? 0
        if (!this.#thumbnailsRendered) {
            this.#renderThumbnails()
            this.#thumbnailsRendered = true
        }
        this.#render()
        this.dialogTarget.showModal()

        document.addEventListener('keydown', this.#handleKeydown)

        const img = this.imageTarget
        img.addEventListener('pointerdown', this.#handlePointerDown)
        img.addEventListener('pointermove', this.#handlePointerMove)
        img.addEventListener('pointerup', this.#handlePointerUp)
        img.addEventListener('pointercancel', this.#handlePointerUp)
    }

    close() {
        this.#swiping = false
        this.dialogTarget.close()

        document.removeEventListener('keydown', this.#handleKeydown)

        const img = this.imageTarget
        img.removeEventListener('pointerdown', this.#handlePointerDown)
        img.removeEventListener('pointermove', this.#handlePointerMove)
        img.removeEventListener('pointerup', this.#handlePointerUp)
        img.removeEventListener('pointercancel', this.#handlePointerUp)
    }

    closeOnClickOutside({ target }) {
        // Don't close if we just finished a swipe
        if (this.#didSwipe) {
            this.#didSwipe = false
            return
        }
        if (target === this.dialogTarget) {
            this.close()
        }
    }

    prev() {
        this.#currentIndex = (this.#currentIndex - 1 + this.#photos.length) % this.#photos.length
        this.#render('left')
    }

    next() {
        this.#currentIndex = (this.#currentIndex + 1) % this.#photos.length
        this.#render('right')
    }

    goTo({ params: { index } }) {
        const direction = index > this.#currentIndex ? 'right' : 'left'
        this.#currentIndex = index ?? 0
        this.#render(direction)
    }

    // --- Arrow function handlers (auto-bound to `this`) ---

    #handleKeydown = (event) => {
        if (event.key === 'ArrowLeft') {
            event.preventDefault()
            this.prev()
        } else if (event.key === 'ArrowRight') {
            event.preventDefault()
            this.next()
        } else if (event.key === 'Escape') {
            event.preventDefault()
            this.close()
        }
    }

    #handlePointerDown = (event) => {
        event.preventDefault()
        this.#swipeStartX = event.clientX
        this.#swiping = true
        this.#didSwipe = false
        this.imageTarget.setPointerCapture(event.pointerId)
        this.imageTarget.style.cursor = 'grabbing'
    }

    #handlePointerMove = (event) => {
        if (!this.#swiping) return
        event.preventDefault()
        const dx = event.clientX - this.#swipeStartX
        this.imageTarget.style.transform = `translateX(${dx * 0.4}px)`
        this.imageTarget.style.transition = 'none'
    }

    #handlePointerUp = (event) => {
        if (!this.#swiping) return
        this.#swiping = false

        const img = this.imageTarget
        const dx = event.clientX - this.#swipeStartX

        img.style.transform = ''
        img.style.transition = ''
        img.style.cursor = ''

        if (Math.abs(dx) > 40) {
            this.#didSwipe = true
            if (dx < 0) {
                this.next()
            } else {
                this.prev()
            }
        }
    }

    // --- Rendering ---

    #render(direction) {
        const photo = this.#photos[this.#currentIndex]
        if (!photo) return

        const img = this.imageTarget
        const slideOut = direction === 'left' ? 'translate-x-6' : direction === 'right' ? '-translate-x-6' : 'translate-x-4'
        const slideIn = direction === 'left' ? '-translate-x-6' : direction === 'right' ? 'translate-x-6' : '-translate-x-4'

        // Slide out
        img.classList.add('opacity-0', slideOut)

        setTimeout(() => {
            img.src = photo.url + '?w=1400&fit=max&auto=format&fm=webp&q=85'
            img.alt = photo.alt || ''

            // Jump to opposite side
            img.classList.remove(slideOut)
            img.classList.add(slideIn)

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    // Slide in
                    img.classList.remove('opacity-0', slideIn)
                })
            })
        }, 150)

        this.counterTarget.textContent = `${this.#currentIndex + 1} / ${this.#photos.length}`
        this.#updateThumbnails()
    }

    #renderThumbnails() {
        const container = this.thumbnailsTarget
        container.innerHTML = ''

        this.#photos.forEach((photo, i) => {
            const btn = document.createElement('button')
            btn.type = 'button'
            btn.dataset.action = 'gallery#goTo'
            btn.dataset.galleryIndexParam = i
            btn.className = 'shrink-0 rounded-lg overflow-hidden cursor-pointer ring-2 ring-transparent transition-all duration-200 focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none'

            const img = document.createElement('img')
            img.src = photo.url + '?w=120&h=80&fit=crop&auto=format&fm=webp&q=70'
            img.alt = photo.alt || ''
            img.width = 120
            img.height = 80
            img.draggable = false
            img.className = 'w-full aspect-3/2 object-cover select-none pointer-events-none'
            img.loading = 'lazy'

            btn.appendChild(img)
            container.appendChild(btn)
        })
    }

    #updateThumbnails() {
        const buttons = this.thumbnailsTarget.querySelectorAll('button')
        buttons.forEach((btn, i) => {
            const isActive = i === this.#currentIndex
            btn.classList.toggle('ring-white', isActive)
            btn.classList.toggle('ring-transparent', !isActive)
            btn.classList.toggle('opacity-100', isActive)
            btn.classList.toggle('opacity-60', !isActive)
        })

        const activeBtn = buttons[this.#currentIndex]
        if (activeBtn) {
            activeBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' })
        }
    }
}
