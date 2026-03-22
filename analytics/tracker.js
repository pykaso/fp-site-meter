(function () {
  var sc = document.currentScript;
  if (!sc) {
    return;
  }
  var site = (sc.getAttribute('data-site') || '').trim();
  if (!site) {
    return;
  }

  var endpoint = new URL('track.php', sc.src).href;

  function visitorId() {
    var key = 'analytics_vid';
    try {
      var existing = localStorage.getItem(key);
      if (existing) {
        return existing;
      }
      var id;
      if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        id = crypto.randomUUID();
      } else {
        id = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
          var r = (Math.random() * 16) | 0;
          var v = c === 'x' ? r : (r & 0x3) | 0x8;
          return v.toString(16);
        });
      }
      localStorage.setItem(key, id);
      return id;
    } catch (e) {
      return 'anon';
    }
  }

  var vid = visitorId();

  function payload(eventType, eventName) {
    return JSON.stringify({
      site: site,
      event_type: eventType,
      event_name: eventName,
      page_url: location.href,
      referrer: document.referrer || '',
      visitor_id: vid,
      user_agent: navigator.userAgent || '',
    });
  }

  function send(eventType, eventName) {
    var body = payload(eventType, eventName);
    if (typeof navigator.sendBeacon === 'function') {
      var blob = new Blob([body], { type: 'application/json' });
      if (navigator.sendBeacon(endpoint, blob)) {
        return;
      }
    }
    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: body,
      keepalive: true,
      credentials: 'omit',
    }).catch(function () {});
  }

  function onDomReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onDomReady(function () {
    send('pageview', '');
  });

  document.addEventListener(
    'click',
    function (ev) {
      var t = ev.target;
      if (!t || !t.closest) {
        return;
      }
      var a = t.closest('a[data-track-click]');
      if (!a) {
        return;
      }
      var name = (a.getAttribute('data-track-click') || '').trim();
      if (!name) {
        return;
      }
      send('link_click', name);
    },
    true
  );
})();
