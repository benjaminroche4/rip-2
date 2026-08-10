/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Submits the form it is attached to. Used by file inputs that upload as
 * soon as a file is picked (e.g. the admin avatar upload).
 */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
