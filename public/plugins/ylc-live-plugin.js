const YLCPlugin = function (YS, options = {}) {
    // Strip trailing slashes so a baseUrl of "/" (or "http://host/") never collapses
    // into a "//path" protocol-relative URL once the leading "/ylc/..." is appended.
    const host = (options.baseUrl || '').replace(/\/+$/, '')
    const endpoint = options.endpoint || host + '/ylc/message'
    const streamEndpoint = options.streamEndpoint || host + '/ylc/stream'
    const lazyEndpoint = options.lazyEndpoint || host + '/ylc/lazy'
    const uploadEndpoint = options.uploadEndpoint || host + '/ylc/upload'
    const pollingInterval = options.pollingInterval || 5000
    const streamFallbackToPolling = options.streamFallbackToPolling !== false

    const registeredListeners = new WeakMap()

    const jsActions = {}
    const components = new Map()
    let suppressUrlPush = false

    window.YLC = window.YLC || {}

    window.YLC.js = function (name, callback) {
        jsActions[name] = callback
    }

    window.YLC.find = function (id) {
        if (!components.has(id)) return null

        return createComponentApi(id)
    }

    window.YLC.all = function () {
        return Array.from(components.keys()).map(id => createComponentApi(id))
    }

    window.YLC.first = function (name = null) {
        const elements = Array.from(document.querySelectorAll('[ylc\\:id]'))

        const el = name
            ? elements.find(node => node.getAttribute('ylc:component') === name)
            : elements[0]

        if (!el || !el.__ylc) return null

        components.set(el.__ylc.snapshot.id, el)

        console.log(el);

        return createComponentApi(el.__ylc.snapshot.id)
    }

    const lazyObserver = 'IntersectionObserver' in window
        ? new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    loadLazy(entry.target)
                }
            })
        })
        : null

    function init(root = document) {
        root.querySelectorAll('[ylc\\:id]').forEach(register)

        root.querySelectorAll('[ylc\\:lazy]').forEach(registerLazy)
    }

    function createComponentApi(id) {
        function currentEl() {
            return components.get(id)
        }

        return {
            id,

            get name() {
                return currentEl()?.__ylc.snapshot.name
            },

            el() {
                return currentEl()
            },

            snapshot() {
                return currentEl()?.__ylc.snapshot
            },

            get(path) {
                const el = currentEl()
                if (!el) return null

                return getNestedValue(el.__ylc.snapshot.state, path)
            },

            async set(path, value) {
                const el = currentEl()
                console.log('[YLC API set]', path, value, el)

                if (!el) return

                await send(el, {
                    updates: {
                        [path]: value
                    }
                })
            },

            async call(method, ...params) {
                const el = currentEl()
                console.log('[YLC API call]', method, el)

                if (!el) return

                await send(el, {
                    action: method,
                    params
                })
            },

            async refresh() {
                const el = currentEl()
                if (!el) return

                await send(el, {
                    updates: {},
                    action: null,
                    params: []
                })
            }
        }
    }

    function getNestedValue(object, path) {
        return path
            .split('.')
            .reduce((current, segment) => current?.[segment], object)
    }

    function register(el) {
        toggleLoadingAttributes(el, false)

        if (el.__ylc) return

        el.__ylc = {
            snapshot: JSON.parse(el.getAttribute('ylc:snapshot')),
            checksum: el.getAttribute('ylc:checksum'),
            loadingAction: null,
            request: null,
            streamRefreshQueued: false,
            lastStreamRefreshAt: 0,
            pollingTimer: null,
            streamFailures: 0,
            lastMessage: null,
        }

        components.set(el.__ylc.snapshot.id, el)

        registerListeners(el)
        connectStream(el)
        syncUrl(el, { replace: true })

        if (el.hasAttribute('ylc:poll')) {
            startPolling(el)
        }

        fire(el, 'init', {
            component: el.__ylc.snapshot.name,
            id: el.__ylc.snapshot.id
        })

        evaluateHooks(el, 'ylc:init', {
            component: el.__ylc.snapshot.name,
            id: el.__ylc.snapshot.id
        })

        el.addEventListener('click', async e => {
            const retry = e.target.closest('[ylc\\:retry]')

            if (retry && el.contains(retry) && belongsToComponent(retry, el)) {
                if (el.__ylc.lastMessage) {
                    await send(el, el.__ylc.lastMessage)
                }

                return
            }

            const target = e.target.closest('[ylc\\:click]')

            if (!target || !el.contains(target)) return
            if (!belongsToComponent(target, el)) return

            const parsed = parseAction(target.getAttribute('ylc:click'))

            if (parsed.action.startsWith('$js.')) {
                const jsAction = parsed.action.replace('$js.', '')
                console.log(jsAction);
                if (typeof jsActions[jsAction] === 'function') {
                    await jsActions[jsAction]({
                        el,
                        target,
                        component: el.__ylc.snapshot,
                        params: parsed.params || []
                    })
                }

                return
            }

            await send(el, {
                action: parsed.action,
                params: parsed.params
            })
        })

        const updateModel = async e => {
            const target = e.target.closest('[ylc\\:model]')
            if (!target || !el.contains(target)) return
            if (!belongsToComponent(target, el)) return

            const model = target.getAttribute('ylc:model')

            if (target.type === 'file') {
                await uploadFiles(el, target, model)
                return
            }

            await send(el, {
                updates: {
                    [model]: getInputValue(target)
                }
            })
        }

        let composing = false

        el.addEventListener('compositionstart', () => {
            composing = true
        })

        el.addEventListener('compositionend', e => {
            composing = false
            updateModel(e)
        })

        el.addEventListener('input', debounce(e => {
            const target = e.target.closest('[ylc\\:model]')

            if (target?.type === 'file') return

            if (composing) return

            updateModel(e)
        }, options.modelDebounce || 300))

        el.addEventListener('change', e => {
            const target = e.target.closest('[ylc\\:model]')
            if (!target || !shouldUpdateModelOnChange(target)) return

            updateModel(e)
        })
    }

    async function uploadFiles(el, input, model) {
        const files = Array.from(input.files || [])

        if (!files.length) return

        setLoading(el, true, 'upload')
        fire(el, 'upload-start', { model, files })

        try {
            const uploaded = []

            const uploadConfig = options.uploads || window.YLC_UPLOAD_CONFIG || {}

            for (const file of files) {

                if (uploadConfig.maxSize && file.size > uploadConfig.maxSize) {
                    throw new Error('File is too large')
                }

                if (
                    uploadConfig.types &&
                    uploadConfig.types.length &&
                    !uploadConfig.types.includes(file.type)
                ) {
                    throw new Error('File type is not allowed')
                }

                const form = new FormData()
                form.append('file', file)

                const response = await fetch(uploadEndpoint, {
                    method: 'POST',
                    headers: {
                        'X-YLC': 'true'
                    },
                    body: form
                })

                const data = await response.json()

                if (!response.ok) {
                    throw new Error(data.message || 'Upload failed')
                }

                uploaded.push(data)

                fire(el, 'upload-progress', {
                    model,
                    file,
                    uploaded: data
                })
            }

            await send(el, {
                updates: {
                    [model]: input.multiple ? uploaded : uploaded[0]
                }
            })

            fire(el, 'upload-finish', {
                model,
                files: uploaded
            })
        } catch (error) {
            fire(el, 'upload-error', {
                model,
                error
            })

            setFailed(el, true)

            document.dispatchEvent(new CustomEvent('ylc:toast', {
                detail: {
                    type: 'error',
                    message: error.message || 'Upload failed'
                }
            }))

            throw error
        } finally {
            setLoading(el, false, 'upload')
        }
    }

    function registerLazy(el) {
        if (el.__ylcLazy) return

        el.__ylcLazy = true

        if (lazyObserver) {
            lazyObserver.observe(el)
        } else {
            loadLazy(el)
        }
    }

    async function loadLazy(el) {
        if (el.__ylcLazyLoading || el.__ylcLazyLoaded) return

        el.__ylcLazyLoading = true
        el.setAttribute('ylc:busy', 'true')

        try {
            const response = await fetch(lazyEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-YLC': 'true'
                },
                body: JSON.stringify({
                    name: el.getAttribute('ylc:lazy-name'),
                    params: JSON.parse(el.getAttribute('ylc:lazy-params') || '{}'),
                    options: JSON.parse(el.getAttribute('ylc:lazy-options') || '{}')
                })
            })

            const data = await response.json()

            if (!response.ok) {
                throw new Error(data.message || 'Failed to load lazy component')
            }

            el.outerHTML = data.html

            el.__ylcLazyLoaded = true

            init(document)
        } catch (error) {
            el.setAttribute('ylc:failed', 'true')
            console.error('[YLC lazy]', error)
        } finally {
            el.removeAttribute('ylc:busy')

            if (lazyObserver) {
                lazyObserver.unobserve(el)
            }
        }
    }

    function shouldUpdateModelOnChange(el) {
        if (el.tagName === 'SELECT') return true
        if (el.tagName !== 'INPUT') return false

        const type = (el.type || '').toLowerCase()

        return [
            'checkbox',
            'radio',
            'file',
            'date',
            'datetime-local',
            'month',
            'time',
            'week',
            'color',
            'range'
        ].includes(type)
    }

    async function send(el, message) {
        const action = message.action || null
        const source = message.source || 'request'
        const isStreamRefresh = source === 'stream'
        const shouldShowLoading = !!action

        // A request is still in flight (e.g. an earlier field's debounced
        // update hasn't resolved yet) and is about to be aborted below -
        // merge its updates into this message first, otherwise that field's
        // change is silently dropped: the aborted request's response (which
        // would have confirmed it server-side) never arrives, and this
        // request's response replaces the snapshot with one built from
        // state that never saw it.
        if (!isStreamRefresh && el.__ylc.request && el.__ylc.lastMessage?.updates) {
            message.updates = { ...el.__ylc.lastMessage.updates, ...(message.updates || {}) }
        }

        if (!message.__retry) {
            el.__ylc.lastMessage = message
        }

        setFailed(el, false)

        if (isStreamRefresh && el.__ylc.request) {
            return
        }

        if (!isStreamRefresh && el.__ylc.request) {
            el.__ylc.request.abort()
            el.__ylc.request = null
        }

        const controller = new AbortController()
        el.__ylc.request = controller

        fire(el, 'before-request', { action, source, message })

        if (shouldShowLoading) {
            setLoading(el, true, action)
            fire(el, 'busy', { action })
        }

        fire(el, 'sending', {
            message
        })

        try {

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-YLC': 'true'
                },
                body: JSON.stringify({
                    snapshot: el.__ylc.snapshot,
                    checksum: el.__ylc.checksum,
                    updates: message.updates || {},
                    action,
                    params: message.params || []
                }),
                signal: controller.signal
            })

            if (!response.ok) {
                const error = await response.json().catch(() => ({}))

                fire(el, 'error', {
                    action,
                    status: response.status,
                    error
                })

                throw new Error(error.message || `YLC request failed with status ${response.status}`)
            }

            const data = await response.json()

            if (el.__ylc.request !== controller) return

            fire(el, 'after-request', { action, response: data })

            el.__ylc.snapshot = data.snapshot
            el.__ylc.checksum = data.checksum
            components.set(el.__ylc.snapshot.id, el)

            el.setAttribute('ylc:snapshot', JSON.stringify(data.snapshot))
            el.setAttribute('ylc:checksum', data.checksum)

            applyServerUpdate(el, data, {
                source,
                action
            })

            components.set(data.snapshot.id, el)

            fire(el, 'received', {
                response: data
            })

        } catch (error) {
            setFailed(el, true)
            if (error.name === 'AbortError') return

            fire(el, 'error', { action, error })

            document.dispatchEvent(new CustomEvent('ylc:error', {
                detail: {
                    component: el.__ylc.snapshot.name,
                    action,
                    message: error.message || 'YLC request failed',
                    error
                }
            }))

            evaluateHooks(el, 'ylc:failed', {
                error
            })

            if (options.debug !== false) {
                console.error('[YLC error]', error)
            }
            throw error
        } finally {
            if (el.__ylc.request === controller) {
                el.__ylc.request = null
            }

            if (shouldShowLoading) {
                setLoading(el, false, action)
                fire(el, 'idle', { action })
            }
        }
    }

    function setFailed(el, failed) {
        if (failed) {
            el.setAttribute('ylc:failed', 'true')
        } else {
            el.removeAttribute('ylc:failed')
        }
    }

    function morph(el, html) {
        const wrapper = el.cloneNode(false)
        wrapper.innerHTML = html

        if (typeof YS.morph === 'function') {
            YS.morph(el, wrapper.outerHTML)
        } else {
            el.innerHTML = html
        }
    }

    function morphComponent(el, html) {
        const preserved = preserveNestedComponents(el)

        morph(el, html)

        restoreNestedComponents(el, preserved)
    }

    function preserveNestedComponents(el) {
        const preserved = new Map()

        el.querySelectorAll('[ylc\\:id]').forEach(child => {
            if (child === el) return

            const component = child.getAttribute('ylc:component')
            if (!component) return

            if (!preserved.has(component)) {
                preserved.set(component, [])
            }

            preserved.get(component).push(child)
        })

        return preserved
    }

    function restoreNestedComponents(el, preserved) {
        preserved.forEach((nodes, component) => {
            const freshNodes = el.querySelectorAll(`[ylc\\:component="${component}"]`)

            freshNodes.forEach((freshNode, index) => {
                if (nodes[index]) {
                    freshNode.replaceWith(nodes[index])
                }
            })
        })
    }

    function restoreFocus(el, activeInfo) {
        if (!activeInfo || !activeInfo.model) return

        const newActive = el.querySelector(`[ylc\\:model="${activeInfo.model}"]`)
        if (!newActive) return

        newActive.focus()

        if ('value' in newActive) {
            newActive.value = activeInfo.value
        }

        if (activeInfo.selection && canSetSelectionRange(newActive)) {
            newActive.setSelectionRange(
                activeInfo.selection[0],
                activeInfo.selection[1]
            )
        }
    }

    function belongsToComponent(target, el) {
        const owner = target.closest('[ylc\\:id]')
        return owner === el
    }

    function registerListeners(el) {
        if (registeredListeners.has(el)) return

        const listeners = el.__ylc.snapshot.listeners || {}
        const handlers = []

        Object.keys(listeners).forEach(event => {
            const handler = async e => {
                await send(el, {
                    action: listeners[event],
                    params: [e.detail || {}]
                })
            }

            document.addEventListener(`ylc:${event}`, handler)

            handlers.push({ event, handler })
        })

        registeredListeners.set(el, handlers)
    }

    function handleEffects(effects, el) {
        ; (effects.dispatches || []).forEach(({ event, payload }) => {
            el.dispatchEvent(new CustomEvent(event, {
                detail: payload || {},
                bubbles: true
            }))
        })

            ; (effects.emits || []).forEach(({ event, payload }) => {
                document.dispatchEvent(new CustomEvent(`ylc:${event}`, {
                    detail: payload || {}
                }))
            })

            ; (effects.toasts || []).forEach(({ message, type, title, duration, persistent }) => {
                document.dispatchEvent(new CustomEvent('ylc:toast', {
                    detail: {
                        message,
                        type: type || 'success',
                        title,
                        duration,
                        persistent
                    }
                }))

                if (!window.YLC_DISABLE_CONSOLE_TOASTS) {
                    console.log(`[YLC toast:${type || 'success'}] ${message}`)
                }
            })

            ; (effects.js || []).forEach(({ action, payload }) => {
                if (typeof jsActions[action] === 'function') {
                    jsActions[action]({
                        el,
                        component: el.__ylc.snapshot,
                        payload: payload || {}
                    })
                }
            })

        if (effects.redirect) {
            window.location.href = effects.redirect
        }
    }

    function setLoading(el, loading, action = null) {
        if (loading) {
            el.setAttribute('ylc:busy', 'true')
            el.__ylc.loadingAction = action
        } else {
            el.removeAttribute('ylc:busy')
            el.__ylc.loadingAction = null
        }

        toggleLoadingAttributes(el, loading, action)
    }

    function toggleLoadingAttributes(el, loading, action = null) {
        el.querySelectorAll('[ylc\\:loading\\.attr]').forEach(node => {
            const attr = node.getAttribute('ylc:loading.attr')
            const target = node.getAttribute('ylc:target')

            if (target && action && target !== action) return

            if (loading) {
                node.setAttribute(attr, attr)
            } else {
                node.removeAttribute(attr)
            }
        })
    }

    function getInputValue(el) {
        if (el.type === 'checkbox') return el.checked

        if (el.type === 'radio') return el.checked ? el.value : null

        if (el.tagName === 'SELECT' && el.multiple) {
            return Array.from(el.selectedOptions).map(option => option.value)
        }

        if (el.type === 'number' || el.type === 'range') {
            return el.value === '' ? null : Number(el.value)
        }

        if (el.type === 'file') {
            // return null // uploads are handled seperately
            return el.multiple
                ? Array.from(el.files)
                : (el.files[0] || null)
        }

        return el.value
    }

    function parseAction(expression) {
        const match = expression.match(/^([a-zA-Z_$][\w$]*(?:\.[a-zA-Z_$][\w$]*)*)\((.*)\)$/)

        if (!match) {
            return { action: expression, params: [] }
        }

        const action = match[1]
        const rawParams = match[2].trim()

        if (!rawParams) {
            return { action, params: [] }
        }

        try {
            return {
                action,
                params: Function(`"use strict"; return [${rawParams}]`)()
            }
        } catch (e) {
            console.error('Invalid YLC action parameters:', expression, e)
            return { action, params: [] }
        }
    }

    function canSetSelectionRange(el) {
        if (!el || typeof el.setSelectionRange !== 'function') return false
        if (el.tagName === 'TEXTAREA') return true
        if (el.tagName !== 'INPUT') return false

        return ['text', 'search', 'url', 'tel', 'password'].includes(
            (el.type || '').toLowerCase()
        )
    }

    function refreshYS(root) {
        if (typeof YS.compile === 'function') return YS.compile(root)
        if (typeof YS.init === 'function') return YS.init(root)
        if (typeof YS.start === 'function') return YS.start()
    }

    function fire(el, name, detail = {}) {
        el.dispatchEvent(new CustomEvent(`ylc:${name}`, {
            detail,
            bubbles: true
        }))
    }

    function debounce(fn, wait = 250) {
        let timer

        return function (...args) {
            clearTimeout(timer)
            timer = setTimeout(() => fn.apply(this, args), wait)
        }
    }

    function injectStyles() {
        if (document.getElementById('ylc-styles')) {
            return
        }

        const style = document.createElement('style')

        style.id = 'ylc-styles'

        style.textContent = `
            [ylc\\:loading] {
                display: none;
            }

            [ylc\\:busy="true"] [ylc\\:loading] {
                display: inline;
            }

            [ylc\\:busy="true"] {
                opacity: .75;
            }

            [ylc\\:error] {
                display: none;
            }

            [ylc\\:failed="true"] [ylc\\:error] {
                display: block;
            }
        `

        document.head.appendChild(style)
    }

    function connectStream(el) {
        if (!el.hasAttribute('ylc:stream')) return
        if (el.__ylc.stream) return

        const snapshot = el.__ylc.snapshot

        const url = new URL(streamEndpoint, window.location.origin)

        url.searchParams.set('id', snapshot.id)
        url.searchParams.set('name', snapshot.name)

        const source = new EventSource(url.toString())

        source.onopen = () => {
            el.__ylc.streamFailures = 0

            stopPolling(el)

            fire(el, 'stream-open', {
                id: snapshot.id,
                component: snapshot.name
            })
        }

        el.__ylc.stream = source

        source.addEventListener('ping', event => {
            fire(el, 'stream-ping', {
                data: JSON.parse(event.data)
            })
        })

        source.addEventListener('ylc-refresh', event => {
            const payload = JSON.parse(event.data)

            if (payload.id !== el.__ylc.snapshot.id) return

            const serverVersion = Number(payload.version || 0)
            const localVersion = Number(el.__ylc.snapshot.version || 0)

            const always = el.hasAttribute('ylc:stream-always')

            if (!always && serverVersion <= localVersion) {
                return
            }

            scheduleStreamRefresh(el)
        })

        source.addEventListener('ylc-close', () => {
            disconnectStream(el)

            setTimeout(() => {
                if (document.body.contains(el)) {
                    connectStream(el)
                }
            }, 1500);
        })

        source.onerror = () => {
            fire(el, 'stream-error', {})

            disconnectStream(el)

            el.__ylc.streamFailures++

            if (
                streamFallbackToPolling &&
                el.__ylc.streamFailures >= 2
            ) {
                startPolling(el)
                return
            }

            const interval = Number(
                el.getAttribute('ylc:stream-interval') || 3000
            )

            setTimeout(() => {
                if (document.body.contains(el)) {
                    connectStream(el)
                }
            }, interval)
        }
    }

    function disconnectStream(el) {
        if (!el.__ylc?.stream) return

        el.__ylc.stream.close()
        el.__ylc.stream = null

        fire(el, 'stream-close')
    }

    function startPolling(el) {
        if (el.__ylc.pollingTimer) return

        fire(el, 'polling-start')

        el.__ylc.pollingTimer = setInterval(() => {
            scheduleStreamRefresh(el)
        }, Number(
            el.getAttribute('ylc:poll-interval') ||
            pollingInterval
        ))
    }

    function stopPolling(el) {
        if (!el.__ylc.pollingTimer) return

        clearInterval(el.__ylc.pollingTimer)
        el.__ylc.pollingTimer = null

        fire(el, 'polling-stop')
    }

    function applyServerUpdate(el, data, meta = {}) {
        if (!data || (!data.html && !data.fragmentHtml)) return

        el.__ylc.snapshot = data.snapshot || el.__ylc.snapshot
        el.__ylc.checksum = data.checksum || el.__ylc.checksum

        const active = document.activeElement

        const activeInfo = active?.getAttribute?.('ylc:model')
            ? {
                model: active.getAttribute('ylc:model'),
                value: active.value,
                selection: canSetSelectionRange(active)
                    ? [active.selectionStart, active.selectionEnd]
                    : null
            }
            : null

        syncUrl(el, meta)

        el.setAttribute('ylc:snapshot', JSON.stringify(el.__ylc.snapshot))

        if (el.__ylc.checksum) {
            el.setAttribute('ylc:checksum', el.__ylc.checksum)
        }

        fire(el, 'before-morph', {
            html: data.html,
            snapshot: el.__ylc.snapshot,
            source: meta.source || 'request'
        })

        // morphComponent(el, data.html)
        const activePreserve = preserveActiveModel(el)
        if (
            data.fragmentHtml &&
            data.fragments &&
            data.fragments.length &&
            data.fragments.every(f => f.type === 'replace')
        ) {
            applyRenderedFragments(el, data.fragmentHtml, data.fragments)
        } else if (data.fragments && data.fragments.length) {
            applyFragments(el, data.html, data.fragments)
        } else {
            morphComponent(el, data.html)
        }
        restoreActiveModel(el, activePreserve)
        components.set(el.__ylc.snapshot.id, el)

        evaluateHooks(el, 'ylc:updated', {
            snapshot: el.__ylc.snapshot
        })

        refreshYS(el)
        init(el)

        // restoreFocus(el, activeInfo)

        fire(el, 'after-morph', {
            snapshot: el.__ylc.snapshot,
            source: meta.source || 'request'
        })

        handleEffects(data.effects || {}, el)

        fire(el, 'hydrated', {
            snapshot: el.__ylc.snapshot
        })
    }

    function findByAttr(root, attr, value) {
        return Array.from(root.querySelectorAll('*')).find(node => {
            return node.getAttribute(attr) === value
        })
    }

    function findAllByAttr(root, attr, value) {
        return Array.from(root.querySelectorAll('*')).filter(node => {
            return node.getAttribute(attr) === value
        })
    }

    function applyRenderedFragments(el, fragmentHtml, fragments) {
        fragments.forEach(fragment => {
            const html = fragmentHtml[fragment.name]

            if (!html) return

            const wrapper = document.createElement('div')
            wrapper.innerHTML = html

            const current = findFragment(el, fragment.name)
            const fresh = findFragment(wrapper, fragment.name)

            if (!current || !fresh) {
                console.warn('[YLC] fragment not found:', fragment.name)
                return
            }

            if (fragment.type === 'replace') {
                morph(el, fresh.outerHTML)
                return
            }

            if (fragment.type === 'append' || fragment.type === 'prepend') {
                const items = fragment.item
                    ? findItems(fresh, fragment.item)
                    : Array.from(fresh.children)

                items.forEach(item => {
                    const key =
                        item.getAttribute('id') ||
                        item.getAttribute('data-ylc-item') ||
                        item.getAttribute('ylc:item') ||
                        item.getAttribute('item')

                    const existing = key
                        ? findItem(current, key)
                        : null

                    if (existing) {
                        morph(existing, item.outerHTML)
                        return
                    }

                    if (fragment.type === 'append') {
                        current.appendChild(item)
                    } else {
                        current.prepend(item)
                    }
                })
            }
        })
    }

    function findFragment(root, name) {
        return Array.from(root.querySelectorAll('*')).find(node => {
            return node.getAttribute('id') === name ||
                node.getAttribute('data-ylc-fragment') === name ||
                node.getAttribute('ylc:fragment') === name ||
                node.getAttribute('fragment') === name
        })
    }

    function findItem(root, name) {
        return Array.from(root.querySelectorAll('*')).find(node => {
            return node.getAttribute('id') === name ||
                node.getAttribute('data-ylc-item') === name ||
                node.getAttribute('ylc:item') === name ||
                node.getAttribute('item') === name
        })
    }

    function findItems(root, name) {
        return Array.from(root.querySelectorAll('*')).filter(node => {
            return node.getAttribute('id') === name ||
                node.getAttribute('data-ylc-item') === name ||
                node.getAttribute('ylc:item') === name ||
                node.getAttribute('item') === name
        })
    }

    function preserveActiveModel(root) {
        const active = document.activeElement

        if (!active || !root.contains(active)) {
            return null
        }

        const model = active.getAttribute?.('ylc:model')

        if (!model) {
            return null
        }

        const placeholder = document.createComment(`ylc-active-model:${model}`)

        active.replaceWith(placeholder)

        return {
            model,
            // A radio group binds every option to the *same* ylc:model
            // value (the only correct way to bind one) - type+value is what
            // actually disambiguates which specific input was focused, not
            // just the model name. checkbox is included for the same reason
            // even though this codebase doesn't group those today.
            type: active.type,
            value: active.value,
            node: active,
            placeholder
        }
    }

    function restoreActiveModel(root, preserved) {
        if (!preserved) return

        const matches = root.querySelectorAll(`[ylc\\:model="${preserved.model}"]`)
        let fresh = matches[0]

        if (matches.length > 1 && (preserved.type === 'radio' || preserved.type === 'checkbox')) {
            fresh = Array.from(matches).find(el => el.value === preserved.value) || fresh
        }

        if (fresh) {
            fresh.replaceWith(preserved.node)
            preserved.node.focus()
            return
        }

        if (preserved.placeholder.parentNode) {
            preserved.placeholder.replaceWith(preserved.node)
            preserved.node.focus()
        }
    }

    function scheduleStreamRefresh(el) {
        // const interval = options.streamRefreshDebounce || 1000
        const interval = Number(
            el.getAttribute('ylc:stream-interval') ||
            options.streamRefreshDebounce ||
            1000
        )
        const now = Date.now()

        if (el.__ylc.streamRefreshQueued) return

        const elapsed = now - el.__ylc.lastStreamRefreshAt

        el.__ylc.streamRefreshQueued = true

        setTimeout(async () => {
            el.__ylc.streamRefreshQueued = false
            el.__ylc.lastStreamRefreshAt = Date.now()

            await send(el, {
                updates: {},
                action: null,
                params: [],
                source: 'stream'
            })
        }, Math.max(0, interval - elapsed))
    }

    function applyFragments(el, html, fragments) {
        const wrapper = document.createElement('div')
        wrapper.innerHTML = html

        fragments.forEach(fragment => {
            const current = findFragment(el, fragment.name)
            const fresh = findFragment(wrapper, fragment.name)

            if (!current || !fresh) return

            if (fragment.type === 'replace') {
                morph(el, fresh.outerHTML)
                return
            }

            if (fragment.type === 'append' || fragment.type === 'prepend') {
                const items = fragment.item
                    ? findItems(fresh, fragment.item)
                    : Array.from(fresh.children)

                items.forEach(item => {
                    const key =
                        item.getAttribute('id') ||
                        item.getAttribute('data-ylc-item') ||
                        item.getAttribute('ylc:item') ||
                        item.getAttribute('item')

                    const existing = key
                        ? findItem(current, key)
                        : null

                    if (existing) {
                        morph(existing, item.outerHTML)
                        return
                    }

                    if (fragment.type === 'append') {
                        current.appendChild(item)
                    } else {
                        current.prepend(item)
                    }
                })
            }
        })
    }

    function evaluateHooks(root, attr, detail = {}) {
        evaluateHook(root, attr, detail)

        root.querySelectorAll(`[${escapeAttrSelector(attr)}]`).forEach(node => {
            if (!belongsToComponent(node, root)) return

            evaluateHook(node, attr, detail)
        })
    }

    function evaluateHook(el, attr, detail = {}) {
        const expression = el.getAttribute(attr)

        if (!expression) return

        try {
            Function('$event', '$el', expression)(detail, el)
        } catch (e) {
            console.error(`[YLC hook error] ${attr}`, e)
        }
    }

    function escapeAttrSelector(attr) {
        return attr.replace(':', '\\:')
    }

    function syncUrl(el, meta = {}) {
        const urlConfig = el.__ylc.snapshot.url || {}

        const url = new URL(window.location.href)
        const before = url.toString()
        let shouldPush = false

        Object.entries(urlConfig).forEach(([property, config]) => {
            const value = el.__ylc.snapshot.state[property]

            const isEmpty = value === null || value === '' || value === false
            const isDefault = 'default' in config && JSON.stringify(value) === JSON.stringify(config.default)

            if (isEmpty || isDefault) {
                url.searchParams.delete(config.name)
                return
            }

            url.searchParams.set(config.name, config.array ? JSON.stringify(value) : value)

            if (config.history) {
                shouldPush = true
            }
        })

        const after = url.toString()

        if (before === after) return

        if (suppressUrlPush || meta.replace || !shouldPush) {
            history.replaceState({}, '', url)
            return
        }

        history.pushState({}, '', url)
    }

    window.addEventListener('popstate', () => {
        components.forEach(el => {
            const updates = updatesFromUrl(el)

            if (!Object.keys(updates).length) {
                return
            }

            suppressUrlPush = true

            send(el, {
                updates,
                source: 'history'
            }).finally(() => {
                suppressUrlPush = false
            })
        })
    })

    function updatesFromUrl(el) {
        const urlConfig = el.__ylc.snapshot.url || {}
        const url = new URL(window.location.href)
        const updates = {}

        Object.entries(urlConfig).forEach(([property, config]) => {
            const current = el.__ylc.snapshot.state[property]

            if (config.array) {
                const raw = url.searchParams.get(config.name)
                let value = config.default

                if (raw !== null) {
                    try {
                        value = JSON.parse(raw)
                    } catch (e) {
                        value = config.default
                    }
                }

                if (JSON.stringify(current ?? null) !== JSON.stringify(value ?? null)) {
                    updates[property] = value
                }

                return
            }

            const value = url.searchParams.get(config.name) || ''

            if (String(current ?? '') !== value) {
                updates[property] = value
            }
        })

        return updates
    }

    YS.fn('ylc', function () {
        this.each(el => register(el))
        return this
    })

    // Portal event delegation — forwards ylc:click/ylc:model events from
    // ys-teleport panels that have been moved outside their host component's
    // DOM subtree (to <body>, etc.) back to the correct component.
    // teleport.js stamps data-ylc-portal-for="{componentId}" on the portal
    // root so we can look it up in the components Map here.
    document.addEventListener('click', async e => {
        const target = e.target.closest('[ylc\\:click]')
        if (!target) return
        const portal = target.closest('[data-ylc-portal-for]')
        if (!portal) return
        const componentEl = components.get(portal.dataset.ylcPortalFor)
        if (!componentEl) return

        const parsed = parseAction(target.getAttribute('ylc:click'))
        await send(componentEl, { action: parsed.action, params: parsed.params })
    })

    const updatePortalModel = async e => {
        const target = e.target.closest('[ylc\\:model]')
        if (!target) return
        const portal = target.closest('[data-ylc-portal-for]')
        if (!portal) return
        const componentEl = components.get(portal.dataset.ylcPortalFor)
        if (!componentEl) return

        const model = target.getAttribute('ylc:model')
        if (target.type === 'file') {
            await uploadFiles(componentEl, target, model)
            return
        }
        await send(componentEl, { updates: { [model]: getInputValue(target) } })
    }

    document.addEventListener('input', debounce(e => {
        const target = e.target.closest('[ylc\\:model]')
        if (target?.type === 'file') return
        updatePortalModel(e)
    }, options.modelDebounce || 300))

    document.addEventListener('change', e => {
        const target = e.target.closest('[ylc\\:model]')
        if (!target || !shouldUpdateModelOnChange(target)) return
        updatePortalModel(e)
    })

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            injectStyles()
            init()
        })
    } else {
        injectStyles()
        init()
    }
}
