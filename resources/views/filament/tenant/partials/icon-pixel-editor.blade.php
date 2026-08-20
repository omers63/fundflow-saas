@php
    /** @var string $field */
    /** @var string $src */
    /** @var int $width */
    /** @var int $height */
@endphp

<div wire:ignore class="ff-pixel-editor" x-data="{
        field: @js($field),
        src: @js($src),
        width: {{ (int) $width }},
        height: {{ (int) $height }},
        tool: 'pencil',
        color: '#111827',
        brushSize: 1,
        opacity: 100,
        filled: false,
        showGrid: true,
        zoom: Math.max(1, Math.min(32, Math.round(256 / Math.max(1, {{ (int) $width }})))),
        minZoom: 1,
        maxZoom: 32,
        drawing: false,
        panning: false,
        spaceDown: false,
        shiftDown: false,
        panX: 0,
        panY: 0,
        lastX: null,
        lastY: null,
        shapeStart: null,
        selection: null,
        selectStart: null,
        movingSelection: false,
        hoveringSelection: false,
        floatingImage: null,
        moveOffsetX: 0,
        moveOffsetY: 0,
        history: [],
        saving: false,
        get canvasStyle() {
            return {
                width: (this.width * this.zoom) + 'px',
                height: (this.height * this.zoom) + 'px',
            }
        },
        get gridStyle() {
            const cell = this.zoom
            const major = this.zoom * 8
            const minor = this.zoom >= 4 ? cell : major

            return {
                backgroundSize: minor + 'px ' + minor + 'px, ' + minor + 'px ' + minor + 'px, ' + major + 'px ' + major + 'px, ' + major + 'px ' + major + 'px',
            }
        },
        toolClass(name) {
            return this.tool === name
                ? 'border-primary-500 bg-primary-500 text-white'
                : 'border-gray-200 bg-white text-gray-700 dark:border-white/10 dark:bg-gray-800 dark:text-gray-200'
        },
        isShapeTool() {
            return this.tool === 'line' || this.tool === 'rect' || this.tool === 'ellipse'
        },
        init() {
            this.load()
        },
        load() {
            const canvas = this.$refs.canvas
            canvas.width = this.width
            canvas.height = this.height
            const ctx = canvas.getContext('2d')
            ctx.imageSmoothingEnabled = false
            ctx.clearRect(0, 0, this.width, this.height)
            if (! this.src) {
                this.updatePreview()
                return
            }
            const img = new Image()
            img.crossOrigin = 'anonymous'
            img.onload = () => {
                ctx.imageSmoothingEnabled = false
                ctx.clearRect(0, 0, this.width, this.height)
                ctx.drawImage(img, 0, 0, this.width, this.height)
                this.snapshot()
                this.redrawOverlay()
            }
            img.onerror = () => {
                this.snapshot()
                this.redrawOverlay()
            }
            img.src = this.src
            this.redrawOverlay()
        },
        snapshot() {
            const ctx = this.$refs.canvas.getContext('2d')
            this.history.push(ctx.getImageData(0, 0, this.width, this.height))
            if (this.history.length > 30) {
                this.history.shift()
            }
        },
        restorePreviewBase() {
            const base = this.history[this.history.length - 1]
            if (! base) {
                return
            }
            this.$refs.canvas.getContext('2d').putImageData(base, 0, 0)
        },
        undo() {
            if (this.history.length < 2) {
                return
            }
            this.history.pop()
            this.floatingImage = null
            this.selection = null
            this.movingSelection = false
            this.$refs.canvas.getContext('2d').putImageData(this.history[this.history.length - 1], 0, 0)
            this.redrawOverlay()
        },
        clearCanvas() {
            this.snapshot()
            this.floatingImage = null
            this.selection = null
            this.$refs.canvas.getContext('2d').clearRect(0, 0, this.width, this.height)
            this.redrawOverlay()
        },
        setTool(name) {
            if (name !== 'select') {
                this.deselect(true)
            }
            this.tool = name
        },
        rectFromPoints(x0, y0, x1, y1) {
            return {
                x: Math.min(x0, x1),
                y: Math.min(y0, y1),
                w: Math.abs(x1 - x0) + 1,
                h: Math.abs(y1 - y0) + 1,
            }
        },
        insideSelection(x, y) {
            const selection = this.selection
            return Boolean(selection)
                && x >= selection.x
                && y >= selection.y
                && x < selection.x + selection.w
                && y < selection.y + selection.h
        },
        redrawOverlay() {
            const overlay = this.$refs.overlay
            if (overlay) {
                overlay.width = this.width
                overlay.height = this.height
                const ctx = overlay.getContext('2d')
                ctx.clearRect(0, 0, this.width, this.height)
                ctx.imageSmoothingEnabled = false
                if (this.floatingImage && this.selection) {
                    ctx.putImageData(this.floatingImage, this.selection.x, this.selection.y)
                }
                if (this.selection) {
                    const { x, y, w, h } = this.selection
                    ctx.save()
                    ctx.lineWidth = 1
                    ctx.setLineDash([2, 2])
                    ctx.strokeStyle = '#ffffff'
                    ctx.strokeRect(x + 0.5, y + 0.5, Math.max(0, w - 1), Math.max(0, h - 1))
                    ctx.strokeStyle = '#111827'
                    ctx.lineDashOffset = 2
                    ctx.strokeRect(x + 0.5, y + 0.5, Math.max(0, w - 1), Math.max(0, h - 1))
                    ctx.restore()
                }
            }
            this.updatePreview()
        },
        updatePreview() {
            const preview = this.$refs.preview
            const source = this.$refs.canvas
            if (! preview || ! source) {
                return
            }
            if (preview.width !== this.width) {
                preview.width = this.width
            }
            if (preview.height !== this.height) {
                preview.height = this.height
            }
            const ctx = preview.getContext('2d')
            ctx.imageSmoothingEnabled = false
            ctx.clearRect(0, 0, this.width, this.height)
            ctx.drawImage(source, 0, 0)
            if (this.floatingImage && this.selection) {
                ctx.putImageData(this.floatingImage, this.selection.x, this.selection.y)
            }
        },
        liftSelection() {
            if (! this.selection || this.floatingImage) {
                return
            }
            const ctx = this.$refs.canvas.getContext('2d')
            this.floatingImage = ctx.getImageData(this.selection.x, this.selection.y, this.selection.w, this.selection.h)
            ctx.clearRect(this.selection.x, this.selection.y, this.selection.w, this.selection.h)
        },
        stampSelection() {
            if (! this.floatingImage || ! this.selection) {
                return
            }
            this.$refs.canvas.getContext('2d').putImageData(this.floatingImage, this.selection.x, this.selection.y)
            this.floatingImage = null
        },
        deselect(commit) {
            if (commit) {
                this.stampSelection()
            } else {
                this.floatingImage = null
            }
            this.selection = null
            this.selectStart = null
            this.movingSelection = false
            this.hoveringSelection = false
            this.redrawOverlay()
        },
        deleteSelection() {
            if (! this.selection) {
                return
            }
            this.snapshot()
            if (! this.floatingImage) {
                this.$refs.canvas.getContext('2d').clearRect(this.selection.x, this.selection.y, this.selection.w, this.selection.h)
            }
            this.floatingImage = null
            this.selection = null
            this.movingSelection = false
            this.redrawOverlay()
        },
        nudge(dx, dy) {
            if (this.tool !== 'select' || ! this.selection) {
                return
            }
            if (! this.floatingImage) {
                this.snapshot()
                this.liftSelection()
            }
            this.selection.x = Math.min(this.width - this.selection.w, Math.max(0, this.selection.x + dx))
            this.selection.y = Math.min(this.height - this.selection.h, Math.max(0, this.selection.y + dy))
            this.redrawOverlay()
        },
        onKey(event) {
            const tag = event.target?.tagName
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
                return
            }
            if (event.code === 'Space') {
                event.preventDefault()
                this.spaceDown = true
            }
            if (event.key === 'Shift') {
                this.shiftDown = true
            }
            if (this.tool !== 'select' || ! this.selection) {
                return
            }
            if (event.key === 'Escape') {
                event.preventDefault()
                this.deselect(true)
            }
            if (event.key === 'Delete' || event.key === 'Backspace') {
                event.preventDefault()
                this.deleteSelection()
            }
            if (event.key === 'ArrowLeft') {
                event.preventDefault()
                this.nudge(-1, 0)
            }
            if (event.key === 'ArrowRight') {
                event.preventDefault()
                this.nudge(1, 0)
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault()
                this.nudge(0, -1)
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault()
                this.nudge(0, 1)
            }
        },
        clampZoom(value) {
            return Math.min(this.maxZoom, Math.max(this.minZoom, Math.round(value)))
        },
        zoomIn() {
            this.zoom = this.clampZoom(this.zoom >= 8 ? this.zoom + 4 : this.zoom + 1)
        },
        zoomOut() {
            this.zoom = this.clampZoom(this.zoom > 8 ? this.zoom - 4 : this.zoom - 1)
        },
        fit() {
            this.zoom = this.clampZoom(256 / Math.max(1, this.width))
        },
        pixel(event) {
            const rect = this.$refs.canvas.getBoundingClientRect()
            const x = Math.floor((event.clientX - rect.left) / rect.width * this.width)
            const y = Math.floor((event.clientY - rect.top) / rect.height * this.height)
            return [
                Math.min(this.width - 1, Math.max(0, x)),
                Math.min(this.height - 1, Math.max(0, y)),
            ]
        },
        constrain(x0, y0, x1, y1) {
            if (! this.shiftDown) {
                return [x1, y1]
            }
            const dx = x1 - x0
            const dy = y1 - y0
            if (this.tool === 'line') {
                const adx = Math.abs(dx)
                const ady = Math.abs(dy)
                if (adx > ady * 2) {
                    return [x1, y0]
                }
                if (ady > adx * 2) {
                    return [x0, y1]
                }
                const d = Math.max(adx, ady)
                return [x0 + Math.sign(dx || 1) * d, y0 + Math.sign(dy || 1) * d]
            }
            const d = Math.max(Math.abs(dx), Math.abs(dy))
            return [x0 + Math.sign(dx || 1) * d, y0 + Math.sign(dy || 1) * d]
        },
        withAlpha(ctx, callback) {
            ctx.save()
            ctx.imageSmoothingEnabled = false
            ctx.globalAlpha = Math.max(0, Math.min(1, Number(this.opacity) / 100))
            ctx.fillStyle = this.color
            ctx.strokeStyle = this.color
            ctx.lineWidth = Math.max(1, Number(this.brushSize))
            ctx.lineCap = 'square'
            ctx.lineJoin = 'miter'
            callback(ctx)
            ctx.restore()
        },
        paint(x, y) {
            const ctx = this.$refs.canvas.getContext('2d')
            const size = Math.max(1, Number(this.brushSize))
            const x0 = x - Math.floor(size / 2)
            const y0 = y - Math.floor(size / 2)
            if (this.tool === 'eraser') {
                ctx.clearRect(x0, y0, size, size)
                return
            }
            this.withAlpha(ctx, (brush) => {
                brush.fillRect(x0, y0, size, size)
            })
        },
        stroke(x0, y0, x1, y1) {
            let dx = Math.abs(x1 - x0)
            let dy = Math.abs(y1 - y0)
            const sx = x0 < x1 ? 1 : -1
            const sy = y0 < y1 ? 1 : -1
            let err = dx - dy
            while (true) {
                this.paint(x0, y0)
                if (x0 === x1 && y0 === y1) {
                    break
                }
                const e2 = 2 * err
                if (e2 > -dy) {
                    err -= dy
                    x0 += sx
                }
                if (e2 < dx) {
                    err += dx
                    y0 += sy
                }
            }
            this.updatePreview()
        },
        drawShape(x0, y0, x1, y1) {
            [x1, y1] = this.constrain(x0, y0, x1, y1)
            const ctx = this.$refs.canvas.getContext('2d')
            this.withAlpha(ctx, (brush) => {
                if (this.tool === 'line') {
                    brush.beginPath()
                    brush.moveTo(x0 + 0.5, y0 + 0.5)
                    brush.lineTo(x1 + 0.5, y1 + 0.5)
                    brush.stroke()
                    return
                }
                const left = Math.min(x0, x1)
                const top = Math.min(y0, y1)
                const w = Math.abs(x1 - x0) + 1
                const h = Math.abs(y1 - y0) + 1
                if (this.tool === 'rect') {
                    if (this.filled) {
                        brush.fillRect(left, top, w, h)
                    } else {
                        const inset = brush.lineWidth / 2
                        brush.strokeRect(
                            left + inset,
                            top + inset,
                            Math.max(0, w - brush.lineWidth),
                            Math.max(0, h - brush.lineWidth),
                        )
                    }
                    return
                }
                brush.beginPath()
                brush.ellipse(
                    (x0 + x1) / 2 + 0.5,
                    (y0 + y1) / 2 + 0.5,
                    Math.max(0.5, Math.abs(x1 - x0) / 2),
                    Math.max(0.5, Math.abs(y1 - y0) / 2),
                    0,
                    0,
                    Math.PI * 2,
                )
                this.filled ? brush.fill() : brush.stroke()
            })
            this.updatePreview()
        },
        pick(x, y) {
            const pixel = this.$refs.canvas.getContext('2d').getImageData(x, y, 1, 1).data
            this.opacity = Math.round(pixel[3] / 255 * 100)
            if (pixel[3] === 0) {
                this.tool = 'eraser'
                return
            }
            this.color = '#' + [pixel[0], pixel[1], pixel[2]]
                .map((channel) => channel.toString(16).padStart(2, '0'))
                .join('')
            this.tool = 'pencil'
        },
        isPanEvent(event) {
            return this.tool === 'pan' || this.spaceDown || event.button === 1
        },
        down(event) {
            event.preventDefault()
            this.$refs.canvas.setPointerCapture(event.pointerId)
            if (this.isPanEvent(event)) {
                this.panning = true
                this.panX = event.clientX
                this.panY = event.clientY
                return
            }
            const [x, y] = this.pixel(event)
            if (this.tool === 'eyedropper') {
                this.pick(x, y)
                return
            }
            if (this.tool === 'select') {
                if (this.insideSelection(x, y)) {
                    if (! this.floatingImage) {
                        this.snapshot()
                        this.liftSelection()
                    }
                    this.movingSelection = true
                    this.moveOffsetX = x - this.selection.x
                    this.moveOffsetY = y - this.selection.y
                    this.redrawOverlay()
                    return
                }
                this.stampSelection()
                this.selectStart = [x, y]
                this.selection = this.rectFromPoints(x, y, x, y)
                this.drawing = true
                this.redrawOverlay()
                return
            }
            this.snapshot()
            this.drawing = true
            this.lastX = x
            this.lastY = y
            if (this.isShapeTool()) {
                this.shapeStart = [x, y]
                this.drawShape(x, y, x, y)
                return
            }
            this.paint(x, y)
            this.updatePreview()
        },
        move(event) {
            if (this.panning) {
                event.preventDefault()
                const viewport = this.$refs.viewport
                viewport.scrollLeft -= event.clientX - this.panX
                viewport.scrollTop -= event.clientY - this.panY
                this.panX = event.clientX
                this.panY = event.clientY
                return
            }
            const [x, y] = this.pixel(event)
            this.hoveringSelection = this.tool === 'select' && this.insideSelection(x, y)
            if (this.movingSelection && this.selection) {
                event.preventDefault()
                this.selection.x = Math.min(this.width - this.selection.w, Math.max(0, x - this.moveOffsetX))
                this.selection.y = Math.min(this.height - this.selection.h, Math.max(0, y - this.moveOffsetY))
                this.redrawOverlay()
                return
            }
            if (! this.drawing) {
                return
            }
            event.preventDefault()
            if (this.tool === 'select' && this.selectStart) {
                let x1 = x
                let y1 = y
                if (this.shiftDown) {
                    const d = Math.max(Math.abs(x - this.selectStart[0]), Math.abs(y - this.selectStart[1]))
                    x1 = this.selectStart[0] + Math.sign(x - this.selectStart[0] || 1) * d
                    y1 = this.selectStart[1] + Math.sign(y - this.selectStart[1] || 1) * d
                }
                this.selection = this.rectFromPoints(this.selectStart[0], this.selectStart[1], x1, y1)
                this.redrawOverlay()
                return
            }
            if (this.isShapeTool() && this.shapeStart) {
                this.restorePreviewBase()
                this.drawShape(this.shapeStart[0], this.shapeStart[1], x, y)
                return
            }
            this.stroke(this.lastX, this.lastY, x, y)
            this.lastX = x
            this.lastY = y
        },
        up(event) {
            if (this.drawing || this.panning || this.movingSelection) {
                event.preventDefault()
            }
            if (this.movingSelection) {
                this.stampSelection()
                this.movingSelection = false
                this.redrawOverlay()
            }
            this.drawing = false
            this.panning = false
            this.shapeStart = null
            this.selectStart = null
            this.lastX = null
            this.lastY = null
        },
        async apply() {
            this.stampSelection()
            this.saving = true
            try {
                const ok = await this.$wire.applyIconPixelEdit(
                    this.field,
                    this.$refs.canvas.toDataURL('image/png'),
                )
                if (ok) {
                    this.$wire.unmountAction()
                }
            } finally {
                this.saving = false
            }
        },
    }" @keydown.window="onKey($event)" @keyup.window.space="spaceDown = false" @keyup.window.shift="shiftDown = false">
    <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
        {{ __('Paint on the native :width×:height icon. Select pixels to move or delete them. Use the zoom buttons or slider to zoom; the mouse wheel only scrolls. Hold Shift to constrain shapes. Pan or Space-drag moves the canvas.', ['width' => $width, 'height' => $height]) }}
    </p>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <button type="button" class="rounded-lg border px-2 py-1 text-xs font-medium" :class="toolClass('pencil')"
            @click="setTool('pencil')">{{ __('Pencil') }}</button>
        <button type="button" class="rounded-lg border px-2 py-1 text-xs font-medium" :class="toolClass('select')"
            @click="setTool('select')">{{ __('Pixel select') }}</button>
        <button type="button" class="rounded-lg border px-2 py-1 text-xs font-medium" :class="toolClass('eraser')"
            @click="setTool('eraser')">{{ __('Eraser') }}</button>
        <button type="button" class="rounded-lg border px-2 py-1 text-xs font-medium" :class="toolClass('eyedropper')"
            @click="setTool('eyedropper')">{{ __('Eyedropper') }}</button>
        <button type="button" class="rounded-lg border px-2 py-1 text-xs font-medium" :class="toolClass('line')"
            @click="setTool('line')">{{ __('Line') }}</button>
        <button type="button" class="rounded-lg border px-2 py-1 text-xs font-medium" :class="toolClass('rect')"
            @click="setTool('rect')">{{ __('Rectangle') }}</button>
        <button type="button" class="rounded-lg border px-2 py-1 text-xs font-medium" :class="toolClass('ellipse')"
            @click="setTool('ellipse')">{{ __('Ellipse') }}</button>
        <button type="button" class="rounded-lg border px-2 py-1 text-xs font-medium" :class="toolClass('pan')"
            @click="setTool('pan')">{{ __('Pan') }}</button>
        <label class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300">
            <input type="checkbox" class="rounded border-gray-300 dark:border-white/20" x-model="filled">
            {{ __('Fill shapes') }}
        </label>
        <label class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300">
            <input type="checkbox" class="rounded border-gray-300 dark:border-white/20" x-model="showGrid">
            {{ __('Grid') }}
        </label>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <label class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300">
            {{ __('Brush color') }}
            <input type="color"
                class="h-8 w-8 cursor-pointer rounded border border-gray-200 bg-white p-0 dark:border-white/10"
                x-model="color">
        </label>
        <label class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300">
            {{ __('Opacity') }}
            <input type="range" min="0" max="100" step="1" class="w-24" x-model.number="opacity">
            <span class="min-w-8 tabular-nums" x-text="opacity + '%'"></span>
        </label>
        <label class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300">
            {{ __('Brush size') }}
            <input type="range" min="1" max="8" step="1" class="w-24" x-model.number="brushSize">
            <span x-text="brushSize"></span>
        </label>
        <button type="button"
            class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-700 dark:border-white/10 dark:bg-gray-800 dark:text-gray-200"
            @click="undo()">{{ __('Undo') }}</button>
        <button type="button"
            class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-700 dark:border-white/10 dark:bg-gray-800 dark:text-gray-200"
            @click="clearCanvas()">{{ __('Clear canvas') }}</button>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <button type="button"
            class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-700 dark:border-white/10 dark:bg-gray-800 dark:text-gray-200"
            @click="zoomOut()">{{ __('Zoom out') }}</button>
        <label class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300">
            {{ __('Zoom') }}
            <input type="range" min="1" max="32" step="1" class="w-28" x-model.number="zoom">
            <span class="min-w-8 tabular-nums" x-text="zoom + '×'"></span>
        </label>
        <button type="button"
            class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-700 dark:border-white/10 dark:bg-gray-800 dark:text-gray-200"
            @click="zoomIn()">{{ __('Zoom in') }}</button>
        <button type="button"
            class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-700 dark:border-white/10 dark:bg-gray-800 dark:text-gray-200"
            @click="fit()">{{ __('Fit') }}</button>
    </div>

    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-start">
        <div x-ref="viewport"
            class="ff-pixel-editor__viewport order-last min-w-0 max-w-full flex-1 rounded-lg border border-gray-200 dark:border-white/10 lg:order-first">
            <div class="ff-pixel-editor__stage relative inline-block p-2">
                <div class="relative inline-block">
                    <canvas x-ref="canvas" class="ff-pixel-editor__canvas" :class="{
                            'is-panning': panning || spaceDown || tool === 'pan',
                            'is-selecting': tool === 'select' && ! hoveringSelection && ! movingSelection,
                            'is-moving-selection': tool === 'select' && (hoveringSelection || movingSelection),
                        }" :style="canvasStyle" @pointerdown="down($event)" @pointermove="move($event)"
                        @pointerup="up($event)" @pointercancel="up($event)" @pointerleave="up($event)"></canvas>
                    <div class="ff-pixel-editor__grid pointer-events-none absolute inset-0" x-show="showGrid"
                        :style="gridStyle"></div>
                    <canvas x-ref="overlay" class="ff-pixel-editor__overlay pointer-events-none absolute inset-0"
                        :style="canvasStyle"></canvas>
                </div>
            </div>
        </div>
        <div class="ff-pixel-editor__preview-pane order-first shrink-0 lg:order-last">
            <p class="mb-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                {{ __('Actual size (:width×:height)', ['width' => $width, 'height' => $height]) }}
            </p>
            <div
                class="ff-pixel-editor__preview-stage ff-pixel-editor__stage inline-block rounded-lg border border-gray-200 p-2 dark:border-white/10">
                <canvas x-ref="preview" class="ff-pixel-editor__preview" width="{{ (int) $width }}"
                    height="{{ (int) $height }}"></canvas>
            </div>
        </div>
    </div>

    <div class="flex justify-end">
        <x-filament::button type="button" x-on:click="apply()" x-bind:disabled="saving">
            {{ __('Apply drawing') }}
        </x-filament::button>
    </div>
</div>