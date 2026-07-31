/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

// Fades its element out right before a live action removes it for real,
// so deletions don't feel abrupt (the re-render drops the node ~200ms later).
export default class extends Controller {
    run() {
        this.element.style.transition = 'opacity 0.2s ease-out, transform 0.2s ease-out'
        this.element.style.opacity = '0'
        this.element.style.transform = 'translateY(3px)'
    }
}
