/* Installation de l'application + abonnement aux notifications de paiement. */
(function () {
  var FP = window.FP || {};
  var $$ = function (s) { return Array.prototype.slice.call(document.querySelectorAll(s)); };
  var show = function (s, on) { $$(s).forEach(function (el) { el.hidden = !on; }); };
  var setText = function (s, t) { $$(s).forEach(function (el) { el.textContent = t; }); };
  var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  var swReg = null, deferred = null;

  // --- Installation de l'application ---
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); deferred = e; show('[data-pwa-install]', true);
  });
  window.addEventListener('appinstalled', function () { show('[data-pwa-install]', false); });
  $$('[data-pwa-install]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!deferred) return;
      deferred.prompt();
      deferred.userChoice.then(function () { deferred = null; show('[data-pwa-install]', false); });
    });
  });
  show('[data-ios-hint]', isIOS && !standalone);

  // --- Notifications ---
  function b64uToBytes(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '=';
    var raw = atob(s), out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }
  function bytesToB64u(buf) {
    var b = new Uint8Array(buf), s = '';
    for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function post(data) {
    var body = new URLSearchParams(data); body.append('_csrf', FP.csrf);
    return fetch(FP.base + '/' + (FP.area || 'admin') + '/push', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }
  var messages = {
    'unsupported': "Ce navigateur ne gère pas les notifications.",
    'ios-install': "Sur iPhone : installez d'abord l'application (bouton Partager → « Sur l'écran d'accueil »), puis ouvrez-la depuis son icône.",
    'denied': "Notifications bloquées : autorisez-les dans les réglages du navigateur pour ce site.",
    'off': "Recevez une alerte à chaque paiement reçu, même téléphone verrouillé.",
    'on': "✅ Activées sur cet appareil."
  };
  function render(state) {
    setText('[data-push-status]', messages[state]);
    show('[data-push-enable]', state === 'off');
    show('[data-push-on]', state === 'on');
    show('[data-push-prompt]', state === 'off' || state === 'ios-install');
  }
  function refresh() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window) || !swReg) {
      return render(isIOS && !standalone ? 'ios-install' : 'unsupported');
    }
    if (Notification.permission === 'denied') return render('denied');
    swReg.pushManager.getSubscription().then(function (s) { render(s ? 'on' : 'off'); });
  }
  function enable() {
    Notification.requestPermission().then(function (p) {
      if (p !== 'granted') return refresh();
      return swReg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(FP.vapid) })
        .then(function (s) {
          return post({ action: 'subscribe', endpoint: s.endpoint, p256dh: bytesToB64u(s.getKey('p256dh')), auth: bytesToB64u(s.getKey('auth')) });
        })
        .then(function () { return post({ action: 'test' }); })
        .then(refresh);
    }).catch(function (e) { alert('Activation impossible : ' + e.message); refresh(); });
  }
  function disable() {
    swReg.pushManager.getSubscription().then(function (s) {
      if (!s) return;
      return post({ action: 'unsubscribe', endpoint: s.endpoint }).then(function () { return s.unsubscribe(); });
    }).then(refresh);
  }
  $$('[data-push-enable]').forEach(function (b) { b.addEventListener('click', enable); });
  $$('[data-push-disable]').forEach(function (b) { b.addEventListener('click', disable); });
  $$('[data-push-test]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.disabled = true;
      post({ action: 'test' }).then(function (r) {
        b.textContent = r.ok ? 'Notification envoyée ✓' : "Échec de l'envoi";
      }).then(function () { b.disabled = false; });
    });
  });

  if ('serviceWorker' in navigator && FP.base !== undefined) {
    navigator.serviceWorker.register(FP.base + '/sw.js', { scope: FP.base + '/' })
      .then(function () { return navigator.serviceWorker.ready; })
      .then(function (r) { swReg = r; refresh(); })
      .catch(function () { render('unsupported'); });
  } else {
    render('unsupported');
  }
})();
