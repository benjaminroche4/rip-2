/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * One-way "show more": reveals the extra targets (display: contents so they
 * flow inside the surrounding flex/grid container) and hides the trigger.
 * The opening is animated: the container's height eases to its new size
 * while the revealed items fade in with a small stagger.
 */
export default class extends Controller {
    static targets = ['extra', 'button']

    show() {
        const container = this.extraTargets[0]?.parentElement
        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
        const startHeight = container?.getBoundingClientRect().height

        this.extraTargets.forEach((element) => {
            element.classList.remove('hidden')
            element.classList.add('contents')
        })
        if (this.hasButtonTarget) this.buttonTarget.classList.add('hidden')

        if (!container || reduced) return

        // Ease the container from its collapsed to its expanded height.
        const endHeight = container.getBoundingClientRect().height
        if (endHeight !== startHeight) {
            container.style.height = `${startHeight}px`
            container.style.overflow = 'hidden'
            container.getBoundingClientRect() // flush so the transition starts from startHeight
            container.style.transition = 'height 250ms ease-out'
            container.style.height = `${endHeight}px`
            container.addEventListener('transitionend', () => {
                container.style.height = ''
                container.style.overflow = ''
                container.style.transition = ''
            }, { once: true })
        }

        // Fade the revealed items in, slightly staggered.
        const items = this.extraTargets.flatMap((element) => Array.from(element.children))
        items.forEach((item, index) => {
            item.style.opacity = '0'
            item.style.transform = 'translateY(4px)'
            requestAnimationFrame(() => {
                item.style.transition = `opacity 200ms ease-out ${index * 15}ms, transform 200ms ease-out ${index * 15}ms`
                item.style.opacity = ''
                item.style.transform = ''
                item.addEventListener('transitionend', () => {
                    item.style.transition = ''
                }, { once: true })
            })
        })
    }
}
