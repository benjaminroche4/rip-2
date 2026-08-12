/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

// Sidebar trigger of the global search: the palette itself lives at the
// end of the layout (out of the sticky aside's stacking context), so the
// button just broadcasts an event the palette listens to.
export default class extends Controller {
    open() {
        window.dispatchEvent(new CustomEvent('global-search:open'))
    }
}
