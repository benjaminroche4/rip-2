/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Custom address autocomplete shared by every Google Places address field. It uses
 * the Places Autocomplete *Data* API (`AutocompleteSuggestion.fetchAutocompleteSuggestions`)
 * to fetch predictions and renders its own on-brand dropdown (full design control),
 * instead of Google's non-customizable `.pac-container`.
 *
 * Performance: the Places library is reused from the map bootstrap (no extra script),
 * requests are debounced, grouped under a session token, and stale responses are
 * dropped. Results are capped. The controller stays lazy.
 *
 * Targets:
 *  - input    (required) the text field
 *  - results  (required) an empty <ul> (hidden) that receives the options
 *  - trigger  (optional) an element wired to a LiveAction; on select it receives
 *             data-live-lat/lng/arrondissement params and a `places-autocomplete:select` event.
 *
 * Values:
 *  - setInput (Boolean, default true) write the chosen address back into the input.
 */
const PARIS_BOUNDS = { north: 48.902145, south: 48.815573, east: 2.46992, west: 2.224199 }
const MIN_LENGTH = 3
const DEBOUNCE_MS = 280
const MAX_RESULTS = 5

export default class extends Controller {
    static targets = ['input', 'results', 'trigger']
    static values = {
        setInput: { type: Boolean, default: true },
        // Optional: when the page has no Google Maps script yet (e.g. the estimation page),
        // the controller injects this URL on first focus instead of loading Maps eagerly.
        scriptUrl: { type: String, default: '' },
    }

    #suggestions = []
    #activeIndex = -1
    #latestQuery = ''
    #timer = null
    #token = null
    #autocompleteService = null
    #placesService = null
    #SessionToken = null
    #bounds = null
    #onFirstFocus = null

    connect() {
        document.addEventListener('click', this.#onOutsideClick)
        // Defer Google Places (and its script) until the user actually focuses the field:
        // avoids loading the heavy Maps API on every page view.
        this.#onFirstFocus = () => this.#initPlaces()
        this.inputTarget.addEventListener('focus', this.#onFirstFocus, { once: true })
    }

    disconnect() {
        clearTimeout(this.#timer)
        document.removeEventListener('click', this.#onOutsideClick)
        if (this.#onFirstFocus) this.inputTarget.removeEventListener('focus', this.#onFirstFocus)
    }

    async #initPlaces() {
        if (this.#autocompleteService) return
        try {
            const places = await this.#waitForPlaces()
            this.#autocompleteService = new places.AutocompleteService()
            // PlacesService needs a host node; a detached div keeps it out of the DOM.
            this.#placesService = new places.PlacesService(document.createElement('div'))
            this.#SessionToken = places.AutocompleteSessionToken
            this.#bounds = new google.maps.LatLngBounds(
                { lat: PARIS_BOUNDS.south, lng: PARIS_BOUNDS.west },
                { lat: PARIS_BOUNDS.north, lng: PARIS_BOUNDS.east },
            )
            this.#renewToken()
        } catch {
            // Places unavailable: the field stays a plain text input.
        }
    }

    /* ---- input events (wired via data-action) ---- */

    search() {
        clearTimeout(this.#timer)
        const query = this.inputTarget.value.trim()
        if (query.length < MIN_LENGTH || !this.#autocompleteService) {
            this.#clear()
            return
        }
        this.#timer = setTimeout(() => this.#fetch(query), DEBOUNCE_MS)
    }

    navigate(event) {
        if (0 === this.#suggestions.length) return
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault()
                this.#setActive((this.#activeIndex + 1) % this.#suggestions.length)
                break
            case 'ArrowUp':
                event.preventDefault()
                this.#setActive((this.#activeIndex - 1 + this.#suggestions.length) % this.#suggestions.length)
                break
            case 'Enter':
                if (this.#activeIndex >= 0) {
                    // Consume Enter so it doesn't also trigger the field's other action (search).
                    event.preventDefault()
                    event.stopImmediatePropagation()
                    this.#select(this.#suggestions[this.#activeIndex])
                }
                break
            case 'Escape':
                this.#clear()
                break
        }
    }

    /* ---- option events (wired on the rendered <li>) ---- */

    choose(event) {
        const index = Number(event.currentTarget.dataset.index)
        this.#select(this.#suggestions[index])
    }

    highlight(event) {
        this.#setActive(Number(event.currentTarget.dataset.index))
    }

    /* ---- internals ---- */

    #fetch(query) {
        this.#latestQuery = query
        this.#autocompleteService.getPlacePredictions(
            {
                input: query,
                sessionToken: this.#token,
                bounds: this.#bounds, // biases results towards Paris
                componentRestrictions: { country: 'fr' },
                types: ['geocode'], // addresses and areas only (no businesses/POIs)
            },
            (predictions, status) => {
                // Drop stale responses (user kept typing).
                if (this.#latestQuery !== query) return
                // The service only biases (not restricts); keep Paris results only,
                // matching both usages (Paris-scoped) and the 750xx arrondissement logic.
                const paris = 'OK' === status && predictions ? predictions.filter((p) => this.#isParis(p)) : []
                this.#suggestions = paris.slice(0, MAX_RESULTS)
                this.#render()
            },
        )
    }

    #render() {
        if (!this.hasResultsTarget) return
        const ul = this.resultsTarget
        ul.replaceChildren()
        this.#activeIndex = -1

        if (0 === this.#suggestions.length) {
            this.#close()
            return
        }

        this.#suggestions.forEach((prediction, index) => {
            const li = document.createElement('li')
            li.id = `${this.#optionIdPrefix()}-${index}`
            li.setAttribute('role', 'option')
            li.dataset.index = String(index)
            li.setAttribute('data-action', 'click->places-autocomplete#choose mousemove->places-autocomplete#highlight')
            li.className =
                'flex items-start gap-2 px-3 py-2 rounded-lg cursor-pointer text-sm text-gray-700 transition-colors duration-100 aria-selected:bg-slate-50'

            const pin = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
            pin.setAttribute('viewBox', '0 0 24 24')
            pin.setAttribute('fill', 'none')
            pin.setAttribute('stroke', 'currentColor')
            pin.setAttribute('stroke-width', '1.7')
            pin.setAttribute('class', 'size-4 mt-0.5 shrink-0 text-gray-400')
            pin.innerHTML = '<path d="M12 21s7-5.3 7-11a7 7 0 1 0-14 0c0 5.7 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/>'

            const text = document.createElement('span')
            text.className = 'min-w-0 flex-1 truncate'
            const formatting = prediction.structured_formatting
            const main = document.createElement('span')
            main.className = 'text-gray-900'
            this.#appendHighlighted(
                main,
                formatting?.main_text ?? prediction.description ?? '',
                formatting?.main_text_matched_substrings ?? [],
            )
            text.append(main)
            const secondary = formatting?.secondary_text
            if (secondary) {
                const sec = document.createElement('span')
                sec.className = 'text-gray-400'
                sec.textContent = ` ${secondary}`
                text.append(sec)
            }

            li.append(pin, text)
            ul.append(li)
        })

        this.#open()
    }

    /** Bold the characters the user typed, using Google's matched substrings (offset/length). */
    #appendHighlighted(parent, value, matches) {
        if (!matches || 0 === matches.length) {
            parent.textContent = value
            return
        }
        let cursor = 0
        for (const { offset, length } of matches) {
            if (offset > cursor) parent.append(document.createTextNode(value.slice(cursor, offset)))
            const strong = document.createElement('strong')
            strong.className = 'font-semibold text-gray-900'
            strong.textContent = value.slice(offset, offset + length)
            parent.append(strong)
            cursor = offset + length
        }
        if (cursor < value.length) parent.append(document.createTextNode(value.slice(cursor)))
    }

    #select(prediction) {
        if (!prediction) return
        this.#placesService.getDetails(
            {
                placeId: prediction.place_id,
                fields: ['geometry', 'address_components', 'formatted_address'],
                sessionToken: this.#token,
            },
            (place, status) => {
                if ('OK' !== status || !place) {
                    this.#clear()
                    return
                }

                if (this.setInputValue) {
                    this.inputTarget.value = place.formatted_address ?? prediction.description ?? ''
                    this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }))
                }

                const location = place.geometry?.location
                const arrondissement = this.#arrondissementFrom(place.address_components)

                if (this.hasTriggerTarget && location) {
                    this.triggerTarget.dataset.liveLatParam = String(location.lat())
                    this.triggerTarget.dataset.liveLngParam = String(location.lng())
                    this.triggerTarget.dataset.liveArrondissementParam = String(arrondissement)
                    this.triggerTarget.dispatchEvent(new CustomEvent('places-autocomplete:select'))
                }

                this.dispatch('select', { detail: { place, arrondissement } })

                this.#renewToken() // a selection ends the billing session
                this.#clear()
            },
        )
    }

    /** Keep only Paris predictions (the service biases but cannot strictly restrict). */
    #isParis(prediction) {
        const text = prediction.description ?? ''
        return /\bparis\b/i.test(text) || /\b75\d{3}\b/.test(text)
    }

    /** Paris postal code -> arrondissement (75001->1 … 75020->20, 75116->16). 0 if none. */
    #arrondissementFrom(components) {
        for (const c of components ?? []) {
            if (c.types?.includes('postal_code')) {
                const code = (c.long_name ?? '').replace(/\s/g, '')
                if (/^75\d{3}$/.test(code)) {
                    const n = parseInt(code.slice(-2), 10)
                    if (n >= 1 && n <= 20) return n
                }
            }
        }
        return 0
    }

    #setActive(index) {
        this.#activeIndex = index
        if (!this.hasResultsTarget) return
        Array.from(this.resultsTarget.children).forEach((li, i) =>
            li.setAttribute('aria-selected', i === index ? 'true' : 'false'),
        )
        this.inputTarget.setAttribute('aria-activedescendant', index >= 0 ? `${this.#optionIdPrefix()}-${index}` : '')
    }

    #open() {
        if (!this.hasResultsTarget) return
        this.resultsTarget.classList.remove('hidden')
        this.inputTarget.setAttribute('aria-expanded', 'true')
    }

    #close() {
        if (!this.hasResultsTarget) return
        this.resultsTarget.classList.add('hidden')
        this.inputTarget.setAttribute('aria-expanded', 'false')
        this.inputTarget.setAttribute('aria-activedescendant', '')
    }

    #clear() {
        clearTimeout(this.#timer)
        this.#suggestions = []
        this.#activeIndex = -1
        if (this.hasResultsTarget) this.resultsTarget.replaceChildren()
        this.#close()
    }

    #renewToken() {
        if (this.#SessionToken) this.#token = new this.#SessionToken()
    }

    #optionIdPrefix() {
        return `pac-${this.identifier}-${this.element.dataset.pacId ?? (this.element.dataset.pacId = String(this.#tokenSeed()))}`
    }

    #tokenSeed() {
        // Stable-ish unique id per controller element without Math.random/Date.
        return (this.inputTarget?.name || this.inputTarget?.id || 'field').replace(/[^a-z0-9]/gi, '')
    }

    #onOutsideClick = (event) => {
        if (!this.element.contains(event.target)) this.#close()
    }

    async #waitForPlaces() {
        // Already available (e.g. marketplace loads Google via the map bootstrap).
        if (window.google?.maps?.importLibrary) return google.maps.importLibrary('places')
        if (window.google?.maps?.places?.AutocompleteService) return google.maps.places
        // Not loaded yet: inject the Maps script on demand (once per page).
        if (this.scriptUrlValue) this.#injectMapsScript(this.scriptUrlValue)
        for (let i = 0; i < 100; i++) {
            if (window.google?.maps?.importLibrary) return google.maps.importLibrary('places')
            if (window.google?.maps?.places?.AutocompleteService) return google.maps.places
            await new Promise((resolve) => setTimeout(resolve, 100))
        }
        throw new Error('Google Maps Places unavailable')
    }

    #injectMapsScript(url) {
        if (document.querySelector('script[data-google-maps]')) return
        const script = document.createElement('script')
        script.src = url
        script.async = true
        script.defer = true
        script.setAttribute('data-google-maps', '')
        document.head.appendChild(script)
    }
}
