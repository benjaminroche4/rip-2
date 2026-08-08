/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Submits the form it is attached to as soon as a field changes. Used by the
 * deposit page file inputs: picking a file uploads it immediately, no extra
 * button. requestSubmit() (not submit()) so Turbo intercepts the submission.
 */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
