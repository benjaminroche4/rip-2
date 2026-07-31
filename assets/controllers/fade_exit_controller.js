/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

// Fades its element out right before a live action removes it for real,
// so deletions don't feel abrupt (the re-render drops the node ~200ms later).
//
// The inline styles are cleared as soon as the live re-render lands: the
// morph can recycle this node for ANOTHER row, and the live mutation
// tracker re-applies external styles after morphing — without the cleanup
// the recycled row would stay invisible (blank gap in the thread).
export default class extends Controller {
    run() {
        const el = this.element
        el.style.transition = 'opacity 0.2s ease-out, transform 0.2s ease-out'
        el.style.opacity = '0'
        el.style.transform = 'translateY(3px)'

        document.addEventListener('live:render', () => {
            el.style.transition = ''
            el.style.opacity = ''
            el.style.transform = ''
        }, { once: true, capture: true })
    }
}
