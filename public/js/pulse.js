/*! Pulse Analytics browser SDK v1.0.0 — dependency-free, no build step.
 *
 * Quick start (auto-init from the script tag):
 *   <script src="/js/pulse.js" data-key="pk_..." data-endpoint="https://host/api/v1/events"></script>
 *
 * Manual:
 *   pulse.init({ key: 'pk_...', endpoint: 'https://host/api/v1/events' });
 *   pulse.track('add_to_cart', { item_id: 'burger' });
 *   pulse.identify('cust_123');
 */
(function (window, document) {
  'use strict';

  var SESSION_MS = 30 * 60 * 1000; // a session ends after 30 minutes of inactivity
  var DEFAULTS = {
    autoPageviews: true,     // send page_view on load and on SPA route changes
    captureQuery: false,     // strip ?query from url/referrer unless enabled (avoids leaking PII)
    flushInterval: 2000,     // ms to wait before sending a partial batch
    maxBatch: 20,            // events per request (server max is 50)
    maxQueue: 1000,          // oldest events are dropped beyond this
    respectDoNotTrack: false,
    debug: false
  };

  var cfg = null;
  var queue = [];
  var memory = {};      // fallback when localStorage is unavailable
  var state = { aid: null };
  var timer = null;
  var inFlight = false;
  var failures = 0;
  var lastPath = null;
  var optedOut = false;

  // ---------- storage helpers (never throw) ----------
  function get(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return memory[key] === undefined ? null : memory[key]; }
  }
  function set(key, value) {
    try { window.localStorage.setItem(key, value); } catch (e) { memory[key] = value; }
  }
  function del(key) {
    try { window.localStorage.removeItem(key); } catch (e) { delete memory[key]; }
  }
  function readCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }
  function writeCookie(name, value) {
    // Readable by the server so server-side events can be attributed to this visitor.
    document.cookie = name + '=' + encodeURIComponent(value) + '; max-age=31536000; path=/; SameSite=Lax' +
      (window.location.protocol === 'https:' ? '; Secure' : '');
  }
  function log() {
    if (cfg && cfg.debug && window.console) console.log.apply(console, ['[pulse]'].concat([].slice.call(arguments)));
  }

  // ---------- identity ----------
  function uuid() {
    var c = window.crypto;
    if (c && c.randomUUID) return c.randomUUID();
    var b = new Uint8Array(16), i;
    if (c && c.getRandomValues) c.getRandomValues(b);
    else for (i = 0; i < 16; i++) b[i] = Math.floor(Math.random() * 256);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = [];
    for (i = 0; i < 16; i++) h.push((b[i] + 0x100).toString(16).slice(1));
    return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' + h.slice(6, 8).join('') + '-' +
      h.slice(8, 10).join('') + '-' + h.slice(10).join('');
  }

  function loadAnonymousId() {
    var id = get('pa_aid') || readCookie('pa_aid') || uuid();
    set('pa_aid', id);
    writeCookie('pa_aid', id);
    return id;
  }

  function sessionId() {
    var now = Date.now(), s = null;
    try { s = JSON.parse(get('pa_sid') || 'null'); } catch (e) { s = null; }
    if (!s || !s.id || now - s.last > SESSION_MS) s = { id: uuid(), last: now };
    s.last = now;
    set('pa_sid', JSON.stringify(s));
    return s.id;
  }

  // ---------- queue (persisted so a reload/crash doesn't lose events) ----------
  function persist() { set('pa_queue', JSON.stringify(queue)); }

  function loadQueue() {
    try {
      var saved = JSON.parse(get('pa_queue') || '[]');
      if (Array.isArray(saved)) queue = saved;
    } catch (e) { queue = []; }
  }

  function remove(batch) {
    var ids = {};
    batch.forEach(function (e) { ids[e.event_id] = true; });
    queue = queue.filter(function (e) { return !ids[e.event_id]; });
    persist();
  }

  function schedule(delay) {
    if (timer) return;
    timer = setTimeout(function () { timer = null; flush(); }, delay === undefined ? cfg.flushInterval : delay);
  }

  function retryLater() {
    failures++;
    // exponential backoff 2s, 4s, 8s ... capped at 60s, plus jitter
    schedule(Math.min(60000, 1000 * Math.pow(2, failures)) + Math.random() * 500);
  }

  // ---------- sending ----------
  function flush(useBeacon) {
    if (!cfg || optedOut || queue.length === 0) return;

    if (useBeacon) {
      // Page is going away: fire-and-forget. If the browser accepts the beacon we drop the
      // events; if delivery still fails, nothing is lost that matters (server dedupes by event_id).
      var rounds = 0;
      while (queue.length && rounds++ < 5) {
        var b = queue.slice(0, cfg.maxBatch);
        var sent = false;
        try {
          sent = !!(navigator.sendBeacon && navigator.sendBeacon(cfg.endpoint,
            new Blob([payload(b)], { type: 'text/plain;charset=UTF-8' })));
        } catch (e) { sent = false; }
        if (!sent) return; // keep in persisted queue; next page load will resend
        remove(b);
      }
      return;
    }

    if (inFlight) return;

    var batch = queue.slice(0, cfg.maxBatch);
    var body = payload(batch);
    inFlight = true;

    // "text/plain" keeps this a CORS "simple request": no preflight round trip.
    fetch(cfg.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
      body: body,
      keepalive: body.length < 60000,
      credentials: 'omit'
    }).then(function (res) {
      inFlight = false;
      if (res.ok) {
        failures = 0;
        remove(batch);
        if (queue.length) flush();
      } else if (res.status >= 400 && res.status < 500 && res.status !== 429) {
        log('batch rejected permanently', res.status);
        remove(batch); // retrying a bad request can't succeed
        if (queue.length) flush();
      } else {
        retryLater(); // 429 / 5xx
      }
    }).catch(function () {
      inFlight = false;
      retryLater(); // offline / DNS / CORS failure
    });
  }

  function payload(batch) {
    return JSON.stringify({ key: cfg.key, sent_at: new Date().toISOString(), events: batch });
  }

  // ---------- tracking ----------
  function cleanUrl(href) {
    if (!href) return undefined;
    try {
      var u = new URL(href);
      return u.origin + u.pathname + (cfg.captureQuery ? u.search : '');
    } catch (e) { return undefined; }
  }

  function build(name, props) {
    var loc = window.location;
    var e = {
      event_id: uuid(),
      name: name,
      anonymous_id: state.aid,
      user_id: get('pa_uid') || undefined,
      session_id: sessionId(),
      url: loc.origin + loc.pathname + (cfg.captureQuery ? loc.search : ''),
      path: loc.pathname,
      referrer: cleanUrl(document.referrer),
      timestamp: new Date().toISOString()
    };
    if (props && typeof props === 'object' && !Array.isArray(props)) e.properties = props;
    return e;
  }

  function track(name, props) {
    if (!cfg || optedOut) return;
    if (typeof name !== 'string' || !name) return;
    queue.push(build(name, props));
    if (queue.length > cfg.maxQueue) queue.splice(0, queue.length - cfg.maxQueue);
    persist();
    if (queue.length >= cfg.maxBatch) flush(); else schedule();
  }

  function page(props) {
    lastPath = window.location.pathname + (cfg && cfg.captureQuery ? window.location.search : '');
    var p = { title: document.title };
    if (props) for (var k in props) p[k] = props[k];
    track('page_view', p);
  }

  function maybePage() {
    var p = window.location.pathname + (cfg.captureQuery ? window.location.search : '');
    if (p !== lastPath) page();
  }

  function hookHistory() {
    ['pushState', 'replaceState'].forEach(function (m) {
      var orig = window.history[m];
      window.history[m] = function () {
        var r = orig.apply(this, arguments);
        setTimeout(maybePage, 0);
        return r;
      };
    });
    window.addEventListener('popstate', function () { setTimeout(maybePage, 0); });
  }

  function identify(userId, traits) {
    if (!cfg || userId === undefined || userId === null || userId === '') return;
    set('pa_uid', String(userId));
    track('identify', traits);
  }

  function reset() {
    del('pa_uid');
    del('pa_sid');
    state.aid = uuid();
    set('pa_aid', state.aid);
    writeCookie('pa_aid', state.aid);
  }

  function optOut() {
    optedOut = true;
    set('pa_optout', '1');
    queue = [];
    persist();
  }

  function init(options) {
    if (cfg) return; // idempotent
    if (!options || !options.key || !options.endpoint) {
      if (window.console) console.warn('[pulse] init requires {key, endpoint}');
      return;
    }
    cfg = {};
    for (var k in DEFAULTS) cfg[k] = DEFAULTS[k];
    for (var o in options) cfg[o] = options[o];

    optedOut = get('pa_optout') === '1' || (cfg.respectDoNotTrack && navigator.doNotTrack === '1');
    if (optedOut) return;

    state.aid = loadAnonymousId();
    loadQueue();

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') flush(true);
    });
    window.addEventListener('pagehide', function () { flush(true); });

    if (cfg.autoPageviews) {
      hookHistory();
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { page(); });
      else page();
    }
    if (queue.length) schedule(0); // resend anything left over from a previous page
  }

  window.pulse = {
    init: init,
    track: track,
    page: page,
    identify: identify,
    reset: reset,
    optOut: optOut,
    flush: function (useBeacon) { flush(!!useBeacon); }
  };

  // Auto-init from <script data-key data-endpoint>.
  var tag = document.currentScript;
  if (tag && tag.getAttribute('data-key')) {
    init({
      key: tag.getAttribute('data-key'),
      endpoint: tag.getAttribute('data-endpoint') || '/api/v1/events',
      autoPageviews: tag.getAttribute('data-auto-pageviews') !== 'false',
      captureQuery: tag.getAttribute('data-capture-query') === 'true',
      debug: tag.getAttribute('data-debug') === 'true'
    });
  }
})(window, document);
