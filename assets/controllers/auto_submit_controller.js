/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Submits the form it sits on as soon as a bound input changes: one-gesture
 * uploads (pick a file, the POST leaves immediately, Turbo follows the
 * redirect). Usage: data-controller="auto-submit" on the <form> +
 * data-action="change->auto-submit#submit" on the input.
 */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
