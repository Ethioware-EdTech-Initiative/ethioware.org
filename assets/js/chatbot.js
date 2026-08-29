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
  // Server worst case is one 15s call plus a 10s fallback, so give it 30s
  // before deciding the request is never coming back.
  var REQUEST_TIMEOUT_MS = 30000;
  var MAX_MESSAGE_LEN = 2000; // matches the server-side cap in chat.php

  // Order matters: absolute URLs are matched before the bare-domain and
  // site-path forms so an "https://ethioware.org/apply" is captured whole.
  var LINK_RE = new RegExp(
    '(https?://[^\\s<>()]+[^\\s<>().,;:!?])' +          // 1: absolute URL
    '|([\\w.+-]+@[\\w-]+\\.[\\w.-]+[\\w])' +      // 2: email
    '|(\\bethioware\\.org/[A-Za-z0-9_-]+)' +            // 3: certificate short URL
    '|(/(?:apply|support|pay|privacy|research-scholars)\\b)', // 4: on-site path
    'g'
  );

  // The prompt tells the model to write plain text, but a stray markdown link
  // or **bold** still slips through occasionally. Flattening it here is
  // cheaper than a retry and stops raw syntax reaching the visitor.
  function tidy(text) {
    return String(text)
      .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)/g, '$1 ($2)')
      .replace(/\*\*([^*]+)\*\*/g, '$1')
      .replace(/(^|\s)\*([^*\n]+)\*(?=\s|$)/g, '$1$2')
      .replace(/^\s*[-*]\s+/gm, '\u2022 ')
      .trim();
  }

  // Builds a text+anchor fragment. Never uses innerHTML: link text and hrefs
  // both come from model output, so everything goes in as a text node or a
  // scheme we chose ourselves.
  function linkify(text) {
    var frag = document.createDocumentFragment();
    var last = 0;
    var match;
    LINK_RE.lastIndex = 0;
    while ((match = LINK_RE.exec(text)) !== null) {
      if (match.index > last) {
        frag.appendChild(document.createTextNode(text.slice(last, match.index)));
      }
      var a = document.createElement('a');
      if (match[1]) {
        a.href = match[1];
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
      } else if (match[2]) {
        a.href = 'mailto:' + match[2];
      } else if (match[3]) {
        a.href = 'https://' + match[3];
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
      } else {
        a.href = match[4];
      }
      a.appendChild(document.createTextNode(match[0]));
      frag.appendChild(a);
      last = match.index + match[0].length;
    }
    if (last < text.length) {
      frag.appendChild(document.createTextNode(text.slice(last)));
    }
    return frag;
  }

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
    this.launcher = el('button', {
      class: 'cb-launcher', type: 'button', 'aria-label': 'Open chat',
      'aria-expanded': 'false', 'aria-haspopup': 'dialog',
    }, [this.launcherImg, this.launcherDot]);
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

    // role=log + polite live region: replies arrive asynchronously, so a
    // screen-reader user needs them announced without stealing focus.
    this.thread = el('div', {
      class: 'cb-thread', role: 'log', 'aria-live': 'polite',
      'aria-relevant': 'additions', 'aria-label': 'Conversation',
    });
    this.chipRow = el('div', { class: 'cb-chip-row' });
    this.formArea = el('div', {});
    this.input = el('input', {
      type: 'text', placeholder: 'Type a message…', 'aria-label': 'Message',
      maxlength: String(MAX_MESSAGE_LEN), autocomplete: 'off',
    });
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

    this.panel = el('div', {
      class: 'cb-panel', role: 'dialog', 'aria-label': 'Ethioware Assistant',
    }, [header, this.thread, this.chipRow, this.formArea, this.composer]);

    // Escape closes the panel, the way every other dialog on the web does.
    this.root.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && self.panelOpen) {
        e.stopPropagation();
        self.closePanel();
      }
    });

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
    this.launcher.setAttribute('aria-expanded', 'true');
    this.launcher.setAttribute('aria-label', 'Close chat');
    this.launcher.classList.remove('cb-has-unread');
    // Focusing the input pops the on-screen keyboard, which on a phone hides
    // most of the conversation the moment it opens. Desktop only.
    if (!window.matchMedia || !window.matchMedia('(pointer: coarse)').matches) {
      this.input.focus();
    }
    if (!this._openedOnce) {
      this._openedOnce = true;
      ga('chatbot_open', { method: 'launcher' });
      if (!this.thread.children.length) {
        this.appendMessage('model', "👋 Hi! I'm the Ethioware assistant. Ask me about our programs, applying, or how to get involved — or tap a suggestion below.");
        this.renderChipRow(CHIPS);
      }
    }
  };
  Widget.prototype.closePanel = function () {
    this.panel.classList.remove('cb-open');
    this.panelOpen = false;
    this.launcher.setAttribute('aria-expanded', 'false');
    this.launcher.setAttribute('aria-label', 'Open chat');
    this.launcher.focus(); // don't strand focus inside a hidden panel
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
  // Only stick to the bottom if the reader is already there — yanking the
  // view down while someone scrolls back through the thread is worse than a
  // missed scroll.
  Widget.prototype.isNearBottom = function () {
    var t = this.thread;
    return (t.scrollHeight - t.scrollTop - t.clientHeight) < 60;
  };
  Widget.prototype.scrollToBottom = function (force) {
    if (force || this.isNearBottom()) this.thread.scrollTop = this.thread.scrollHeight;
  };

  Widget.prototype.appendMessage = function (role, text) {
    var stick = this.isNearBottom();
    var msg = el('div', { class: 'cb-msg cb-' + role });
    // The visitor's own words go in verbatim; model output is tidied of stray
    // markdown and has its links and addresses made clickable.
    msg.appendChild(role === 'user'
      ? document.createTextNode(text)
      : linkify(tidy(text)));
    this.thread.appendChild(msg);
    this.scrollToBottom(stick || role === 'user');
    return msg;
  };
  Widget.prototype.showTyping = function () {
    this.typingEl = el('div', { class: 'cb-typing', 'aria-label': 'Assistant is typing' },
      [el('span'), el('span'), el('span')]);
    this.thread.appendChild(this.typingEl);
    this.scrollToBottom(true);
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
  // Aborts rather than spinning forever if the server never answers. The
  // JSON body is still parsed on a 4xx — chat.php reports validation problems
  // that way and the widget shows them.
  Widget.prototype.postJSON = function (payload) {
    var controller = ('AbortController' in window) ? new AbortController() : null;
    var timer = null;
    if (controller) {
      timer = window.setTimeout(function () { controller.abort(); }, REQUEST_TIMEOUT_MS);
    }
    var clear = function () { if (timer) window.clearTimeout(timer); };

    return fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      signal: controller ? controller.signal : undefined,
    }).then(function (res) {
      clear();
      return res.json();
    }, function (err) {
      clear();
      throw err;
    });
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
        self.appendMessage('model', "Sorry, I couldn't reach the server. You can try again, or email info@ethioware.org.");
        // Offer the failed turn back rather than making them retype it.
        self.renderChipRow(['Try again'], function () {
          self.input.value = text;
          self.pendingHintIntent = payload.hint_intent || null;
          self.send();
        });
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

  // Appended straight to the thread rather than wrapped in a model bubble: a
  // solid call-to-action inside a bordered bubble reads as a box in a box, and
  // the bubble's link styling put an underline through the button.
  Widget.prototype.renderReferral = function (url) {
    var stick = this.isNearBottom();
    var link = el('a', { class: 'cb-referral', href: url },
      [document.createTextNode('Continue to application →')]);
    this.thread.appendChild(link);
    this.scrollToBottom(stick);
  };

  // Submitting a form must always end in one of: success, a visible error, or
  // a re-enabled button. Before this, a network failure left the form frozen
  // mid-submit with no feedback at all.
  Widget.prototype.submitForm = function (payload, opts) {
    var self = this;
    opts.submit.disabled = true;
    var originalLabel = opts.submit.textContent;
    opts.submit.textContent = 'Sending…';
    opts.error.style.display = 'none';

    var restore = function () {
      opts.submit.disabled = false;
      opts.submit.textContent = originalLabel;
    };
    var fail = function (message) {
      opts.error.textContent = message;
      opts.error.style.display = 'block';
      restore();
    };

    this.postJSON(payload).then(function (data) {
      if (data && data.success) {
        opts.onSuccess(data);
        return;
      }
      fail((data && data.message) || 'Please check your details and try again.');
    }).catch(function () {
      fail("Couldn't reach the server. Please try again in a moment.");
    });
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
      self.submitForm({
        session_id: self.sessionId, type: 'gate_submit',
        name: name.value.trim(), email: email.value.trim(),
        organization: org.value.trim(), phone: phone.value.trim(),
      }, {
        submit: submit,
        error: errorEl,
        onSuccess: function (data) {
          self.formArea.innerHTML = '';
          ga('chatbot_lead', { kind: 'gate' });
          self.handleResponse(data);
        },
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
      self.submitForm({
        session_id: self.sessionId, type: 'contact_submit',
        name: name.value.trim(), email: email.value.trim(),
      }, {
        submit: submit,
        error: errorEl,
        onSuccess: function (data) {
          self.formArea.innerHTML = '';
          self.appendMessage('model', data.reply);
          ga('chatbot_lead', { kind: 'fallback' });
        },
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
