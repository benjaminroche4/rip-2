/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * Multi-photo picker. Selections accumulate (a native file input replaces its
 * FileList on every pick, so we keep our own list and write it back through a
 * DataTransfer). Thumbnails render in a grid below the dropzone, which stays
 * visible so more photos can always be added. Tiles can be reordered by drag
 * and drop (desktop) or with the arrow buttons (touch, keyboard); the final
 * order is the order the files are submitted in.
 */
export default class extends Controller {
    static targets = ['input', 'count', 'previews', 'toolbar', 'zone', 'error']
    static classes = ['zoneActive']
    static values = {
        label: String,
        removeLabel: { type: String, default: '' },
        moveLeftLabel: { type: String, default: '' },
        moveRightLabel: { type: String, default: '' },
        maxFiles: { type: Number, default: 0 },
        maxSize: { type: Number, default: 0 },
        errorTooLarge: { type: String, default: '' },
        errorInvalidType: { type: String, default: '' },
        errorTooMany: { type: String, default: '' },
        optimizing: { type: String, default: '' },
    }

    // Above this size, photos are recompressed client-side before being kept.
    static RECOMPRESS_OVER = 2_500_000
    // Longest edge after recompression: plenty for property photos.
    static MAX_EDGE = 2560
    // Formats the browser can decode but the server refuses: always converted.
    static CONVERT_TYPES = ['image/heic', 'image/heif']

    #files = []
    #objectUrls = []
    #dragEl = null
    #queue = Promise.resolve()

    disconnect() {
        this.#revokeUrls()
    }

    zoneActive() {
        if (this.hasZoneTarget) this.zoneTarget.classList.add(...this.zoneActiveClasses)
    }

    zoneIdle() {
        if (this.hasZoneTarget) this.zoneTarget.classList.remove(...this.zoneActiveClasses)
    }

    // change on the input: merge the new selection into what we already hold.
    // Oversized photos are recompressed and iPhone HEIC converted to JPEG on
    // the fly; files the server would still reject (unreadable type, too
    // large after compression, too many) are refused with a message: a
    // full-page reload on a server-side photo error would lose the whole
    // selection. Batches are queued so two quick picks cannot interleave.
    update() {
        const incoming = Array.from(this.inputTarget.files ?? [])
        this.#queue = this.#queue.then(() => this.#ingest(incoming))
    }

    async #ingest(incoming) {
        if (incoming.length > 0 && this.hasToolbarTarget && this.hasCountTarget) {
            this.toolbarTarget.classList.remove('hidden')
            this.countTarget.textContent = this.optimizingValue
        }

        const errors = []
        for (const original of incoming) {
            const file = await this.#normalize(original)
            if (null === file || !this.#accepts(file)) {
                errors.push(this.errorInvalidTypeValue.replace('%name%', original.name))
                continue
            }
            if (this.maxSizeValue > 0 && file.size > this.maxSizeValue) {
                errors.push(this.errorTooLargeValue.replace('%name%', original.name))
                continue
            }
            const known = this.#files.some(
                (existing) => existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified,
            )
            if (!known) this.#files.push(file)
        }

        if (this.maxFilesValue > 0 && this.#files.length > this.maxFilesValue) {
            this.#files.length = this.maxFilesValue
            errors.push(this.errorTooManyValue)
        }

        this.#showErrors([...new Set(errors)])
        this.#sync()
    }

    // Downscale + JPEG re-encode through a canvas. HEIC/HEIF must convert
    // (null on failure: the server cannot read them); other formats fall
    // back to the original file when the browser cannot decode them.
    async #normalize(file) {
        const mustConvert = this.constructor.CONVERT_TYPES.includes(file.type)
        if (!mustConvert && file.size <= this.constructor.RECOMPRESS_OVER) return file

        try {
            const bitmap = await createImageBitmap(file)
            const scale = Math.min(1, this.constructor.MAX_EDGE / Math.max(bitmap.width, bitmap.height))
            const canvas = document.createElement('canvas')
            canvas.width = Math.max(1, Math.round(bitmap.width * scale))
            canvas.height = Math.max(1, Math.round(bitmap.height * scale))
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height)
            bitmap.close()

            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82))
            if (!blob) throw new Error('encoding failed')

            return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', {
                type: 'image/jpeg',
                lastModified: file.lastModified,
            })
        } catch {
            return mustConvert ? null : file
        }
    }

    remove(event) {
        event.preventDefault()
        event.stopPropagation()

        this.#files.splice(Number(event.currentTarget.dataset.index), 1)
        this.#showErrors([])
        this.#sync()
        this.#notify()
    }

    // Arrow buttons: swap with the neighbour. Works on touch and keyboard
    // where HTML5 drag and drop does not. The re-render rebuilds the tiles,
    // so the glide is keyed by file: each new tile starts from the old
    // position of the file it now shows.
    move(event) {
        event.preventDefault()
        event.stopPropagation()

        const from = Number(event.currentTarget.dataset.index)
        const to = from + Number(event.currentTarget.dataset.direction)
        if (to < 0 || to >= this.#files.length) return

        const oldRects = new Map()
        Array.from(this.previewsTarget.children).forEach((tile, index) => {
            oldRects.set(this.#files[index], tile.getBoundingClientRect())
        })

        ;[this.#files[from], this.#files[to]] = [this.#files[to], this.#files[from]]
        this.#sync()
        this.#notify()
        this.#flip((tile, index) => oldRects.get(this.#files[index]))
    }

    /* ---- drag and drop reordering ----
     *
     * Live sort: while dragging, the grabbed tile is re-inserted in the DOM
     * as the cursor crosses its neighbours, and every displaced tile glides
     * to its new slot with a FLIP animation. The file order is committed
     * from the DOM on dragend (which always fires, drop or not), so a
     * cancelled drag simply snaps everything back via re-render.
     */

    dragStart(event) {
        this.#dragEl = event.currentTarget
        event.dataTransfer.effectAllowed = 'move'
        // Deferred so the browser snapshots the un-dimmed tile as drag image.
        requestAnimationFrame(() => this.#dragEl?.classList.add('opacity-40'))
    }

    dragOver(event) {
        // Ignore OS file drags: those belong to the dropzone input.
        if (!this.#dragEl) return
        event.preventDefault()
        event.dataTransfer.dropEffect = 'move'

        const target = event.currentTarget
        if (target === this.#dragEl) return

        // Only re-slot once the cursor passes the middle of the hovered tile,
        // otherwise fast back-and-forth movements make the grid jitter.
        const rect = target.getBoundingClientRect()
        const before = event.clientX - rect.left < rect.width / 2
        const reference = before ? target : target.nextSibling
        if (reference === this.#dragEl || this.#dragEl.nextSibling === reference) return

        const firstRects = this.#captureRects()
        this.previewsTarget.insertBefore(this.#dragEl, reference)
        this.#flip((tile) => (tile === this.#dragEl ? undefined : firstRects.get(tile)))
    }

    dragOverGrid(event) {
        // Gaps between tiles must stay valid drop targets too.
        if (!this.#dragEl) return
        event.preventDefault()
        event.dataTransfer.dropEffect = 'move'
    }

    dropTile(event) {
        if (!this.#dragEl) return
        // The reorder already happened live; just claim the drop so the
        // browser does not animate the ghost flying back.
        event.preventDefault()
        event.stopPropagation()
    }

    dragEnd() {
        if (!this.#dragEl) return
        this.#dragEl.classList.remove('opacity-40')
        // Commit the DOM order (tiles keep their original index) to the list.
        const order = Array.from(this.previewsTarget.children).map((tile) => Number(tile.dataset.index))
        this.#files = order.map((index) => this.#files[index])
        this.#dragEl = null
        this.#sync()
        this.#notify()
    }

    #captureRects() {
        const rects = new Map()
        for (const tile of this.previewsTarget.children) rects.set(tile, tile.getBoundingClientRect())

        return rects
    }

    // FLIP: each tile starts at the old position returned by firstRectFor
    // (inverted transform) and glides to its current slot.
    #flip(firstRectFor) {
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return

        Array.from(this.previewsTarget.children).forEach((tile, index) => {
            const first = firstRectFor(tile, index)
            if (!first) return
            const last = tile.getBoundingClientRect()
            const dx = first.left - last.left
            const dy = first.top - last.top
            if (!dx && !dy) return

            tile.style.transition = 'none'
            tile.style.transform = `translate(${dx}px, ${dy}px)`
            requestAnimationFrame(() => {
                tile.style.transition = 'transform 200ms ease'
                tile.style.transform = ''
                tile.addEventListener('transitionend', () => {
                    tile.style.transition = ''
                }, { once: true })
            })
        })
    }

    /* ---- internals ---- */

    // Re-notify the steps controller (bound to input/change on the form).
    #notify() {
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }))
    }

    #sync() {
        const transfer = new DataTransfer()
        this.#files.forEach((file) => transfer.items.add(file))
        this.inputTarget.files = transfer.files

        const hasFiles = this.#files.length > 0
        if (this.hasToolbarTarget) this.toolbarTarget.classList.toggle('hidden', !hasFiles)
        if (this.hasCountTarget) {
            this.countTarget.textContent = hasFiles
                ? this.labelValue
                      .replace('%count%', String(this.#files.length))
                      .replace('%max%', String(this.maxFilesValue || this.#files.length))
                : ''
        }

        this.#renderPreviews()
    }

    #renderPreviews() {
        if (!this.hasPreviewsTarget) return

        this.#revokeUrls()
        this.previewsTarget.replaceChildren()
        this.previewsTarget.classList.toggle('hidden', this.#files.length === 0)
        this.previewsTarget.classList.toggle('grid', this.#files.length > 0)

        this.#files.forEach((file, index) => {
            const url = URL.createObjectURL(file)
            this.#objectUrls.push(url)

            const tile = document.createElement('div')
            tile.className = 'group/tile relative cursor-grab active:cursor-grabbing'
            tile.draggable = true
            tile.dataset.index = String(index)
            tile.dataset.action = 'dragstart->file-drop#dragStart dragover->file-drop#dragOver drop->file-drop#dropTile dragend->file-drop#dragEnd'

            const img = document.createElement('img')
            img.src = url
            img.alt = file.name
            img.width = 96
            img.height = 80
            img.draggable = false
            img.className = 'h-20 w-full rounded-lg object-cover ring-1 ring-black/5 pointer-events-none'
            tile.appendChild(img)

            tile.appendChild(this.#tileButton({
                index,
                label: `${this.removeLabelValue} ${file.name}`.trim(),
                action: 'file-drop#remove',
                className: 'absolute -right-1.5 -top-1.5 z-20 flex size-6 cursor-pointer items-center justify-center rounded-full bg-white text-gray-500 shadow ring-1 ring-black/10 transition-colors duration-100 ease-out hover:text-red-600',
                svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3.5" aria-hidden="true"><path d="M6 18 18 6M6 6l12 12" stroke-linecap="round"/></svg>',
            }))

            if (this.#files.length > 1) {
                if (index > 0) {
                    tile.appendChild(this.#tileButton({
                        index,
                        direction: -1,
                        label: this.moveLeftLabelValue,
                        action: 'file-drop#move',
                        className: 'absolute bottom-1 left-1 z-20 flex size-5 cursor-pointer items-center justify-center rounded-full bg-white/90 text-gray-600 shadow-sm ring-1 ring-black/10 transition-colors duration-100 ease-out hover:text-primary',
                        svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3" aria-hidden="true"><path d="m15 18-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    }))
                }
                if (index < this.#files.length - 1) {
                    tile.appendChild(this.#tileButton({
                        index,
                        direction: 1,
                        label: this.moveRightLabelValue,
                        action: 'file-drop#move',
                        className: 'absolute bottom-1 right-1 z-20 flex size-5 cursor-pointer items-center justify-center rounded-full bg-white/90 text-gray-600 shadow-sm ring-1 ring-black/10 transition-colors duration-100 ease-out hover:text-primary',
                        svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3" aria-hidden="true"><path d="m9 18 6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    }))
                }
            }

            this.previewsTarget.appendChild(tile)
        })
    }

    #tileButton({ index, direction, label, action, className, svg }) {
        const button = document.createElement('button')
        button.type = 'button'
        button.dataset.index = String(index)
        if (undefined !== direction) button.dataset.direction = String(direction)
        button.dataset.action = action
        button.setAttribute('aria-label', label)
        button.className = className
        button.innerHTML = svg

        return button
    }

    #accepts(file) {
        const accepted = (this.inputTarget.accept ?? '').split(',').map((type) => type.trim()).filter(Boolean)

        return accepted.length === 0 || accepted.includes(file.type)
    }

    #showErrors(errors) {
        if (!this.hasErrorTarget) return

        this.errorTarget.classList.toggle('hidden', errors.length === 0)
        this.errorTarget.textContent = errors.join(' ')
    }

    #revokeUrls() {
        this.#objectUrls.forEach((url) => URL.revokeObjectURL(url))
        this.#objectUrls = []
    }
}
