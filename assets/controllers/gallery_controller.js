/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['dialog', 'image', 'counter', 'thumbnails', 'skeleton', 'photoCount']

    #currentIndex = 0
    #photos = []
    #thumbnailsRendered = false
    #swipeStartX = 0
    #swiping = false
    #didSwipe = false
    #idleCallbackId = null
    #slideTimeoutId = null

    connect() {
        this.#photos = JSON.parse(this.element.dataset.galleryPhotosValue || '[]')
        this.#preloadFullSize()
    }

    disconnect() {
        // The document-level keydown listener and the body scroll lock are set
        // in open() and normally removed in close(); if the controller is torn
        // down while the dialog is open (e.g. Turbo navigation), clean them up.
        document.removeEventListener('keydown', this.#handleKeydown)
        document.body.style.overflow = ''

        if (this.#idleCallbackId !== null) {
            const cancel = window.cancelIdleCallback || clearTimeout
            cancel(this.#idleCallbackId)
            this.#idleCallbackId = null
        }
        if (this.#slideTimeoutId !== null) {
            clearTimeout(this.#slideTimeoutId)
            this.#slideTimeoutId = null
        }
    }

    // Warm the browser cache so the first open() paints instantly.
    // First photo eagerly; the rest deferred to idle time to avoid
    // competing with critical resources.
    #preloadFullSize() {
        if (!this.#photos.length) return

        const urlFor = (photo) => photo.url + '?w=1400&fit=max&auto=format&fm=webp&q=85'
        const preload = (url) => { const i = new Image(); i.src = url }

        preload(urlFor(this.#photos[0]))

        const idle = window.requestIdleCallback || ((cb) => setTimeout(cb, 500))
        this.#idleCallbackId = idle(() => {
            for (let i = 1; i < this.#photos.length; i++) {
                preload(urlFor(this.#photos[i]))
            }
        })
    }

    open({ params: { index } }) {
        this.#currentIndex = index ?? 0
        this.dialogTarget.showModal()
        this.dialogTarget.focus()
        document.body.style.overflow = 'hidden'
        document.addEventListener('keydown', this.#handleKeydown)

        // Carousel setup only if those targets are present
        if (this.hasImageTarget) {
            if (!this.#thumbnailsRendered) {
                this.#renderThumbnails()
                this.#thumbnailsRendered = true
            }
            this.#render()

            const img = this.imageTarget
            img.addEventListener('pointerdown', this.#handlePointerDown)
            img.addEventListener('pointermove', this.#handlePointerMove)
            img.addEventListener('pointerup', this.#handlePointerUp)
            img.addEventListener('pointercancel', this.#handlePointerUp)
            img.addEventListener('load', this.#handleImageLoad)
            img.addEventListener('error', this.#handleImageLoad)
        }
    }

    close() {
        this.#swiping = false
        if (this.#slideTimeoutId !== null) {
            clearTimeout(this.#slideTimeoutId)
            this.#slideTimeoutId = null
        }
        this.dialogTarget.close()
        document.body.style.overflow = ''
        document.removeEventListener('keydown', this.#handleKeydown)

        if (this.hasImageTarget) {
            const img = this.imageTarget
            img.removeEventListener('pointerdown', this.#handlePointerDown)
            img.removeEventListener('pointermove', this.#handlePointerMove)
            img.removeEventListener('pointerup', this.#handlePointerUp)
            img.removeEventListener('pointercancel', this.#handlePointerUp)
            img.removeEventListener('load', this.#handleImageLoad)
            img.removeEventListener('error', this.#handleImageLoad)
        }

        // Blur the trigger button that re-gains focus on dialog close — otherwise
        // browsers render a visible focus ring around the large clickable image area.
        if (document.activeElement instanceof HTMLElement) {
            document.activeElement.blur()
        }
    }

    closeOnClickOutside({ target }) {
        if (this.#didSwipe) {
            this.#didSwipe = false
            return
        }
        if (target === this.dialogTarget) {
            this.close()
        }
    }

    prev() {
        if (!this.#photos.length) return
        this.#currentIndex = (this.#currentIndex - 1 + this.#photos.length) % this.#photos.length
        this.#render('left')
    }

    next() {
        if (!this.#photos.length) return
        this.#currentIndex = (this.#currentIndex + 1) % this.#photos.length
        this.#render('right')
    }

    // Une vignette vient d'être supprimée de la grille (photo-delete:removed) :
    // retire la photo du slider sans recharger la page, recale l'index
    // courant, le compteur affiché et les miniatures du lightbox.
    removePhoto({ detail: { index } }) {
        if (index < 0 || index >= this.#photos.length) return
        this.#photos.splice(index, 1)
        this.#thumbnailsRendered = false

        if (this.hasPhotoCountTarget) {
            this.photoCountTarget.textContent = this.#photos.length
        }

        if (!this.dialogTarget.open) return
        if (!this.#photos.length) {
            this.close()
            return
        }
        if (this.#currentIndex >= this.#photos.length) {
            this.#currentIndex = this.#photos.length - 1
        }
        if (this.hasImageTarget) {
            this.#renderThumbnails()
            this.#thumbnailsRendered = true
            this.#render()
        }
    }

    goTo({ params: { index } }) {
        if (index === this.#currentIndex) return
        const direction = index > this.#currentIndex ? 'right' : 'left'
        this.#currentIndex = index ?? 0
        this.#render(direction)
    }

    #handleKeydown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault()
            this.close()
        } else if (this.hasImageTarget) {
            if (event.key === 'ArrowLeft') {
                event.preventDefault()
                this.prev()
            } else if (event.key === 'ArrowRight') {
                event.preventDefault()
                this.next()
            }
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

    // Photo pas encore décodée : plaque animée à la place de l'image
    // (l'événement load/error de l'image fait la bascule inverse).
    #toggleSkeleton(loading) {
        if (!this.hasSkeletonTarget) return
        this.skeletonTarget.classList.toggle('hidden', !loading)
        this.imageTarget.classList.toggle('hidden', loading)
    }

    #handleImageLoad = () => {
        this.#toggleSkeleton(false)
    }

    #render(direction) {
        const photo = this.#photos[this.#currentIndex]
        if (!photo || !this.hasImageTarget) return

        const img = this.imageTarget
        const src = photo.url + '?w=1400&fit=max&auto=format&fm=webp&q=85'

        // Navigations rapprochées : le timeout d'une slide précédente encore
        // en vol appliquerait une photo périmée par-dessus la nouvelle.
        if (this.#slideTimeoutId !== null) {
            clearTimeout(this.#slideTimeoutId)
            this.#slideTimeoutId = null
        }

        if (direction === undefined) {
            // Initial render (on open): no slide animation, paint ASAP
            img.src = src
            img.alt = photo.alt || ''
            img.classList.remove('opacity-0', 'translate-x-4', '-translate-x-4', 'translate-x-6', '-translate-x-6')
            this.#toggleSkeleton(!img.complete)
        } else {
            // Navigation between photos: slide animation
            const slideOut = direction === 'left' ? 'translate-x-6' : '-translate-x-6'
            const slideIn = direction === 'left' ? '-translate-x-6' : 'translate-x-6'

            img.classList.add('opacity-0', slideOut)

            this.#slideTimeoutId = setTimeout(() => {
                this.#slideTimeoutId = null
                img.src = src
                img.alt = photo.alt || ''

                // Purge les deux sens : une animation interrompue peut avoir
                // laissé la classe de l'autre direction sur l'élément.
                img.classList.remove('translate-x-6', '-translate-x-6')
                img.classList.add(slideIn)
                this.#toggleSkeleton(!img.complete)

                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        img.classList.remove('opacity-0', slideIn)
                    })
                })
            }, 150)
        }

        if (this.hasCounterTarget) {
            this.counterTarget.textContent = `${this.#currentIndex + 1} / ${this.#photos.length}`
        }
        this.#updateThumbnails()
    }

    #renderThumbnails() {
        if (!this.hasThumbnailsTarget) return
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
        if (!this.hasThumbnailsTarget) return
        // querySelectorAll assumé : les vignettes sont générées en JS au vol (DOM dynamique), un target Stimulus déclaratif ne peut pas les couvrir.
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
