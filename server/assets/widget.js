/*!
 * Widget de paiement — affiche la page de paiement dans votre site (sans redirection).
 * Toujours à jour : nouveaux moyens de paiement, frais Wave, consignes… sans rien changer chez vous.
 *
 * Utilisation :
 *   <div id="paiement"></div>
 *   <script src="https://VOTRE-PLATEFORME/pay/assets/widget.js"></script>
 *   <script>
 *     FlashPay.mount('#paiement', {
 *       payment: 'PAY-XXXXXXXXXX',          // id renvoyé par POST /api/v1/payments
 *       onPaid: function (p) { location.href = '/merci?commande=' + p.payment; },
 *       onStatus: function (p) { console.log(p.status); }   // expired, cancelled…
 *     });
 *   </script>
 */
(function (w, d) {
  'use strict';
  var script = d.currentScript || (function () { var s = d.getElementsByTagName('script'); return s[s.length - 1]; })();
  // Base de la plateforme déduite de l'adresse du script : https://…/pay/assets/widget.js -> https://…/pay
  var BASE = script && script.src ? script.src.replace(/\/assets\/widget\.js.*$/, '') : '';

  function mount(target, opts) {
    opts = opts || {};
    var el = typeof target === 'string' ? d.querySelector(target) : target;
    if (!el) throw new Error('FlashPay : élément introuvable (' + target + ')');
    var ref = opts.payment || (opts.checkoutUrl || '').split('/pay/').pop().split('?')[0];
    if (!ref) throw new Error('FlashPay : indiquez { payment: "PAY-…" } ou { checkoutUrl }');
    var base = (opts.base || BASE).replace(/\/$/, '');

    var frame = d.createElement('iframe');
    frame.src = base + '/pay/' + encodeURIComponent(ref) + '?embed=1';
    frame.title = 'Paiement';
    frame.setAttribute('allow', 'clipboard-write');
    frame.style.cssText = 'width:100%;border:0;display:block;min-height:420px;height:560px;background:transparent;color-scheme:normal';
    el.innerHTML = '';
    el.appendChild(frame);

    var origin;
    try { origin = new URL(base).origin; } catch (e) { origin = '*'; }
    function onMessage(e) {
      if (origin !== '*' && e.origin !== origin) return;
      var m = e.data;
      if (!m || m.source !== 'flashpay' || m.payment !== ref) return;
      if (m.type === 'resize' && m.height) frame.style.height = Math.max(300, m.height + 4) + 'px';
      if (m.type === 'operator' && opts.onOperator) opts.onOperator({ payment: ref, operator: m.operator });
      if (m.type === 'status') {
        if (opts.onStatus) opts.onStatus({ payment: ref, status: m.status });
        if (m.status === 'paid' && opts.onPaid) opts.onPaid({ payment: ref, status: 'paid' });
      }
    }
    w.addEventListener('message', onMessage);
    return {
      frame: frame,
      destroy: function () { w.removeEventListener('message', onMessage); el.innerHTML = ''; }
    };
  }

  w.FlashPay = { mount: mount, version: '1.0.0' };
})(window, document);
