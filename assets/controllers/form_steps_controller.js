/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Splits one form into steps, client-side only: a single POST, no server state.
 * Without JS every step stays visible and the form works as a plain long form.
 *
 * Targets:
 * - `step`: the blocks to page through
 * - `question`: a card/select question (`data-name` = field name, `data-required`
 *   when mandatory); its `hint` target is shown when "Continue" finds it empty
 * - `error`: server error messages (the first step holding one opens on connect)
 * - `segment`: progress bar, one per step
 * - `jsOnly`: controls that only make sense with JS (hidden in markup)
 * - `heading`: one per step, focused on step change (tabindex="-1")
 *
 * History: each step is a browser history entry (pushState on the current Turbo
 * entry), so the browser back button and the mobile back gesture go to the
 * previous step. Those entries are handled here before Turbo sees them.
 *
 * Draft: answers and step are saved in localStorage (per visitor convenience,
 * best effort) and restored into empty fields, until a successful submission.
 */
export default class extends Controller {
    static targets = ['step', 'question', 'hint', 'error', 'segment', 'jsOnly', 'heading']
    static classes = ['segmentDone', 'segmentTodo']
    static values = {
        storageKey: String,
        advanceDelay: { type: Number, default: 250 },
        // Never restore the honeypot nor the CSRF token
        skipFields: { type: Array, default: [] },
    }

    static DRAFT_TTL = 30 * 24 * 3600 * 1000

    connect() {
        this.pointer = false
        this.jsOnlyTargets.forEach((element) => element.classList.remove('hidden'))

        const errorStep = this.stepTargets.findIndex((step) => this.errorTargets.some((error) => step.contains(error)))
        const draft = this.#readDraft()
        // Server-rendered values win after a failed submission
        if (errorStep < 0 && draft) {
            this.#restore(draft.fields)
        }

        const state = history.state ?? {}
        this.restorationId = state.turbo?.restorationIdentifier
        const wanted = errorStep >= 0 ? errorStep : (state.formStep ?? draft?.step ?? 0)
        this.#show(this.#reachable(wanted), { history: 'replace' })

        window.addEventListener('popstate', this.#onPopState, true)
        this.element.addEventListener('turbo:submit-end', this.#onSubmitEnd)
    }

    disconnect() {
        clearTimeout(this.advanceTimer)
        clearTimeout(this.saveTimer)
        window.removeEventListener('popstate', this.#onPopState, true)
        this.element.removeEventListener('turbo:submit-end', this.#onSubmitEnd)
    }

    next() {
        const missing = this.#questionsOf(this.current).filter((question) => 'required' in question.dataset && !this.#isAnswered(question))
        this.hintTargets.forEach((hint) => hint.classList.toggle('hidden', !missing.some((question) => question.contains(hint))))
        if (missing.length > 0) {
            missing[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' })

            return
        }

        this.#show(this.current + 1, { history: 'push', focus: true })
    }

    previous() {
        // Our own entry right behind: let the browser go back (keeps history in sync)
        if ((history.state?.formDepth ?? 0) > 0) {
            history.back()

            return
        }

        this.#show(this.current - 1, { history: 'replace', focus: true })
    }

    markPointer() {
        this.pointer = true
    }

    markKeyboard() {
        this.pointer = false
    }

    // change on the form: hide the "required" hint, save, maybe move on
    changed(event) {
        this.questionTargets
            .filter((question) => question.contains(event.target) && this.#isAnswered(question))
            .forEach((question) => this.hintTargets.filter((hint) => question.contains(hint)).forEach((hint) => hint.classList.add('hidden')))

        this.save()
        this.#autoAdvance(event)
    }

    save() {
        clearTimeout(this.saveTimer)
        this.saveTimer = setTimeout(() => this.#writeDraft(), 200)
    }

    #autoAdvance(event) {
        // `data-form-steps-hold` on an answer keeps the visitor on the step (e.g. to read a message it reveals)
        if (!this.pointer || 'radio' !== event.target.type || 'formStepsHold' in event.target.dataset || this.current >= this.stepTargets.length - 1) {
            return
        }
        if (!this.#questionsOf(this.current).every((question) => this.#isAnswered(question))) {
            return
        }

        clearTimeout(this.advanceTimer)
        this.advanceTimer = setTimeout(() => this.next(), this.advanceDelayValue)
    }

    #onPopState = (event) => {
        const state = event.state ?? {}
        if (undefined === state.formStep || state.turbo?.restorationIdentifier !== this.restorationId) {
            return
        }

        // One of our step entries: Turbo must not turn it into a page restore
        event.stopImmediatePropagation()
        this.#show(this.#reachable(state.formStep), { focus: true })
    }

    #onSubmitEnd = (event) => {
        // Success is a redirect to the confirmation; invalid answers come back as a 200 stream
        if (event.detail.success && event.detail.fetchResponse?.redirected) {
            this.#clearDraft()
        }
    }

    #show(index, { history: mode = null, focus = false } = {}) {
        this.current = Math.min(Math.max(index, 0), this.stepTargets.length - 1)

        this.stepTargets.forEach((step, i) => step.classList.toggle('hidden', i !== this.current))
        this.segmentTargets.forEach((segment, i) => {
            const done = i <= this.current
            segment.classList.toggle(this.segmentDoneClass, done)
            segment.classList.toggle(this.segmentTodoClass, !done)
        })

        const state = history.state ?? {}
        if ('push' === mode) {
            history.pushState({ ...state, formStep: this.current, formDepth: (state.formDepth ?? 0) + 1 }, '', window.location.href)
        } else if ('replace' === mode) {
            history.replaceState({ ...state, formStep: this.current, formDepth: state.formDepth ?? 0 }, '', window.location.href)
        }
        this.save()

        // Widgets measured while hidden (intl-tel-input) can recompute now
        this.dispatch('show', { target: window, detail: { step: this.current } })

        if (focus && this.headingTargets[this.current]) {
            const heading = this.headingTargets[this.current]
            heading.focus({ preventScroll: true })
            heading.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
        }
    }

    // Never open a step while an earlier one still misses a required answer
    #reachable(index) {
        for (let i = 0; i < index; i++) {
            if (this.#questionsOf(i).some((question) => 'required' in question.dataset && !this.#isAnswered(question))) {
                return i
            }
        }

        return index
    }

    #questionsOf(index) {
        const step = this.stepTargets[index]

        return step ? this.questionTargets.filter((question) => step.contains(question)) : []
    }

    #isAnswered(question) {
        const item = this.element.elements.namedItem(question.dataset.name)
        // Radio or checkbox group: answered once one is ticked (RadioNodeList.value is empty for checkboxes)
        if (item instanceof RadioNodeList) return Array.from(item).some((input) => input.checked)
        if (item && ('checkbox' === item.type || 'radio' === item.type)) return item.checked

        return '' !== (item?.value ?? '')
    }

    #fields() {
        return Array.from(this.element.elements).filter((field) => field.name && !this.skipFieldsValue.includes(field.name) && 'button' !== field.type && 'submit' !== field.type)
    }

    #writeDraft() {
        const fields = {}
        this.#fields().forEach((field) => {
            if ('radio' === field.type) {
                if (field.checked) fields[field.name] = field.value
            } else if ('checkbox' === field.type) {
                fields[field.name] = field.checked
            } else if ('' !== field.value) {
                fields[field.name] = field.value
            }
        })

        try {
            localStorage.setItem(this.storageKeyValue, JSON.stringify({ savedAt: Date.now(), step: this.current, fields }))
        } catch {
            // Storage unavailable (private mode, quota): the form still works
        }
    }

    #readDraft() {
        try {
            const draft = JSON.parse(localStorage.getItem(this.storageKeyValue) ?? 'null')
            if (draft && Date.now() - draft.savedAt < this.constructor.DRAFT_TTL) {
                return draft
            }
            this.#clearDraft()
        } catch {
            // Unreadable draft: start fresh
        }

        return null
    }

    #clearDraft() {
        clearTimeout(this.saveTimer)
        try {
            localStorage.removeItem(this.storageKeyValue)
        } catch {
            // Nothing to clear
        }
    }

    // Fill only fields the visitor has not answered yet; fire the native events
    // so the custom widgets (dropdown, phone) refresh their display
    #restore(saved) {
        const elements = this.element.elements
        Object.entries(saved ?? {}).forEach(([name, value]) => {
            if (this.skipFieldsValue.includes(name)) return
            const item = elements.namedItem(name)
            if (!item) return

            if (item instanceof RadioNodeList || 'radio' === item.type) {
                const radios = item instanceof RadioNodeList ? Array.from(item) : [item]
                if (radios.some((radio) => radio.checked)) return
                const radio = radios.find((candidate) => candidate.value === value)
                if (!radio) return
                radio.checked = true
                radio.dispatchEvent(new Event('change', { bubbles: true }))
            } else if ('checkbox' === item.type) {
                if (item.checked || true !== value) return
                item.checked = true
                item.dispatchEvent(new Event('change', { bubbles: true }))
            } else if ('' === item.value && 'string' === typeof value) {
                item.value = value
                // A select only accepts one of its options
                if (item.value !== value) return
                item.dispatchEvent(new Event('input', { bubbles: true }))
                item.dispatchEvent(new Event('change', { bubbles: true }))
            }
        })
    }
}
