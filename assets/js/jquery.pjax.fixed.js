/*!
 * Modified jquery-pjax with Safari/Firefox fixes
 * Based on jquery-pjax by Chris Wanstrath
 * Released under the MIT License
 * https://github.com/defunkt/jquery-pjax
 */
(function($){

// When called on a container with a selector, fetches the href with
// ajax into the container or with the data-pjax attribute on the link
// itself.
//
// Tries to make sure the back button and ctrl+click work the way
// you'd expect.
//
// Exported as $.fn.pjax
//
// Accepts a jQuery ajax options object that may include these
// pjax specific options:
//
//
//               container - String selector for the element where to place the response body.
//                    push - Whether to pushState the URL. Defaults to true (of course).
//                 replace - Want to use replaceState instead? That's cool.
//                 history - Work with window.history. Defaults to true
//                   cache - Whether to cache pages HTML. Defaults to true
//            pushRedirect - Whether to add a browser history entry upon redirect. Defaults to false.
//         replaceRedirect - Whether to replace URL without adding a browser history entry upon redirect. Defaults to true.
//     skipOuterContainers - When pjax containers are nested and this option is true,
//                           the closest pjax block will handle the event. Otherwise, the top
//                           container will handle the event. Defaults to false.
// ieRedirectCompatibility - Whether to add `X-Ie-Redirect-Compatibility` header for the request on IE.
//                           See https://github.com/yiisoft/jquery-pjax/issues/37
//
// For convenience the second parameter can be either the container or
// the options object.
//
// Returns the jQuery object
    function fnPjax(selector, container, options) {
        options = optionsFor(container, options)
        var handler = function(event) {
            var opts = options
            if (!opts.container) {
                opts = $.extend({history: true}, options)
                opts.container = $(this).attr('data-pjax')
            }
            handleClick(event, opts)
        }
        $(selector).removeClass('data-pjax');
        return this
            .off('click.pjax', selector, handler)
            .on('click.pjax', selector, handler);
    }

// Public: pjax on click handler
//
// Exported as $.pjax.click.
//
// event   - "click" jQuery.Event
// options - pjax options
//
// If the click event target has 'data-pjax="0"' attribute, the event is ignored, and no pjax call is made.
//
// Examples
//
//   $(document).on('click', 'a', $.pjax.click)
//   // is the same as
//   $(document).pjax('a')
//
// Returns nothing.
    function handleClick(event, container, options) {
        options = optionsFor(container, options)

        var link = event.currentTarget
        var $link = $(link)

        // Ignore links with data-pjax="0"
        if (parseInt($link.data('pjax')) === 0) {
            return
        }

        if (link.tagName.toUpperCase() !== 'A')
            throw "$.fn.pjax or $.pjax.click requires an anchor element"

        // Middle click, cmd click, and ctrl click should open
        // links in a new tab as normal.
        if ( event.which > 1 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey )
            return

        // Ignore cross origin links
        if ( location.protocol !== link.protocol || location.hostname !== link.hostname )
            return

        // Ignore case when a hash is being tacked on the current URL
        if ( link.href.indexOf('#') > -1 && stripHash(link) == stripHash(location) )
            return

        // Ignore event with default prevented
        if (event.isDefaultPrevented())
            return

        // FIX: Prevent multiple rapid clicks
        if ($.pjax.clicksInProgress && $.pjax.clicksInProgress[link.href]) {
            event.preventDefault()
            return
        }

        // Track this click
        if (!$.pjax.clicksInProgress) $.pjax.clicksInProgress = {}
        $.pjax.clicksInProgress[link.href] = true

        // Clear click tracking after a delay
        setTimeout(function() {
            if ($.pjax.clicksInProgress) {
                delete $.pjax.clicksInProgress[link.href]
            }
        }, 1000)

        var defaults = {
            url: link.href,
            container: $link.attr('data-pjax'),
            target: link
        }

        var opts = $.extend({}, defaults, options)
        var clickEvent = $.Event('pjax:click')
        $link.trigger(clickEvent, [opts])

        if (!clickEvent.isDefaultPrevented()) {
            pjax(opts)
            event.preventDefault()
            $link.trigger('pjax:clicked', [opts])
        }
    }

// Public: pjax on form submit handler
//
// Exported as $.pjax.submit
//
// event   - "click" jQuery.Event
// options - pjax options
//
// Examples
//
//  $(document).on('submit', 'form', function(event) {
//    $.pjax.submit(event, '[data-pjax-container]')
//  })
//
// Returns nothing.
    function handleSubmit(event, container, options) {
        // check result of previous handlers
        if (event.result === false)
            return false;

        options = optionsFor(container, options)

        var form = event.currentTarget
        var $form = $(form)

        if (form.tagName.toUpperCase() !== 'FORM')
            throw "$.pjax.submit requires a form element"

        var defaults = {
            type: ($form.attr('method') || 'GET').toUpperCase(),
            url: $form.attr('action'),
            container: $form.attr('data-pjax'),
            target: form
        }

        if (defaults.type !== 'GET' && window.FormData !== undefined) {
            defaults.data = new FormData(form)
            defaults.processData = false
            defaults.contentType = false
        } else {
            // Can't handle file uploads, exit
            if ($form.find(':file').length) {
                return
            }

            // Fallback to manually serializing the fields
            defaults.data = $form.serializeArray()
        }

        pjax($.extend({}, defaults, options))

        event.preventDefault()
    }

// Loads a URL with ajax, puts the response body inside a container,
// then pushState()'s the loaded URL.
//
// Works just like $.ajax in that it accepts a jQuery ajax
// settings object (with keys like url, type, data, etc).
//
// Accepts these extra keys:
//
// container - String selector for where to stick the response body.
//      push - Whether to pushState the URL. Defaults to true (of course).
//   replace - Want to use replaceState instead? That's cool.
//
// Use it just like $.ajax:
//
//   var xhr = $.pjax({ url: this.href, container: '#main' })
//   console.log( xhr.readyState )
//
// Returns whatever $.ajax returns.
    function pjax(options) {
        options = $.extend(true, {}, $.ajaxSettings, pjax.defaults, options)

        // FIX: Safari/Firefox issues with pushState
        const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
        const isFirefox = navigator.userAgent.indexOf('Firefox') > -1;

        if (isSafari || isFirefox) {
            // Force safer options for problematic browsers
            options.push = false;
            options.replace = true;
        }

        if ($.isFunction(options.url)) {
            options.url = options.url()
        }

        var hash = parseURL(options.url).hash

        var containerType = $.type(options.container)
        if (containerType !== 'string') {
            throw "expected string value for 'container' option; got " + containerType
        }
        var context = options.context = $(options.container)
        if (!context.length) {
            throw "the container selector '" + options.container + "' did not match anything"
        }

        // We want the browser to maintain two separate internal caches: one
        // for pjax'd partial page loads and one for normal page loads.
        // Without adding this secret parameter, some browsers will often
        // confuse the two.
        if (!options.data) options.data = {}
        if ($.isArray(options.data)) {
            options.data = $.grep(options.data, function(obj) { return '_pjax' !== obj.name })
            options.data.push({name: '_pjax', value: options.container})
        } else {
            options.data._pjax = options.container
        }

        function fire(type, args, props) {
            if (!props) props = {}
            props.relatedTarget = options.target
            var event = $.Event(type, props)
            context.trigger(event, args)
            return !event.isDefaultPrevented()
        }

        var timeoutTimer

        options.beforeSend = function(xhr, settings) {
            // No timeout for non-GET requests
            // Its not safe to request the resource again with a fallback method.
            if (settings.type !== 'GET') {
                settings.timeout = 0
            }

            xhr.setRequestHeader('X-PJAX', 'true')
            xhr.setRequestHeader('X-PJAX-Container', options.container)

            if (settings.ieRedirectCompatibility) {
                var ua = window.navigator.userAgent
                if (ua.indexOf('MSIE ') > 0 || ua.indexOf('Trident/') > 0 || ua.indexOf('Edge/') > 0) {
                    xhr.setRequestHeader('X-Ie-Redirect-Compatibility', 'true')
                }
            }

            if (!fire('pjax:beforeSend', [xhr, settings]))
                return false

            if (settings.timeout > 0) {
                timeoutTimer = setTimeout(function() {
                    if (fire('pjax:timeout', [xhr, options]))
                        xhr.abort('timeout')
                }, settings.timeout)

                // Clear timeout setting so jquerys internal timeout isn't invoked
                settings.timeout = 0
            }

            var url = parseURL(settings.url)
            if (hash) url.hash = hash
            options.requestUrl = stripInternalParams(url)

            if (typeof (options.async) !== 'undefined' && !options.async) {
                fire('pjax:start', [xhr, options])
                fire('pjax:send', [xhr, options])
            }
        }

        options.complete = function(xhr, textStatus) {
            if (timeoutTimer)
                clearTimeout(timeoutTimer)

            fire('pjax:complete', [xhr, textStatus, options])

            fire('pjax:end', [xhr, options])

            // FIX: Clear click tracking on complete
            if ($.pjax.clicksInProgress && options.url) {
                const url = parseURL(options.url);
                if ($.pjax.clicksInProgress[url.href]) {
                    delete $.pjax.clicksInProgress[url.href];
                }
            }
        }

        options.error = function(xhr, textStatus, errorThrown) {
            var container = extractContainer("", xhr, options)
            // Check redirect status code
            var redirect = xhr.status >= 301 && xhr.status <= 303
            // Do not fire pjax::error in case of redirect
            var allowed = redirect || fire('pjax:error', [xhr, textStatus, errorThrown, options])
            if (redirect || options.type == 'GET' && textStatus !== 'abort' && allowed) {
                if (options.replaceRedirect) {
                    locationReplace(container.url)
                } else if (options.pushRedirect) {
                    window.history.pushState(null, "", container.url)
                    window.location.replace(container.url)
                }
            }
        }

        options.success = function(data, status, xhr) {
            var previousState = pjax.state

            // If $.pjax.defaults.version is a function, invoke it first.
            // Otherwise it can be a static string.
            var currentVersion = typeof $.pjax.defaults.version === 'function' ?
                $.pjax.defaults.version() :
                $.pjax.defaults.version

            var latestVersion = xhr.getResponseHeader('X-PJAX-Version')

            var container = extractContainer(data, xhr, options)

            var url = parseURL(container.url)
            if (hash) {
                url.hash = hash
                container.url = url.href
            }

            // If there is a layout version mismatch, hard load the new url
            if (currentVersion && latestVersion && currentVersion !== latestVersion) {
                locationReplace(container.url)
                return
            }

            // If the new response is missing a body, hard load the page
            if (!container.contents) {
                locationReplace(container.url)
                return
            }

            pjax.state = {
                id: options.id || uniqueId(),
                url: container.url,
                title: container.title,
                container: options.container,
                fragment: options.fragment,
                timeout: options.timeout,
                cache: options.cache
            }

            // FIX: Move the history update to after the DOM has been updated
            // This is the key fix for Safari/Firefox

            // Only blur the focus if the focused element is within the container.
            var blurFocus = $.contains(context, document.activeElement)

            // Clear out any focused controls before inserting new page contents.
            if (blurFocus) {
                try {
                    document.activeElement.blur()
                } catch (e) { /* ignore */ }
            }

            if (container.title) document.title = container.title

            fire('pjax:beforeReplace', [container.contents, options], {
                state: pjax.state,
                previousState: previousState
            })
            context.html(container.contents)

            // FF bug: Won't autofocus fields that are inserted via JS.
            // This behavior is incorrect. So if theres no current focus, autofocus
            // the last field.
            //
            // http://www.w3.org/html/wg/drafts/html/master/forms.html
            var autofocusEl = context.find('input[autofocus], textarea[autofocus]').last()[0]
            if (autofocusEl && document.activeElement !== autofocusEl) {
                autofocusEl.focus()
            }

            executeScriptTags(container.scripts, context)
            loadLinkTags(container.links)

            // FIX: Wait for a short delay before updating history
            // This gives Safari/Firefox time to process the DOM updates
            setTimeout(function() {
                if (options.history) {
                    if (options.replace) {
                        window.history.replaceState(pjax.state, container.title, container.url)
                    } else if (options.push) {
                        // Skip the history update if it's only a hash change
                        if (container.url !== window.location.href) {
                            window.history.pushState(null, container.title, container.url)
                        }
                    }
                }

                // Scroll handling after the history change
                if (typeof options.scrollTo === 'function') {
                    var scrollTo = options.scrollTo(context, hash)
                } else {
                    var scrollTo = options.scrollTo
                    // Ensure browser scrolls to the element referenced by the URL anchor
                    if (hash || true === scrollTo) {
                        var name = decodeURIComponent(hash.slice(1))
                        var target = true === scrollTo ? context : (document.getElementById(name) || document.getElementsByName(name)[0])
                        if (target) scrollTo = $(target).offset().top
                    }
                }

                if (typeof options.scrollOffset === 'function')
                    var scrollOffset = options.scrollOffset(scrollTo)
                else
                    var scrollOffset = options.scrollOffset

                if (typeof scrollTo === 'number') {
                    scrollTo = scrollTo + scrollOffset;
                    if (scrollTo < 0) scrollTo = 0
                    $(window).scrollTop(scrollTo)
                }

                fire('pjax:success', [data, status, xhr, options])
            }, 50); // Small delay for Safari/Firefox
        }

        // Initialize pjax.state for the initial page load. Assume we're
        // using the container and options of the link we're loading for the
        // back button to the initial page. This ensures good back button
        // behavior.
        if (!pjax.state) {
            pjax.state = {
                id: uniqueId(),
                url: window.location.href,
                title: document.title,
                container: options.container,
                fragment: options.fragment,
                timeout: options.timeout,
                cache: options.cache
            }
            if (options.history)
                window.history.replaceState(pjax.state, document.title)
        }

        // New request can not override the existing one when option skipOuterContainers is set to true
        if (pjax.xhr && pjax.xhr.readyState < 4 && pjax.options.skipOuterContainers) {
            return
        }
        // Cancel the current request if we're already pjaxing
        abortXHR(pjax.xhr)

        pjax.options = options
        var xhr = pjax.xhr = $.ajax(options)

        if (xhr.readyState > 0) {
            // FIX: Move history updates for Safari/Firefox
            // Don't update history until after the request completes
            if ((isSafari || isFirefox) && options.push && !options.replace) {
                // Cache current container element before replacing it
                cachePush(pjax.state.id, [options.container, cloneContents(context)])

                // Don't update URL until success callback
            } else if (options.history && (options.push && !options.replace)) {
                // Cache current container element before replacing it
                cachePush(pjax.state.id, [options.container, cloneContents(context)])

                window.history.pushState(null, "", options.requestUrl)
            }

            if (typeof (options.async) === 'undefined' || options.async) {
                fire('pjax:start', [xhr, options])
                fire('pjax:send', [xhr, options])
            }
        }

        return pjax.xhr
    }

// Public: Reload current page with pjax.
//
// Returns whatever $.pjax returns.
    function pjaxReload(container, options) {
        var defaults = {
            url: window.location.href,
            push: false,
            replace: true,
            scrollTo: false
        }

        return pjax($.extend(defaults, optionsFor(container, options)))
    }

// Internal: Hard replace current state with url.
//
// Work for around WebKit
//   https://bugs.webkit.org/show_bug.cgi?id=93506
//
// Returns nothing.
    function locationReplace(url) {
        if (!pjax.options.history) return
        window.history.replaceState(null, "", pjax.state.url)
        window.location.replace(url)
    }


    var initialPop = true
    var initialURL = window.location.href
    var initialState = window.history.state

// Initialize $.pjax.state if possible
// Happens when reloading a page and coming forward from a different
// session history.
    if (initialState && initialState.container) {
        pjax.state = initialState
    }

// Non-webkit browsers don't fire an initial popstate event
    if ('state' in window.history) {
        initialPop = false
    }

// popstate handler takes care of the back and forward buttons
//
// You probably shouldn't use pjax on pages with other pushState
// stuff yet.
    function onPjaxPopstate(event) {

        // Hitting back or forward should override any pending PJAX request.
        if (!initialPop) {
            abortXHR(pjax.xhr)
        }

        var previousState = pjax.state
        var state = event.state
        var direction

        if (state && state.container) {
            // When coming forward from a separate history session, will get an
            // initial pop with a state we are already at. Skip reloading the current
            // page.
            if (initialPop && initialURL == state.url) return

            if (previousState) {
                // If popping back to the same state, just skip.
                // Could be clicking back from hashchange rather than a pushState.
                if (previousState.id === state.id) return

                // Since state IDs always increase, we can deduce the navigation direction
                direction = previousState.id < state.id ? 'forward' : 'back'
            }

            var cache = cacheMapping[state.id] || []
            var containerSelector = cache[0] || state.container
            var container = $(containerSelector), contents = cache[1]

            if (container.length) {
                var options = {
                    id: state.id,
                    url: state.url,
                    container: containerSelector,
                    push: false,
                    fragment: state.fragment,
                    timeout: state.timeout,
                    cache: state.cache,
                    scrollTo: false
                }

                if (previousState && options.cache) {
                    // Cache current container before replacement and inform the
                    // cache which direction the history shifted.
                    cachePop(direction, previousState.id, [containerSelector, cloneContents(container)])
                }

                var popstateEvent = $.Event('pjax:popstate', {
                    state: state,
                    direction: direction
                })
                container.trigger(popstateEvent)

                if (contents) {
                    container.trigger('pjax:start', [null, options])

                    pjax.state = state
                    if (state.title) document.title = state.title
                    var beforeReplaceEvent = $.Event('pjax:beforeReplace', {
                        state: state,
                        previousState: previousState
                    })
                    container.trigger(beforeReplaceEvent, [contents, options])
                    container.html(contents)

                    container.trigger('pjax:end', [null, options])
                } else {
                    pjax(options)
                }

                // Force reflow/relayout before the browser tries to restore the
                // scroll position.
                container[0].offsetHeight // eslint-disable-line no-unused-expressions
            } else {
                locationReplace(location.href)
            }
        }
        initialPop = false
    }

// Fallback version of main pjax function for browsers that don't
// support pushState.
//
// Returns nothing since it retriggers a hard form submission.
    function fallbackPjax(options) {
        var url = $.isFunction(options.url) ? options.url() : options.url,
            method = options.type ? options.type.toUpperCase() : 'GET'

        var form = $('<form>', {
            method: method === 'GET' ? 'GET' : 'POST',
            action: url,
            style: 'display:none'
        })

        if (method !== 'GET' && method !== 'POST') {
            form.append($('<input>', {
                type: 'hidden',
                name: '_method',
                value: method.toLowerCase()
            }))
        }

        var data = options.data
        if (typeof data === 'string') {
            $.each(data.split('&'), function(index, value) {
                var pair = value.split('=')
                form.append($('<input>', {type: 'hidden', name: pair[0], value: pair[1]}))
            })
        } else if ($.isArray(data)) {
            $.each(data, function(index, value) {
                form.append($('<input>', {type: 'hidden', name: value.name, value: value.value}))
            })
        } else if (typeof data === 'object') {
            var key
            for (key in data)
                form.append($('<input>', {type: 'hidden', name: key, value: data[key]}))
        }

        $(document.body).append(form)
        form.submit()
    }

// Internal: Abort an XmlHttpRequest if it hasn't been completed,
// also removing its event handlers.
    function abortXHR(xhr) {
        if ( xhr && xhr.readyState < 4) {
            xhr.onreadystatechange = $.noop
            xhr.abort()
        }
    }

// Internal: Generate unique id for state object.
//
// Use a timestamp instead of a counter since ids should still be
// unique across page loads.
//
// Returns Number.
    function uniqueId() {
        return (new Date).getTime()
    }

    function cloneContents(container) {
        var cloned = container.clone()
        // Unmark script tags as already being eval'd so they can get executed again
        // when restored from cache. HAXX: Uses jQuery internal method.
        cloned.find('script').each(function(){
            if (!this.src) $._data(this, 'globalEval', false)
        })
        return cloned.contents()
    }

// Internal: Strip internal query params from parsed URL.
//
// Returns sanitized url.href String.
    function stripInternalParams(url) {
        url.search = url.search.replace(/([?&])(_pjax|_)=[^&]*/g, '').replace(/^&/, '')
        return url.href.replace(/\?($|#)/, '$1')
    }

// Internal: Parse URL components and returns a Locationish object.
//
// url - String URL
//
// Returns HTMLAnchorElement that acts like Location.
    function parseURL(url) {
        var a = document.createElement('a')
        a.href = url
        return a
    }

// Internal: Return the `href` component of given URL object with the hash
// portion removed.
//
// location - Location or HTMLAnchorElement
//
// Returns String
    function stripHash(location) {
        return location.href.replace(/#.*/, '')
    }

// Internal: Build options Object for arguments.
//
// For convenience the first parameter can be either the container or
// the options object.
//
// Examples
//
//   optionsFor('#container')
//   // => {container: '#container'}
//
//   optionsFor('#container', {push: true})
//   // => {container: '#container', push: true}
//
//   optionsFor({container: '#container', push: true})
//   // => {container: '#container', push: true}
//
// Returns options Object.
    function optionsFor(container, options) {
        if (container && options) {
            options = $.extend({}, options)
            options.container = container
            return options
        } else if ($.isPlainObject(container)) {
            return container
        } else {
            return {container: container}
        }
    }

// Internal: Filter and find all elements matching the selector.
//
// Where $.fn.find only matches descendants, findAll will test all the
// top level elements in the jQuery object as well.
//
// elems    - jQuery object of Elements
// selector - String selector to match
//
// Returns a jQuery object.
    function findAll(elems, selector) {
        return elems.filter(selector).add(elems.find(selector))
    }

    function parseHTML(html) {
        return $.parseHTML(html, document, true)
    }

// Internal: Extracts container and metadata from response.
//
// 1. Extracts X-PJAX-URL header if set
// 2. Extracts inline <title> tags
// 3. Builds response Element and extracts fragment if set
//
// data    - String response data
// xhr     - XHR response
// options - pjax options Object
//
// Returns an Object with url, title, and contents keys.
    function extractContainer(data, xhr, options) {
        var obj = {}, fullDocument = /<html/i.test(data)

        // Prefer X-PJAX-URL header if it was set, otherwise fallback to
        // using the original requested url.
        var serverUrl = xhr.getResponseHeader('X-PJAX-URL')
        obj.url = serverUrl ? stripInternalParams(parseURL(serverUrl)) : options.requestUrl

        var $head, $body
        // Attempt to parse response html into elements
        if (fullDocument) {
            $body = $(parseHTML(data.match(/<body[^>]*>([\s\S.]*)<\/body>/i)[0]))
            var head = data.match(/<head[^>]*>([\s\S.]*)<\/head>/i)
            $head = head != null ? $(parseHTML(head[0])) : $body
        } else {
            $head = $body = $(parseHTML(data))
        }

        // If response data is empty, return fast
        if ($body.length === 0)
            return obj

        // If there's a <title> tag in the header, use it as
        // the page's title.
        obj.title = findAll($head, 'title').last().text()

        if (options.fragment) {
            var $fragment = $body
            // If they specified a fragment, look for it in the response
            // and pull it out.
            if (options.fragment !== 'body') {
                $fragment = findAll($fragment, options.fragment).first()
            }

            if ($fragment.length) {
                obj.contents = options.fragment === 'body' ? $fragment : $fragment.contents()

                // If there's no title, look for data-title and title attributes
                // on the fragment
                if (!obj.title)
                    obj.title = $fragment.attr('title') || $fragment.data('title')
            }

        } else if (!fullDocument) {
            obj.contents = $body
        }

        // Clean up any <title> tags
        if (obj.contents) {
            // Remove any parent title elements
            obj.contents = obj.contents.not(function() { return $(this).is('title') })

            // Then scrub any titles from their descendants
            obj.contents.find('title').remove()

            // Gather all script elements
            obj.scripts = findAll(obj.contents, 'script').remove()
            obj.contents = obj.contents.not(obj.scripts)

            // Gather all link[href] elements
            obj.links = findAll(obj.contents, 'link[href]').remove()
            obj.contents = obj.contents.not(obj.links)
        }

        // Trim any whitespace off the title
        if (obj.title) obj.title = $.trim(obj.title)

        return obj
    }

// Load an execute scripts using standard script request.
//
// Avoids jQuery's traditional $.getScript which does a XHR request and
// globalEval.
//
// scripts - jQuery object of script Elements
// context - jQuery object whose context is `document` and has a selector
//
// Returns nothing.
    function executeScriptTags(scripts, context) {
        if (!scripts) return

        var existingScripts = $('script[src]')

        var cb = function (next) {
            var src = this.src
            var matchedScripts = existingScripts.filter(function () {
                return this.src === src
            })

            if (matchedScripts.length) {
                next()
                return
            }

            if (src) {
                $.getScript(src).done(next).fail(next)
                document.head.appendChild(this)
            } else {
                context.append(this)
                next()
            }
        }

        var i = 0
        var next = function () {
            if (i >= scripts.length) {
                return
            }
            var script = scripts[i]
            i++
            cb.call(script, next)
        }
        next()
    }

// Load an links using standard request.
//
// links - jQuery object of link Elements
//
// Returns nothing.
    function loadLinkTags(links) {
        if (!links) return

        var existingLinks = $('link[href]')

        links.each(function() {
            var href = this.href,
                alreadyLoadedLinks = existingLinks.filter(function() {
                    return this.href === href
                })
            if (alreadyLoadedLinks.length) return

            document.head.appendChild(this)
        })
    }

// Internal: History DOM caching class.
    var cacheMapping      = {}
    var cacheForwardStack = []
    var cacheBackStack    = []

// Push previous state id and container contents into the history
// cache. Should be called in conjunction with `pushState` to save the
// previous container contents.
//
// id    - State ID Number
// value - DOM Element to cache
//
// Returns nothing.
    function cachePush(id, value) {
        if (!pjax.options.cache) {
            return
        }
        cacheMapping[id] = value
        cacheBackStack.push(id)

        // Remove all entries in forward history stack after pushing a new page.
        trimCacheStack(cacheForwardStack, 0)

        // Trim back history stack to max cache length.
        trimCacheStack(cacheBackStack, pjax.defaults.maxCacheLength)
    }

// Shifts cache from directional history cache. Should be
// called on `popstate` with the previous state id and container
// contents.
//
// direction - "forward" or "back" String
// id        - State ID Number
// value     - DOM Element to cache
//
// Returns nothing.
    function cachePop(direction, id, value) {
        var pushStack, popStack
        cacheMapping[id] = value

        if (direction === 'forward') {
            pushStack = cacheBackStack
            popStack  = cacheForwardStack
        } else {
            pushStack = cacheForwardStack
            popStack  = cacheBackStack
        }

        pushStack.push(id)
        id = popStack.pop()
        if (id) delete cacheMapping[id]

        // Trim whichever stack we just pushed to to max cache length.
        trimCacheStack(pushStack, pjax.defaults.maxCacheLength)
    }

// Trim a cache stack (either cacheBackStack or cacheForwardStack) to be no
// longer than the specified length, deleting cached DOM elements as necessary.
//
// stack  - Array of state IDs
// length - Maximum length to trim to
//
// Returns nothing.
    function trimCacheStack(stack, length) {
        while (stack.length > length)
            delete cacheMapping[stack.shift()]
    }

// Public: Find version identifier for the initial page load.
//
// Returns String version or undefined.
    function findVersion() {
        return $('meta').filter(function() {
            var name = $(this).attr('http-equiv')
            return name && name.toUpperCase() === 'X-PJAX-VERSION'
        }).attr('content')
    }

// Install pjax functions on $.pjax to enable pushState behavior.
//
// Does nothing if already enabled.
//
// Examples
//
//     $.pjax.enable()
//
// Returns nothing.
    function enable() {
        $.fn.pjax = fnPjax
        $.pjax = pjax
        $.pjax.enable = $.noop
        $.pjax.disable = disable
        $.pjax.click = handleClick
        $.pjax.submit = handleSubmit
        $.pjax.reload = pjaxReload
        $.pjax.clicksInProgress = {}  // FIX: Add tracking for clicks in progress
        $.pjax.defaults = {
            history: true,
            cache: true,
            timeout: 650,
            push: true,
            replace: false,
            type: 'GET',
            dataType: 'html',
            scrollTo: 0,
            scrollOffset: 0,
            maxCacheLength: 20,
            version: findVersion,
            pushRedirect: false,
            replaceRedirect: true,
            skipOuterContainers: false,
            ieRedirectCompatibility: true
        }
        $(window).on('popstate.pjax', onPjaxPopstate)
    }

// Disable pushState behavior.
//
// This is the case when a browser doesn't support pushState. It is
// sometimes useful to disable pushState for debugging on a modern
// browser.
//
// Examples
//
//     $.pjax.disable()
//
// Returns nothing.
    function disable() {
        $.fn.pjax = function() { return this }
        $.pjax = fallbackPjax
        $.pjax.enable = enable
        $.pjax.disable = $.noop
        $.pjax.click = $.noop
        $.pjax.submit = $.noop
        $.pjax.reload = function() { window.location.reload() }

        $(window).off('popstate.pjax', onPjaxPopstate)
    }


// Add the state property to jQuery's event object so we can use it in
// $(window).bind('popstate')
    if ($.event.props && $.inArray('state', $.event.props) < 0) {
        $.event.props.push('state')
    } else if (!('state' in $.Event.prototype)) {
        $.event.addProp('state')
    }

// Is pjax supported by this browser?
    $.support.pjax =
        window.history && window.history.pushState && window.history.replaceState &&
        // pushState isn't reliable on iOS until 5.
        !navigator.userAgent.match(/((iPod|iPhone|iPad).+\bOS\s+[1-4]\D|WebApps\/.+CFNetwork)/)

    if ($.support.pjax) {
        enable()
    } else {
        disable()
    }

})(jQuery);