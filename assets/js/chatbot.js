/**
 * Ethioware chat widget. Single deferred script tag per page; injects its
 * own CSS + DOM. No framework, no build step — matches the rest of the site.
 * See CHATBOT_SPEC.md §6.
 */
(function () {
  'use strict';

  var API_URL = '/chatbot/api/chat.php';
  var GREETING_DELAY_MS = 10000;
  var GREETING_AUTOHIDE_MS = 15000;
  var SESSION_KEY = 'cb_session';
  var GREETED_KEY = 'cb_greeted';

  var CHIPS = [
    { label: 'Explore programs', intent: 'enrollment', reply: "Great — we run a few different programs. Tell me a bit about your grade level or what you're interested in, and I'll help point you to the right one." },
    { label: 'How do I apply?', intent: 'enrollment', reply: 'Applying takes about 3–5 minutes at /apply. What would you like to know before you start?' },
    { label: 'Partner with us', intent: 'partnership', reply: "Happy to help! What would you like to know about partnering, sponsoring, or pricing?" },
    { label: 'Something else', intent: 'general', reply: "Sure — what's on your mind?" },
  ];

  function uuid4() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0, v = c === 'x' ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  function ga(name, params) {
    if (typeof window.gtag === 'function') {
      window.gtag('event', name, params || {});
    }
  }

  // cb_session is only written to sessionStorage once a chat actually
  // starts (first message/chip), not on every page load — that's what lets
  // "a chat exists" (§6.2) distinguish a fresh visit from a returning one
  // and choose greeting vs. unread-dot.
  function getOrCreateSessionId() {
    var id = sessionStorage.getItem(SESSION_KEY);
    if (!id) id = uuid4();
    return id;
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    attrs = attrs || {};
    for (var k in attrs) {
      if (k === 'class') node.className = attrs[k];
      else if (k === 'html') node.innerHTML = attrs[k];
      else node.setAttribute(k, attrs[k]);
    }
    (children || []).forEach(function (c) { if (c) node.appendChild(c); });
    return node;
  }

  function injectStyles() {
    if (document.getElementById('cb-styles')) return;
    var link = document.createElement('link');
    link.id = 'cb-styles';
    link.rel = 'stylesheet';
    link.href = '/assets/css/chatbot.css';
    document.head.appendChild(link);
  }

  function Widget() {
    this.hasExistingChat = !!sessionStorage.getItem(SESSION_KEY);
    this.sessionId = getOrCreateSessionId();
    this.busy = false;
    this.capped = false;
    this.build();
    this.applyTheme();
    this.watchTheme();
    this.scheduleGreeting();
  }

  // Marks the chat as started: persists the session id (so later page loads
  // this visit recognize "a chat exists") and clears the unread dot.
  Widget.prototype.commitSession = function () {
    sessionStorage.setItem(SESSION_KEY, this.sessionId);
    this.launcher.classList.remove('cb-has-unread');
  };

  Widget.prototype.build = function () {
    var self = this;
    this.root = el('div', { id: 'eth-chatbot-root' });

    this.launcherDot = el('span', { class: 'cb-dot' });
    this.launcherFallback = el('span', { class: 'cb-icon-fallback', html: '💬' });
    this.launcherImg = el('img', { src: '/assets/img/logo.png', alt: 'Chat with Ethioware', onerror: 'this.remove()' });
    this.launcher = el('button', { class: 'cb-launcher', type: 'button', 'aria-label': 'Open chat' }, [this.launcherImg, this.launcherDot]);
    this.launcherImg.addEventListener('error', function () {
      self.launcher.insertBefore(self.launcherFallback, self.launcherDot);
    });
    this.launcher.addEventListener('click', function () { self.togglePanel(); });

    this.greetingText = el('p', {}, [document.createTextNode('👋 Hi! Have questions about our programs? I can help.')]);
    this.greetingChips = el('div', { class: 'cb-chips' });
    this.greetingClose = el('button', { class: 'cb-close', type: 'button', 'aria-label': 'Dismiss' }, [document.createTextNode('×')]);
    this.greeting = el('div', { class: 'cb-greeting' }, [this.greetingClose, this.greetingText, this.greetingChips]);
    this.greetingClose.addEventListener('click', function () { self.hideGreeting(); });
    CHIPS.forEach(function (chip) {
      var btn = el('button', { class: 'cb-chip', type: 'button' }, [document.createTextNode(chip.label)]);
      btn.addEventListener('click', function () { self.hideGreeting(); self.openPanel(); self.handleChip(chip); });
      self.greetingChips.appendChild(btn);
    });

    this.thread = el('div', { class: 'cb-thread' });
    this.chipRow = el('div', { class: 'cb-chip-row' });
    this.formArea = el('div', {});
    this.input = el('input', { type: 'text', placeholder: 'Type a message…', 'aria-label': 'Message' });
    this.sendBtn = el('button', { type: 'button', 'aria-label': 'Send' }, [document.createTextNode('➤')]);
    this.composer = el('div', { class: 'cb-composer' }, [this.input, this.sendBtn]);

    this.input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); self.send(); }
    });
    this.sendBtn.addEventListener('click', function () { self.send(); });

    var closeBtn = el('button', { type: 'button', 'aria-label': 'Close chat' }, [document.createTextNode('×')]);
    closeBtn.addEventListener('click', function () { self.closePanel(); });
    var header = el('div', { class: 'cb-header' }, [
      el('img', { src: '/assets/img/logo.png', alt: '', onerror: 'this.remove()' }),
      el('span', { class: 'cb-title' }, [document.createTextNode('Ethioware Assistant')]),
      closeBtn,
    ]);

    this.panel = el('div', { class: 'cb-panel' }, [header, this.thread, this.chipRow, this.formArea, this.composer]);

    this.root.appendChild(this.greeting);
    this.root.appendChild(this.panel);
    this.root.appendChild(this.launcher);
    document.body.appendChild(this.root);

    if (this.hasExistingChat) {
      this.launcher.classList.add('cb-has-unread');
    }
  };

  // ---------------- Theme ----------------
  Widget.prototype.computeTheme = function () {
    if (document.body.classList.contains('dark-theme')) return 'dark';
    var stored = null;
    try { stored = localStorage.getItem('selected-theme'); } catch (e) {}
    if (stored === 'dark' || stored === 'light') return stored;
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark';
    return 'light';
  };
  Widget.prototype.applyTheme = function () {
    this.root.setAttribute('data-cb-theme', this.computeTheme());
  };
  Widget.prototype.watchTheme = function () {
    var self = this;
    try {
      new MutationObserver(function () { self.applyTheme(); })
        .observe(document.body, { attributes: true, attributeFilter: ['class'] });
    } catch (e) {}
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () { self.applyTheme(); });
    }
  };

  // ---------------- Greeting ----------------
  Widget.prototype.scheduleGreeting = function () {
    var self = this;
    if (sessionStorage.getItem(GREETED_KEY) || this.hasExistingChat) return;
    if (window.innerWidth < 380) return;
    this._greetTimer = window.setTimeout(function () { self.showGreeting(); }, GREETING_DELAY_MS);
  };
  Widget.prototype.showGreeting = function () {
    if (this.panelOpen) return;
    sessionStorage.setItem(GREETED_KEY, '1');
    this.greeting.classList.add('cb-visible');
    ga('chatbot_open', { method: 'greeting' });
    var self = this;
    this._greetHideTimer = window.setTimeout(function () { self.hideGreeting(); }, GREETING_AUTOHIDE_MS);
  };
  Widget.prototype.hideGreeting = function () {
    this.greeting.classList.remove('cb-visible');
    if (this._greetHideTimer) window.clearTimeout(this._greetHideTimer);
  };

  // ---------------- Panel ----------------
  Widget.prototype.togglePanel = function () {
    if (this.panelOpen) this.closePanel(); else this.openPanel();
  };
  Widget.prototype.openPanel = function () {
    this.hideGreeting();
    if (this._greetTimer) window.clearTimeout(this._greetTimer);
    this.panel.classList.add('cb-open');
    this.panelOpen = true;
    this.launcher.classList.remove('cb-has-unread');
    this.input.focus();
    if (!this._openedOnce) {
      this._openedOnce = true;
      ga('chatbot_open', { method: 'launcher' });
      if (!this.thread.children.length) {
        this.appendMessage('model', "👋 Hi! I'm the Ethioware assistant. Ask me about our programs, applying, or how to get involved — or tap a suggestion below.");
        this.renderChipRow(CHIPS.map(function (c) { return c; }));
      }
    }
  };
  Widget.prototype.closePanel = function () {
    this.panel.classList.remove('cb-open');
    this.panelOpen = false;
  };

  // ---------------- Chips ----------------
  Widget.prototype.renderChipRow = function (chips, onPick) {
    var self = this;
    this.chipRow.innerHTML = '';
    chips.forEach(function (chip) {
      var label = typeof chip === 'string' ? chip : chip.label;
      var btn = el('button', { class: 'cb-chip', type: 'button' }, [document.createTextNode(label)]);
      btn.addEventListener('click', function () {
        self.chipRow.innerHTML = '';
        if (onPick) onPick(chip);
        else self.handleChip(chip);
      });
      self.chipRow.appendChild(btn);
    });
  };
  Widget.prototype.handleChip = function (chip) {
    // Chips route intent WITHOUT an LLM call for the first hop (CHATBOT_SPEC.md §6.3.2).
    this.commitSession();
    this.appendMessage('user', chip.label);
    this.appendMessage('model', chip.reply);
    this.pendingHintIntent = chip.intent;
    this.input.focus();
  };

  // ---------------- Messages ----------------
  Widget.prototype.appendMessage = function (role, text) {
    var msg = el('div', { class: 'cb-msg cb-' + role }, [document.createTextNode(text)]);
    this.thread.appendChild(msg);
    this.thread.scrollTop = this.thread.scrollHeight;
  };
  Widget.prototype.showTyping = function () {
    this.typingEl = el('div', { class: 'cb-typing' }, [el('span'), el('span'), el('span')]);
    this.thread.appendChild(this.typingEl);
    this.thread.scrollTop = this.thread.scrollHeight;
  };
  Widget.prototype.hideTyping = function () {
    if (this.typingEl && this.typingEl.parentNode) this.typingEl.parentNode.removeChild(this.typingEl);
    this.typingEl = null;
  };

  Widget.prototype.setBusy = function (busy) {
    this.busy = busy;
    this.sendBtn.disabled = busy || this.capped;
    this.input.disabled = this.capped;
  };

  // ---------------- Networking ----------------
  Widget.prototype.postJSON = function (payload) {
    return fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then(function (res) { return res.json(); });
  };

  Widget.prototype.send = function () {
    var text = this.input.value.trim();
    if (!text || this.busy || this.capped) return;
    this.commitSession();
    this.input.value = '';
    this.chipRow.innerHTML = '';
    this.formArea.innerHTML = '';
    this.appendMessage('user', text);
    this.setBusy(true);
    this.showTyping();

    var payload = { session_id: this.sessionId, message: text, page: location.pathname };
    if (this.pendingHintIntent) {
      payload.hint_intent = this.pendingHintIntent;
      this.pendingHintIntent = null;
    }

    var self = this;
    this.postJSON(payload)
      .then(function (data) { self.hideTyping(); self.setBusy(false); self.handleResponse(data); })
      .catch(function () {
        self.hideTyping();
        self.setBusy(false);
        self.appendMessage('model', "Sorry, something went wrong. Please try again or email info@ethioware.org.");
      });
  };

  Widget.prototype.handleResponse = function (data) {
    if (!data || data.success === false) {
      this.appendMessage('model', (data && data.message) || "Sorry, something went wrong.");
      return;
    }
    if (data.reply) this.appendMessage('model', data.reply);

    if (data.action === 'refer_apply' && data.referral_url) {
      this.renderReferral(data.referral_url);
      ga('chatbot_referred');
    } else if (data.action === 'request_gate') {
      this.renderGateForm();
      ga('chatbot_gate_shown');
    } else if (data.action === 'quota_fallback') {
      this.renderContactForm();
    } else if (data.action === 'capped') {
      this.capped = true;
      this.setBusy(false);
    } else if (data.chips && data.chips.length) {
      this.renderChipRow(data.chips, this.handleProgramChip.bind(this));
    }

    if (data.gate && data.gate.passed && !this._gaFiredGatePassed) {
      this._gaFiredGatePassed = true;
      ga('chatbot_gate_passed');
    }
  };

  Widget.prototype.handleProgramChip = function (label) {
    this.input.value = "I'm interested in " + label + ".";
    this.send();
  };

  Widget.prototype.renderReferral = function (url) {
    var link = el('a', { class: 'cb-referral', href: url }, [document.createTextNode('Continue to application →')]);
    var wrap = el('div', { class: 'cb-msg cb-model' }, [link]);
    this.thread.appendChild(wrap);
    this.thread.scrollTop = this.thread.scrollHeight;
  };

  // ---------------- Gate form (partnership/pricing/donation/investment) ----------------
  Widget.prototype.renderGateForm = function () {
    var self = this;
    this.formArea.innerHTML = '';
    var errorEl = el('p', { class: 'cb-form-error', style: 'display:none;' });
    var name = el('input', { type: 'text', placeholder: 'Your name *', required: 'required' });
    var email = el('input', { type: 'email', placeholder: 'Email *', required: 'required' });
    var org = el('input', { type: 'text', placeholder: 'Organization (optional)' });
    var phone = el('input', { type: 'tel', placeholder: 'Phone (optional)' });
    var submit = el('button', { type: 'submit' }, [document.createTextNode('Send')]);
    var form = el('form', { class: 'cb-form' }, [
      el('p', { class: 'cb-form-note' }, [document.createTextNode('Share your details and our partnerships team will follow up.')]),
      errorEl, name, email, org, phone, submit,
    ]);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      errorEl.style.display = 'none';
      self.postJSON({
        session_id: self.sessionId, type: 'gate_submit',
        name: name.value.trim(), email: email.value.trim(),
        organization: org.value.trim(), phone: phone.value.trim(),
      }).then(function (data) {
        if (data && data.success) {
          self.formArea.innerHTML = '';
          ga('chatbot_lead', { kind: 'gate' });
          self.handleResponse(data);
        } else {
          errorEl.textContent = (data && data.message) || 'Please check your details and try again.';
          errorEl.style.display = 'block';
        }
      });
    });
    this.formArea.appendChild(form);
  };

  // ---------------- Quota-fallback contact mini-form ----------------
  Widget.prototype.renderContactForm = function () {
    var self = this;
    this.formArea.innerHTML = '';
    var errorEl = el('p', { class: 'cb-form-error', style: 'display:none;' });
    var name = el('input', { type: 'text', placeholder: 'Your name *', required: 'required' });
    var email = el('input', { type: 'email', placeholder: 'Email *', required: 'required' });
    var submit = el('button', { type: 'submit' }, [document.createTextNode('Leave my details')]);
    var form = el('form', { class: 'cb-form' }, [
      el('p', { class: 'cb-form-note' }, [document.createTextNode("We'll follow up by email as soon as we're back.")]),
      errorEl, name, email, submit,
    ]);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      errorEl.style.display = 'none';
      self.postJSON({
        session_id: self.sessionId, type: 'contact_submit',
        name: name.value.trim(), email: email.value.trim(),
      }).then(function (data) {
        if (data && data.success) {
          self.formArea.innerHTML = '';
          self.appendMessage('model', data.reply);
          ga('chatbot_lead', { kind: 'fallback' });
        } else {
          errorEl.textContent = (data && data.message) || 'Please check your details and try again.';
          errorEl.style.display = 'block';
        }
      });
    });
    this.formArea.appendChild(form);
  };

  function init() {
    injectStyles();
    if (document.body) {
      new Widget();
    } else {
      document.addEventListener('DOMContentLoaded', function () { new Widget(); });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
