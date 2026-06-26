/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Budget range slider: keeps the value bubble and the filled track in sync with
 * the native range input. The thumb fill is exposed as a `--fill` CSS variable
 * consumed by `.budget-slider` in app.css. The input itself carries the
 * LiveComponent `data-model`, so this controller only handles presentation.
 */
export default class extends Controller {
    static targets = ['input', 'output']
    static values = { locale: String, floor: Number }

    connect() {
        this.update()
    }

    update() {
        if (!this.hasInputTarget) return
        const input = this.inputTarget
        const value = Number(input.value)
        const min = Number(input.min)
        const max = Number(input.max)
        const pct = max > min ? ((value - min) / (max - min)) * 100 : 0
        input.style.setProperty('--fill', `${pct}%`)

        if (this.hasOutputTarget) {
            const locale = this.localeValue || 'en'
            const suffix = value >= max ? '+' : ''
            this.outputTarget.textContent = `€${value.toLocaleString(locale)}${suffix}`
        }
    }
}
