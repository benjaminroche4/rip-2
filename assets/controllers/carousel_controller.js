/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['track', 'dot', 'prev', 'next']

    // #pos is the slide position inside the (cloned) track:
    //   0            -> clone of the last slide
    //   1..realCount -> the real slides
    //   realCount+1  -> clone of the first slide
    // Resting on a clone is never permanent: we snap back on transitionend.
    #pos = 0
    #realCount = 0
    #looped = false

    #startX = 0
    #startY = 0
    #currentX = 0
    #dragging = false
    #startTime = 0
    #isHorizontalSwipe = null
    #didSwipe = false

    connect() {
        this.#realCount = this.trackTarget.children.length
        this.#looped = this.#realCount > 1

        if (this.#looped) {
            const slides = Array.from(this.trackTarget.children)
            const firstClone = slides[0].cloneNode(true)
            const lastClone = slides[slides.length - 1].cloneNode(true)
            firstClone.setAttribute('aria-hidden', 'true')
            lastClone.setAttribute('aria-hidden', 'true')
            this.trackTarget.appendChild(firstClone)
            this.trackTarget.insertBefore(lastClone, this.trackTarget.firstChild)
            this.#jumpTo(1)
        }

        this.trackTarget.style.touchAction = 'pan-y'
        this.trackTarget.addEventListener('pointerdown', this.#onPointerDown)
        this.trackTarget.addEventListener('transitionend', this.#onTransitionEnd)
        window.addEventListener('pointermove', this.#onPointerMove)
        window.addEventListener('pointerup', this.#onPointerUp)
        window.addEventListener('pointercancel', this.#onPointerUp)

        // Swallow the click that fires at the end of a swipe so the parent
        // <a> doesn't navigate when the user was just dragging the carousel.
        this.element.addEventListener('click', this.#onClickCapture, true)
    }

    disconnect() {
        this.trackTarget.removeEventListener('pointerdown', this.#onPointerDown)
        this.trackTarget.removeEventListener('transitionend', this.#onTransitionEnd)
        window.removeEventListener('pointermove', this.#onPointerMove)
        window.removeEventListener('pointerup', this.#onPointerUp)
        window.removeEventListener('pointercancel', this.#onPointerUp)
        this.element.removeEventListener('click', this.#onClickCapture, true)
    }

    #onClickCapture = (e) => {
        if (this.#didSwipe) {
            e.preventDefault()
            e.stopPropagation()
            this.#didSwipe = false
        }
    }

    get #logicalIndex() {
        if (!this.#looped) return 0
        return ((this.#pos - 1) + this.#realCount) % this.#realCount
    }

    goTo(event) {
        if (!this.#looped) return
        const index = parseInt(event.currentTarget.dataset.index, 10)
        this.#normalize()
        this.#slideTo(index + 1)
    }

    // Arrows live inside the card's <a>, so block the click from navigating.
    next(event) {
        event.preventDefault()
        event.stopPropagation()
        if (!this.#looped) return
        this.#normalize()
        this.#slideTo(this.#pos + 1)
    }

    prev(event) {
        event.preventDefault()
        event.stopPropagation()
        if (!this.#looped) return
        this.#normalize()
        this.#slideTo(this.#pos - 1)
    }

    // Snap (no transition) before a new move if we're still resting on a clone
    // (e.g. rapid clicks before transitionend fired).
    #normalize() {
        if (this.#pos === 0) this.#jumpTo(this.#realCount)
        else if (this.#pos === this.#realCount + 1) this.#jumpTo(1)
    }

    #jumpTo(pos) {
        this.#pos = pos
        this.trackTarget.style.transition = 'none'
        this.trackTarget.style.transform = `translateX(-${pos * 100}%)`
        // Force reflow so the next transition starts from this position.
        void this.trackTarget.offsetHeight
        this.#updateDots()
    }

    #slideTo(pos) {
        this.#pos = pos
        this.trackTarget.style.transition = 'transform 300ms ease-out'
        this.trackTarget.style.transform = `translateX(-${pos * 100}%)`
        this.#updateDots()
    }

    #onTransitionEnd = (e) => {
        if (e.target !== this.trackTarget || e.propertyName !== 'transform') return
        if (this.#pos === 0) this.#jumpTo(this.#realCount)
        else if (this.#pos === this.#realCount + 1) this.#jumpTo(1)
    }

    #updateDots() {
        const active = this.#logicalIndex
        this.dotTargets.forEach((dot, i) => {
            dot.classList.toggle('bg-white', i === active)
            dot.classList.toggle('bg-white/50', i !== active)
        })
    }

    #onPointerDown = (e) => {
        if (e.pointerType === 'mouse' && e.button !== 0) return
        if (!this.#looped) return

        this.#normalize()
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

        const base = -this.#pos * this.trackTarget.offsetWidth
        this.trackTarget.style.transform = `translateX(${base + diffX}px)`
    }

    #onPointerUp = () => {
        if (!this.#dragging) return
        this.#dragging = false

        // Restore touch-action for next interaction
        this.trackTarget.style.touchAction = 'pan-y'

        const diff = this.#currentX - this.#startX
        const elapsed = Date.now() - this.#startTime
        const velocity = Math.abs(diff) / elapsed

        // Any movement past ~6px while swiping horizontally counts as a drag,
        // not a click — block the ensuing click from navigating the card link.
        if (this.#isHorizontalSwipe && Math.abs(diff) > 6) {
            this.#didSwipe = true
        }

        // Swipe threshold: either 15% of width OR fast flick (velocity > 0.3px/ms)
        const threshold = this.trackTarget.offsetWidth * 0.15
        const shouldAdvance = Math.abs(diff) > threshold || velocity > 0.3

        if (shouldAdvance && diff < 0) {
            this.#slideTo(this.#pos + 1)
        } else if (shouldAdvance && diff > 0) {
            this.#slideTo(this.#pos - 1)
        } else {
            this.#slideTo(this.#pos)
        }

        this.#isHorizontalSwipe = null
    }
}
