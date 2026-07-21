/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = [
        'section', 'content', 'badge', 'badgeNumber', 'badgeCheck', 'field', 'fill',
        'submit', 'submitButton', 'submitLabel', 'submitSpinner', 'alert',
        'title', 'mobileLabel', 'mobileFill', 'recap', 'recapCheck',
    ]
    static classes = ['badgeActive', 'badgeDone', 'badgeIdle', 'muted']
    static values = { recapLabel: String, recapDoneLabel: String }

    #clicked = null
    // Sections we already auto-advanced from: re-clicking chips in a section
    // that stayed complete must not yank the user forward again. Re-armed
    // when the section becomes incomplete again.
    #advancedFrom = new Set()

    connect() {
        this.boundResize = () => this.update()
        window.addEventListener('resize', this.boundResize)
        this.update()
        this.#revealFirstError()
    }

    // change = a committed edit (a picked chip, a text field left). If it just
    // completed its section, move on to the next one right away instead of
    // waiting for a click outside.
    onChange(event) {
        const section = Number(event.target?.dataset?.listingStepsSection ?? NaN)
        if (!Number.isNaN(section)) this.#maybeAdvance(section)
        this.update()
    }

    #maybeAdvance(section) {
        const next = section + 1
        if (next >= this.sectionTargets.length) return
        if (!this.#isComplete(section)) return
        if (this.#advancedFrom.has(section)) return
        // Only advance along the natural flow: re-editing an already complete
        // section later must not yank the user forward.
        const firstIncomplete = this.sectionTargets.findIndex((_, index) => !this.#isComplete(index))
        if (firstIncomplete !== next) return

        this.#advancedFrom.add(section)
        if (this.sectionTargets[section].contains(document.activeElement)) document.activeElement.blur()
        this.#clicked = next
        this.sectionTargets[next].scrollIntoView({ behavior: this.#scrollBehavior(), block: 'start' })
    }

    #scrollBehavior() {
        return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
    }

    // Clicking anywhere inside a section re-activates it, even without a
    // focusable element under the cursor (title, completed chips zone...).
    onClick(event) {
        const index = this.sectionTargets.findIndex((section) => section.contains(event.target))
        this.#clicked = index !== -1 ? index : null
        this.update()
    }

    disconnect() {
        window.removeEventListener('resize', this.boundResize)
    }

    update() {
        // Sections after the first incomplete one are locked: no click, no
        // focus, no typing (inert) until the previous steps are done.
        const firstIncomplete = this.sectionTargets.findIndex((_, index) => !this.#isComplete(index))
        const lockFrom = firstIncomplete === -1 ? this.sectionTargets.length : firstIncomplete
        const active = this.#activeIndex(lockFrom)

        this.sectionTargets.forEach((section, index) => {
            const complete = this.#isComplete(index)
            const isActive = index === active

            let locked = index > lockFrom
            // The last section is fully optional (photos, note): it opens as
            // soon as the previous section's unlock field (rent) holds a
            // value, so the user can add photos while finishing charges and
            // deposit. The submit gate still requires the full section.
            if (locked && index === this.sectionTargets.length - 1 && lockFrom === index - 1 && this.#unlockSatisfied()) {
                locked = false
            }
            section.toggleAttribute('inert', locked)
            // Dim the content only: the badge must keep its solid white
            // background to mask the track line running behind it.
            if (this.contentTargets[index]) {
                this.contentTargets[index].classList.toggle(this.mutedClass, !isActive && !complete)
            }

            const badge = this.badgeTargets[index]
            if (!badge) return
            badge.classList.remove(...this.badgeActiveClasses, ...this.badgeDoneClasses, ...this.badgeIdleClasses)
            badge.classList.add(...(isActive ? this.badgeActiveClasses : complete ? this.badgeDoneClasses : this.badgeIdleClasses))

            // The check replaces the number as soon as the section is
            // complete, active or not.
            if (this.badgeNumberTargets[index]) this.badgeNumberTargets[index].classList.toggle('hidden', complete)
            if (this.badgeCheckTargets[index]) this.badgeCheckTargets[index].classList.toggle('hidden', !complete)
        })

        if (this.hasSubmitTarget) {
            this.submitTarget.classList.toggle(this.mutedClass, !this.#requiredComplete())
        }

        this.#drawFill(active)
        this.#drawMobileBar(active)
        // Re-arm auto-advance for sections that dropped back to incomplete.
        this.sectionTargets.forEach((_, index) => {
            if (!this.#isComplete(index)) this.#advancedFrom.delete(index)
        })
    }

    // Compact sticky recap for mobile, where the vertical stepper scrolls out
    // of view: "2/4 · Conditions de location" plus a completion bar. Also
    // feeds the recap line above the submit button.
    #drawMobileBar(active) {
        const total = this.sectionTargets.length
        const done = this.sectionTargets.filter((_, index) => this.#isComplete(index)).length
        if (this.hasMobileLabelTarget) {
            const title = this.titleTargets[active]?.textContent.trim() ?? ''
            this.mobileLabelTarget.textContent = `${active + 1}/${total} · ${title}`
        }
        if (this.hasMobileFillTarget) {
            this.mobileFillTarget.style.width = `${Math.round((done / total) * 100)}%`
        }
        if (this.hasRecapTarget) {
            const complete = done === total
            this.recapTarget.textContent = complete
                ? this.recapDoneLabelValue
                : this.recapLabelValue.replace('%done%', String(done)).replace('%total%', String(total))
            if (this.hasRecapCheckTarget) this.recapCheckTarget.classList.toggle('hidden', !complete)
        }
    }

    // Client gate before the native (non-Turbo) submit: an invalid submission
    // would reload the page and lose the selected photos, so block it early
    // and point at what is missing. Server validation stays authoritative.
    onSubmit(event) {
        if (!this.#requiredComplete()) {
            event.preventDefault()
            this.update()

            const section = this.sectionTargets.find((_, index) => !this.#isComplete(index))
            if (section) section.scrollIntoView({ behavior: 'smooth', block: 'center' })
            const field = this.#firstInvalidField()
            if (field) field.focus({ preventScroll: true })

            return
        }

        if (this.hasSubmitButtonTarget) this.submitButtonTarget.disabled = true
        if (this.hasSubmitLabelTarget) this.submitLabelTarget.classList.add('hidden')
        if (this.hasSubmitSpinnerTarget) {
            this.submitSpinnerTarget.classList.remove('hidden')
            this.submitSpinnerTarget.classList.add('inline-flex')
        }
    }

    // Server-side validation failed: bring the first error into view.
    #revealFirstError() {
        if (this.alertTargets.length === 0) return

        this.alertTargets[0].scrollIntoView({ behavior: 'smooth', block: 'center' })
        const field = this.#firstInvalidField()
        if (field) field.focus({ preventScroll: true })
    }

    #firstInvalidField() {
        return this.fieldTargets.find((field) => {
            if (field.disabled) return false
            if (field.type === 'file' || field.type === 'radio' || field.type === 'checkbox') return false

            return field.value.trim() === '' || !field.checkValidity()
        })
    }

    // Every section except the last (photos, optional) must be complete.
    #requiredComplete() {
        return this.sectionTargets.every((_, index) => index === this.sectionTargets.length - 1 || this.#isComplete(index))
    }

    // The vertical track fills down to the active badge.
    #drawFill(active) {
        if (!this.hasFillTarget) return
        const badge = this.badgeTargets[active]
        if (!badge) return

        const wrapper = this.fillTarget.offsetParent
        if (!wrapper) return
        const height = badge.getBoundingClientRect().top - wrapper.getBoundingClientRect().top - this.fillTarget.offsetTop + 4

        this.fillTarget.style.height = `${Math.max(height, 0)}px`
    }

    // The active step is the completion frontier (first incomplete section).
    // Focus or clicks only move it FORWARD (e.g. the early-unlocked photos
    // section): editing an earlier, still complete section must not shift
    // the stepper. If that edit breaks the section, it becomes the frontier
    // itself and the stepper comes back to it.
    #activeIndex(lockFrom) {
        const focused = this.sectionTargets.findIndex((section) => section.contains(document.activeElement))
        if (focused !== -1 && focused >= lockFrom) return focused

        if (this.#clicked !== null && this.#clicked >= lockFrom && this.#clicked < this.sectionTargets.length) {
            return this.#clicked
        }

        return Math.min(lockFrom, this.sectionTargets.length - 1)
    }

    // Fields tagged data-listing-steps-unlocks-next: once every one of them
    // is filled (or disabled, e.g. Airbnb-only financials), the next section
    // opens early.
    #unlockSatisfied() {
        const fields = this.fieldTargets.filter((field) => 'listingStepsUnlocksNext' in field.dataset)
        if (fields.length === 0) return false

        return fields.every((field) => field.disabled || ('' !== field.value.trim() && field.checkValidity()))
    }

    #isComplete(index) {
        // Disabled fields are intentionally out of the flow (e.g. financials
        // hidden for an Airbnb-only project).
        const fields = this.fieldTargets.filter(
            (field) => field.dataset.listingStepsSection === String(index) && !field.disabled,
        )
        if (fields.length === 0) return true

        return fields.every((field) => {
            if (field.type === 'file') return field.files && field.files.length > 0
            if (field.type === 'radio' || field.type === 'checkbox') {
                return fields.some((box) => box.name === field.name && box.checked)
            }

            return field.value.trim() !== '' && field.checkValidity()
        })
    }
}
