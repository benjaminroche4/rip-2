/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Counts down to a deadline (ISO 8601 in the `deadline` value) and swaps to
 * the overdue element when it passes:
 *
 *   <div data-controller="countdown" data-countdown-deadline-value="2026-07-30T15:30:00+02:00">
 *       <span data-countdown-target="running"><span data-countdown-target="remaining">--:--</span></span>
 *       <span data-countdown-target="overdue" hidden>...</span>
 *   </div>
 */
export default class extends Controller {
    static targets = ['running', 'overdue', 'remaining'];
    static values = { deadline: String };

    connect() {
        this.tick();
        this.interval = setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        clearInterval(this.interval);
    }

    tick() {
        const remainingSeconds = Math.floor((new Date(this.deadlineValue).getTime() - Date.now()) / 1000);

        if (remainingSeconds <= 0) {
            this.runningTarget.hidden = true;
            this.overdueTarget.hidden = false;
            clearInterval(this.interval);

            return;
        }

        const minutes = String(Math.floor(remainingSeconds / 60)).padStart(2, '0');
        const seconds = String(remainingSeconds % 60).padStart(2, '0');
        this.remainingTarget.textContent = `${minutes}:${seconds}`;
    }
}
