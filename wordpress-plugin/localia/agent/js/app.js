/* Localia desktop agent: polls your server for queued prompts, runs them on a local LLM, returns the results. */
(function () {
  'use strict';

  const VERSION = '1.1.0';
  const SETTINGS_KEY = 'localia.settings.v1';
  const HISTORY_KEY = 'localia.history.v1';
  const HISTORY_MAX = 40;
  const SERVER_TIMEOUT_MS = 30000;

  const { hmacHex, randomHex, safeEqual } = window.LocaliaCrypto;
  const Schedule = window.LocaliaSchedule;
  const LLM = window.LocaliaLLM;
  const Research = window.LocaliaResearch;

  const DEFAULTS = {
    serverProtocol: 'https',
    serverHost: '',
    serverPort: '',
    serverPath: '/localia/api.php',
    agentId: 'home-pc',
    secret: '',
    pairId: '',
    siteName: '',
    verifyServer: true,

    provider: 'ollama',
    llmUrl: 'http://localhost:11434',
    llmApiKey: '',
    model: '',
    requestTimeout: 600,
    temperature: 0.7,
    maxTokens: 2048,
    numCtx: '',
    keepAlive: '',
    allowServerModel: true,
    allowServerOptions: true,
    stripThinking: true,

    allowWebResearch: true,
    searchProvider: 'duckduckgo',
    searchUrl: '',
    searchKey: '',

    instructions: 'You are a helpful assistant working for a website. Follow the instructions in each request exactly and reply with only the requested content.',
    systemMode: 'append',

    pollInterval: 60,
    drainQueue: true,
    autoStart: false,
    wakeLock: false,

    scheduleMode: 'always',
    scheduleRules: [{ enabled: true, days: [0, 1, 2, 3, 4, 5, 6], start: '22:00', end: '07:00', from: '', to: '' }],
  };

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  // ------------------------------------------------------------------ storage

  function storageGet(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      return raw ? JSON.parse(raw) : fallback;
    } catch (e) {
      return fallback;
    }
  }

  function storageSet(key, value) {
    try {
      localStorage.setItem(key, JSON.stringify(value));
      return true;
    } catch (e) {
      log('Could not save to browser storage: ' + e.message, 'error');
      return false;
    }
  }

  const clone = (v) => JSON.parse(JSON.stringify(v));
  let settings = { ...clone(DEFAULTS), ...storageGet(SETTINGS_KEY, {}) };
  let draft = clone(settings);
  let history = storageGet(HISTORY_KEY, []);

  // ------------------------------------------------------------------ helpers

  function endpointUrl(s = settings) {
    const host = String(s.serverHost || '').trim();
    if (!host) return '';
    if (/^https?:\/\//i.test(host)) return host.replace(/\/+$/, ''); // full URL pasted into the host box
    let path = String(s.serverPath || '').trim();
    if (path && !path.startsWith('/')) path = '/' + path;
    const port = String(s.serverPort || '').trim();
    const defPort = s.serverProtocol === 'https' ? '443' : '80';
    return `${s.serverProtocol}://${host}${port && port !== defPort ? ':' + port : ''}${path}`;
  }

  function setupProblems(s = settings) {
    const p = [];
    if (!endpointUrl(s)) p.push('enter your server address');
    if (!s.agentId) p.push('set an agent ID');
    if (!s.secret || s.secret.length < 16) p.push('enter the shared secret (16+ characters)');
    if (!s.llmUrl) p.push('set the LLM base URL');
    if (!s.model) p.push('choose a model');
    return p;
  }

  const fmtDuration = (ms) => {
    if (ms == null) return '—';
    const s = ms / 1000;
    if (s < 60) return s.toFixed(1) + 's';
    const m = Math.floor(s / 60);
    return m < 60 ? `${m}m ${Math.round(s % 60)}s` : `${Math.floor(m / 60)}h ${m % 60}m`;
  };
  const fmtTime = (d) => new Date(d).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const fmtWhen = (d) => {
    const date = new Date(d);
    const sameDay = date.toDateString() === new Date().toDateString();
    return sameDay ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : date.toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  };
  const truncate = (s, n) => (s && s.length > n ? s.slice(0, n) + '…' : s || '');

  function toast(msg) {
    const el = document.createElement('div');
    el.className = 'toast';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 2600);
  }

  // ------------------------------------------------------------------ log

  const logEl = $('#log');
  function log(msg, level = 'info') {
    const line = document.createElement('div');
    line.className = 'l-' + level;
    const t = document.createElement('span');
    t.className = 't';
    t.textContent = fmtTime(Date.now()) + '  ';
    line.append(t, document.createTextNode(msg));
    const stick = logEl.scrollTop + logEl.clientHeight >= logEl.scrollHeight - 20;
    logEl.appendChild(line);
    while (logEl.childNodes.length > 400) logEl.removeChild(logEl.firstChild);
    if (stick) logEl.scrollTop = logEl.scrollHeight;
  }

  // ------------------------------------------------------------------ timers
  // Timers run in a Web Worker so background-tab throttling in the browser hurts less.

  const timerCallbacks = new Map();
  let timerSeq = 0;
  let timerWorker = null;
  try {
    const src = 'const t={};onmessage=e=>{const d=e.data;if(d.cancel){clearTimeout(t[d.id]);delete t[d.id];return;}t[d.id]=setTimeout(()=>{delete t[d.id];postMessage(d.id);},d.ms);};';
    timerWorker = new Worker(URL.createObjectURL(new Blob([src], { type: 'text/javascript' })));
    timerWorker.onerror = () => { timerWorker = null; };
    timerWorker.onmessage = (e) => {
      const cb = timerCallbacks.get(e.data);
      if (cb) cb();
    };
  } catch (e) {
    timerWorker = null;
  }

  let wakeSleep = null;
  function sleep(ms) {
    return new Promise((resolve) => {
      const id = ++timerSeq;
      const done = () => {
        if (!timerCallbacks.has(id)) return;
        timerCallbacks.delete(id);
        if (timerWorker) timerWorker.postMessage({ id, cancel: true });
        if (wakeSleep === done) wakeSleep = null;
        agent.nextAt = null;
        resolve();
      };
      timerCallbacks.set(id, done);
      wakeSleep = done;
      agent.nextAt = Date.now() + ms;
      if (timerWorker) timerWorker.postMessage({ id, ms });
      // Backup timer in case the worker is unavailable; done() only runs once.
      setTimeout(done, timerWorker ? ms + 1500 : ms);
    });
  }

  // ------------------------------------------------------------------ server protocol

  let clockOffset = 0; // seconds to add to local time so it matches the server

  async function serverCall(action, extra = {}, retried = false) {
    const s = settings;
    const url = endpointUrl(s);
    if (!url) throw new Error('No server address configured');
    const raw = JSON.stringify({ action, ...extra });
    const ts = String(Math.floor(Date.now() / 1000 + clockOffset));
    const nonce = randomHex(16);
    const signature = await hmacHex(s.secret, `v1\n${ts}\n${nonce}\n${raw}`);

    let res;
    try {
      res = await fetch(url, {
        method: 'POST',
        mode: 'cors',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/json',
          'X-Localia-Agent': s.agentId,
          'X-Localia-Timestamp': ts,
          'X-Localia-Nonce': nonce,
          'X-Localia-Signature': signature,
        },
        body: raw,
        signal: AbortSignal.timeout(SERVER_TIMEOUT_MS),
      });
    } catch (e) {
      throw new Error(`Could not reach the server (${e.name === 'TimeoutError' ? 'timed out' : 'network/CORS error'}) at ${url}`);
    }

    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      throw new Error(`Server returned HTTP ${res.status} with a non-JSON body: ${truncate(text.replace(/\s+/g, ' '), 160)}`);
    }

    if (!res.ok || !data.ok) {
      if (data.error === 'clock_skew' && data.server_time && !retried) {
        clockOffset = data.server_time - Date.now() / 1000;
        log(`This PC's clock is ${Math.round(clockOffset)}s off from the server — correcting automatically.`, 'warn');
        return serverCall(action, extra, true);
      }
      const err = new Error(`Server error ${res.status}: ${data.error || 'unknown'}${data.message && data.message !== data.error ? ' — ' + data.message : ''}`);
      err.code = data.error;
      throw err;
    }

    if (s.verifyServer) {
      const got = res.headers.get('X-Localia-Server-Signature') || '';
      const expected = await hmacHex(s.secret, `v1-resp\n${nonce}\n${text}`);
      if (!safeEqual(got.toLowerCase(), expected)) {
        throw new Error('The server response signature is missing or wrong. Ignoring it (check the secret, or that a proxy is not altering responses).');
      }
    }

    agent.lastContact = Date.now();
    if (typeof data.pending === 'number') agent.pending = data.pending;
    return data;
  }

  function agentInfo() {
    return {
      version: VERSION,
      provider: settings.provider,
      model: settings.model,
      // Models the runtime has, so the server can offer them in its model lists.
      models: agent.models,
      schedule: settings.scheduleMode,
      poll_interval: settings.pollInterval,
      // Whether this agent can take jobs that need web research (job.options.research).
      research: agent.helper && Research.isReady(settings),
      search_provider: settings.searchProvider,
      user_agent: navigator.userAgent.slice(0, 200),
    };
  }

  /** Refresh what agentInfo() reports: the runtime's models and whether the helper is running. */
  async function refreshCapabilities() {
    try {
      agent.models = (await LLM.listModels(settings)).slice(0, 100);
    } catch (e) {
      agent.models = settings.model ? [settings.model] : [];
    }
    agent.helper = !!(await Research.helperInfo(true));
    if (settings.allowWebResearch && !agent.helper) {
      log('Web research is unavailable: open this page with the start script so its helper runs (see Help).', 'warn');
    }
  }

  // ------------------------------------------------------------------ job execution

  function buildRequest(job) {
    const s = settings;
    let system = (s.instructions || '').trim();
    const jobSystem = (job.system || '').trim();
    if (jobSystem && s.systemMode === 'override') system = jobSystem;
    else if (jobSystem && s.systemMode === 'append') system = system ? system + '\n\n' + jobSystem : jobSystem;

    let messages;
    if (Array.isArray(job.messages) && job.messages.length) {
      messages = job.messages.filter((m) => m && typeof m.content === 'string');
      // A system message inside job.messages follows the same rule as job.system.
      const inner = messages.filter((m) => m.role === 'system').map((m) => m.content).join('\n\n');
      messages = messages.filter((m) => m.role !== 'system');
      if (inner && s.systemMode === 'override') system = inner;
      else if (inner && s.systemMode === 'append') system = system ? system + '\n\n' + inner : inner;
      if (job.prompt) messages.push({ role: 'user', content: job.prompt });
    } else {
      messages = [{ role: 'user', content: String(job.prompt || '') }];
    }
    if (system) messages.unshift({ role: 'system', content: system });

    const options = { temperature: s.temperature, max_tokens: s.maxTokens };
    if (s.numCtx) options.num_ctx = s.numCtx;
    const jo = job.options && typeof job.options === 'object' && !Array.isArray(job.options) ? job.options : {};
    if (s.allowServerOptions) {
      for (const k of ['temperature', 'max_tokens', 'top_p', 'seed', 'stop', 'num_ctx', 'format']) {
        if (jo[k] !== undefined && jo[k] !== null) options[k] = jo[k];
      }
    }

    const model = (s.allowServerModel && job.model) || s.model;
    return { messages, model, options };
  }

  let currentAbort = null;

  async function runJob(job) {
    const started = Date.now();
    agent.currentJob = { id: job.id, prompt: job.prompt || (job.messages || []).map((m) => `[${m.role}] ${m.content}`).join('\n'), started };
    setState('working');
    log(`Job ${job.id} received (attempt ${job.attempt || 1}). Sending to ${settings.provider === 'ollama' ? 'Ollama' : 'LLM'}…`);

    const controller = new AbortController();
    currentAbort = controller;
    const timeoutMs = Math.max(10, Number(settings.requestTimeout) || 600) * 1000;
    const timer = setTimeout(() => controller.abort(new DOMException('LLM request timed out', 'TimeoutError')), timeoutMs);

    let result;
    try {
      const req = buildRequest(job);
      const research = job.options && job.options.research && typeof job.options.research === 'object';
      if (research) {
        // Searches, page fetches and the answer, all on this PC; see js/research.js.
        const out = await Research.run(job, req, { settings, signal: controller.signal, log: (m, level) => log(`Job ${job.id}: ${m}`, level) });
        result = { status: 'done', response: out.text, model: out.model, usage: out.usage, sources: out.sources };
      } else {
        const out = await LLM.chat(settings, req, controller.signal);
        let text = out.text;
        if (settings.stripThinking) text = LLM.stripThinking(text);
        result = { status: 'done', response: text, model: out.model || req.model, usage: out.usage };
      }
    } catch (e) {
      const msg = controller.signal.aborted
        ? (controller.signal.reason && controller.signal.reason.message) || 'Aborted by agent'
        : e.message;
      result = { status: 'error', error: msg };
    } finally {
      clearTimeout(timer);
      currentAbort = null;
    }

    const duration = Date.now() - started;
    result.duration_ms = duration;
    if (result.status === 'done') log(`Job ${job.id} finished in ${fmtDuration(duration)} (${result.response.length} chars${result.sources ? `, ${result.sources.length} sources` : ''}). Sending result…`, 'ok');
    else log(`Job ${job.id} failed after ${fmtDuration(duration)}: ${result.error}`, 'error');

    // Deliver the result, retrying through brief network trouble.
    let reply = null;
    for (let attempt = 1; attempt <= 4; attempt++) {
      try {
        reply = await serverCall('result', { job_id: job.id, lease_token: job.lease_token, ...result });
        break;
      } catch (e) {
        if (e.code && /lease_mismatch|job_already|job_not_found/.test(e.code)) {
          log(`Server no longer wants job ${job.id}: ${e.code}`, 'warn');
          break;
        }
        log(`Could not deliver result (try ${attempt}/4): ${e.message}`, 'warn');
        if (attempt < 4) await new Promise((r) => setTimeout(r, attempt * 3000));
      }
    }

    if (reply && result.status === 'done') {
      agent.stats.done++;
      agent.stats.totalMs += duration;
    } else {
      agent.stats.failed++;
    }
    if (reply && reply.requeued) log(`Server will retry job ${job.id} later.`, 'warn');

    history.unshift({
      id: job.id,
      at: Date.now(),
      status: result.status,
      delivered: !!reply,
      duration,
      model: result.model || null,
      prompt: truncate(agent.currentJob.prompt, 4000),
      response: truncate(result.response || result.error || '', 8000),
    });
    history = history.slice(0, HISTORY_MAX);
    storageSet(HISTORY_KEY, history);
    agent.currentJob = null;
    renderHistory();
    return reply;
  }

  // ------------------------------------------------------------------ agent loop

  const agent = {
    running: false,
    gen: 0,
    state: 'stopped',
    stateDetail: '',
    currentJob: null,
    lastContact: null,
    pending: null,
    nextAt: null,
    forceOnce: false,
    models: [],
    helper: false,
    stats: { done: 0, failed: 0, totalMs: 0 },
  };

  function setState(state, detail = '') {
    agent.state = state;
    agent.stateDetail = detail;
    render();
  }

  async function loop(gen) {
    let errors = 0;
    let wasActive = null;
    while (agent.running && agent.gen === gen) {
      const s = settings;
      const active = agent.forceOnce || Schedule.isActive(s);
      if (!active) {
        if (wasActive !== false) log('Outside the schedule — waiting for the next window.', 'warn');
        wasActive = false;
        const next = Schedule.nextChange(s);
        setState('sleeping', next ? 'until ' + fmtWhen(next) : 'no upcoming window');
        await sleep(Math.max(1000, Math.min(60000, next ? next - Date.now() : 60000)));
        continue;
      }
      if (wasActive === false && !agent.forceOnce) log('Schedule window open — resuming.', 'ok');
      wasActive = true;
      agent.forceOnce = false;

      let again = false;
      try {
        setState('polling');
        const res = await serverCall('poll', { agent: agentInfo() });
        if (errors) log('Connection restored.', 'ok');
        errors = 0;
        if (res.job) {
          const reply = await runJob(res.job);
          const pending = reply ? reply.pending : res.pending;
          again = s.drainQueue && pending > 0;
          if (again) log(`${pending} more job(s) waiting — continuing right away.`);
        }
      } catch (e) {
        errors++;
        log(e.message, 'error');
      }

      if (!agent.running || agent.gen !== gen) break;
      if (again) {
        await sleep(300);
        continue;
      }
      const base = Math.max(5, Number(s.pollInterval) || 60);
      const delay = errors ? Math.min(Math.max(base, 15) * 2 ** Math.min(errors - 1, 4), 900) : base;
      setState(errors ? 'error' : 'idle', errors ? `retrying in ${fmtDuration(delay * 1000)}` : '');
      await sleep(delay * 1000);
    }
  }

  function start() {
    const problems = setupProblems();
    if (problems.length) {
      toast('Finish setup first: ' + problems[0]);
      showTab('settings');
      return;
    }
    if (agent.running) return;
    agent.running = true;
    agent.gen++;
    log(`Agent started. Checking ${endpointUrl()} every ${settings.pollInterval}s.`, 'ok');
    acquireWakeLock();
    const gen = agent.gen;
    refreshCapabilities().finally(() => loop(gen));
    render();
  }

  function stop() {
    if (!agent.running) return;
    agent.running = false;
    agent.gen++;
    if (currentAbort) currentAbort.abort(new DOMException('Agent stopped by user', 'AbortError'));
    if (wakeSleep) wakeSleep();
    releaseWakeLock();
    log('Agent stopped.', 'warn');
    setState('stopped');
  }

  function pollNow() {
    if (!agent.running) {
      start();
      return;
    }
    if (agent.state === 'working' || agent.state === 'polling') return;
    agent.forceOnce = true;
    if (wakeSleep) wakeSleep();
  }

  // ------------------------------------------------------------------ wake lock

  let wakeLock = null;
  async function acquireWakeLock() {
    if (!settings.wakeLock || !agent.running || !('wakeLock' in navigator) || wakeLock) return;
    try {
      wakeLock = await navigator.wakeLock.request('screen');
      wakeLock.addEventListener('release', () => { wakeLock = null; });
    } catch (e) {
      log('Could not keep the screen awake: ' + e.message, 'warn');
    }
  }
  function releaseWakeLock() {
    if (wakeLock) wakeLock.release().catch(() => {});
    wakeLock = null;
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') acquireWakeLock();
  });

  // ------------------------------------------------------------------ pairing
  // Connection details come from the WordPress plugin: js/config.js inside the downloaded
  // agent (window.LOCALIA_PRESET), or a "LOCALIA1:..." pairing code pasted in Settings.

  function applyPairing(target, p) {
    target.serverHost = p.serverUrl;
    target.serverProtocol = /^http:/i.test(p.serverUrl) ? 'http' : 'https';
    target.serverPort = '';
    target.serverPath = '';
    target.agentId = p.agentId;
    target.secret = p.secret;
    target.pairId = p.id || '';
    target.siteName = p.site || '';
  }

  function decodePairingCode(code) {
    const m = /^LOCALIA1:([A-Za-z0-9+/=\s]+)$/.exec(String(code || '').trim());
    if (!m) throw new Error('That is not a Localia pairing code.');
    const bytes = Uint8Array.from(atob(m[1].replace(/\s+/g, '')), (c) => c.charCodeAt(0));
    const p = JSON.parse(new TextDecoder().decode(bytes));
    if (!p.serverUrl || !p.agentId || !p.secret) throw new Error('The pairing code is incomplete.');
    return p;
  }

  // ------------------------------------------------------------------ rendering

  const STATE_LABEL = { stopped: 'Stopped', idle: 'Waiting', polling: 'Checking server', working: 'Running job', sleeping: 'Outside schedule', error: 'Connection problem' };

  function render() {
    $('#tagline').textContent = settings.siteName ? 'Working for ' + settings.siteName : 'Your home LLM, working for your server';
    const pill = $('#statusPill');
    pill.className = 'pill pill-' + agent.state;
    pill.textContent = STATE_LABEL[agent.state] + (agent.stateDetail ? ' · ' + agent.stateDetail : '');

    const btn = $('#btnStartStop');
    btn.textContent = agent.running ? 'Stop agent' : 'Start agent';
    btn.classList.toggle('running', agent.running);
    btn.classList.toggle('btn-primary', !agent.running);

    const problems = setupProblems();
    $('#setupNotice').hidden = !problems.length;
    $('#setupNoticeText').textContent = problems.length ? problems.join(', ') + '.' : '';

    $('#cardEndpoint').textContent = endpointUrl() || 'not set';
    $('#cardLastContact').textContent = agent.lastContact ? fmtWhen(agent.lastContact) : 'never';
    $('#cardPending').textContent = agent.pending == null ? '—' : String(agent.pending);
    $('#cardModel').textContent = settings.model || 'no model set';
    $('#cardLlmUrl').textContent = (settings.provider === 'ollama' ? 'Ollama · ' : 'OpenAI-compatible · ') + (settings.llmUrl || '');

    if (settings.scheduleMode !== 'schedule') {
      $('#cardSchedule').textContent = 'Any time';
      $('#cardScheduleNext').textContent = '';
    } else {
      const active = Schedule.isActive(settings);
      const next = Schedule.nextChange(settings);
      $('#cardSchedule').textContent = active ? 'In a window now' : 'Outside windows';
      $('#cardScheduleNext').textContent = next ? (active ? 'Ends ' : 'Next window ') + fmtWhen(next) : 'No upcoming window';
    }

    $('#statDone').textContent = agent.stats.done;
    $('#statFailed').textContent = agent.stats.failed;
    $('#statAvg').textContent = agent.stats.done ? fmtDuration(agent.stats.totalMs / agent.stats.done) : '—';

    const cj = agent.currentJob;
    $('#currentJob').hidden = !cj;
    if (cj) {
      $('#curJobId').textContent = cj.id;
      $('#curJobPrompt').textContent = truncate(cj.prompt, 1500);
    }
    renderTicker();
  }

  function renderTicker() {
    const cj = agent.currentJob;
    if (cj) $('#curJobElapsed').textContent = fmtDuration(Date.now() - cj.started);
    const np = $('#nextPoll');
    if (agent.running && agent.nextAt && (agent.state === 'idle' || agent.state === 'error')) {
      np.textContent = `Next check in ${Math.max(0, Math.ceil((agent.nextAt - Date.now()) / 1000))}s`;
    } else np.textContent = '';
  }
  setInterval(renderTicker, 1000);
  setInterval(render, 30000); // refresh schedule card / relative times

  function renderHistory() {
    const box = $('#history');
    box.textContent = '';
    if (!history.length) {
      box.innerHTML = '<p class="muted small">No jobs yet.</p>';
      return;
    }
    for (const h of history) {
      const d = document.createElement('details');
      const sum = document.createElement('summary');
      const st = document.createElement('span');
      st.className = 's s-' + h.status;
      st.textContent = h.status === 'done' ? 'done' : 'error';
      const p = document.createElement('span');
      p.className = 'p';
      p.textContent = h.prompt.replace(/\s+/g, ' ');
      const meta = document.createElement('span');
      meta.className = 'muted small';
      meta.textContent = `${fmtWhen(h.at)} · ${fmtDuration(h.duration)}${h.delivered ? '' : ' · not delivered'}`;
      sum.append(st, p, meta);
      const body = document.createElement('div');
      body.className = 'body';
      const mk = (label, text) => {
        const l = document.createElement('div');
        l.className = 'muted small';
        l.textContent = label;
        const pre = document.createElement('pre');
        pre.textContent = text;
        body.append(l, pre);
      };
      mk(`Job ${h.id}${h.model ? ' · ' + h.model : ''} — prompt`, h.prompt);
      mk(h.status === 'done' ? 'Response' : 'Error', h.response);
      d.append(sum, body);
      box.appendChild(d);
    }
  }

  // ------------------------------------------------------------------ settings form

  const form = $('#settingsForm');

  function fillForm() {
    for (const el of $$('[data-setting]', form)) {
      const key = el.dataset.setting;
      const v = draft[key];
      if (el.type === 'checkbox') el.checked = !!v;
      else if (el.type === 'radio') el.checked = el.value === v;
      else el.value = v == null ? '' : v;
    }
    renderRules();
    updateFormDerived();
  }

  function readField(el) {
    if (el.type === 'checkbox') return el.checked;
    if (el.type === 'number') return el.value === '' ? '' : Number(el.value);
    return el.value;
  }

  function isDirty() {
    return JSON.stringify(draft) !== JSON.stringify(settings);
  }

  function updateFormDerived() {
    $('#endpointPreview').textContent = endpointUrl(draft) || '—';
    $('#httpWarning').hidden = !(draft.serverProtocol === 'http' || /^http:\/\//i.test(draft.serverHost || ''));
    $('#scheduleEditor').classList.toggle('disabled', draft.scheduleMode !== 'schedule');
    $('#dirtyDot').hidden = !isDirty();
  }

  form.addEventListener('input', (e) => {
    const el = e.target;
    if (el.dataset && el.dataset.setting) {
      if (el.type === 'radio' && !el.checked) return;
      draft[el.dataset.setting] = readField(el);
    }
    updateFormDerived();
  });

  $('#providerSelect').addEventListener('change', (e) => {
    const urls = { ollama: 'http://localhost:11434', openai: 'http://localhost:1234' };
    const other = e.target.value === 'ollama' ? urls.openai : urls.ollama;
    if (!draft.llmUrl || draft.llmUrl === other) {
      draft.llmUrl = urls[e.target.value];
      $('[data-setting="llmUrl"]').value = draft.llmUrl;
    }
  });

  function normalize(s) {
    const out = { ...s };
    out.serverHost = String(out.serverHost || '').trim();
    out.serverPath = String(out.serverPath || '').trim();
    out.agentId = String(out.agentId || '').trim();
    out.secret = String(out.secret || '').trim();
    out.llmUrl = String(out.llmUrl || '').trim().replace(/\/+$/, '');
    out.model = String(out.model || '').trim();
    out.searchUrl = String(out.searchUrl || '').trim().replace(/\/+$/, '');
    out.searchKey = String(out.searchKey || '').trim();
    out.pollInterval = Math.max(5, Number(out.pollInterval) || DEFAULTS.pollInterval);
    out.requestTimeout = Math.max(10, Number(out.requestTimeout) || DEFAULTS.requestTimeout);
    out.temperature = out.temperature === '' ? DEFAULTS.temperature : Number(out.temperature);
    out.maxTokens = Math.max(1, Number(out.maxTokens) || DEFAULTS.maxTokens);
    return out;
  }

  function save() {
    const next = normalize(draft);
    const warnings = [];
    if (next.secret && next.secret.length < 16) warnings.push('The secret should be at least 16 characters.');
    if (next.scheduleMode === 'schedule' && !next.scheduleRules.some((r) => r.enabled !== false && r.days.length)) {
      warnings.push('Schedule mode is on but no window has any days selected — the agent will never run.');
    }
    const restart = agent.running && (endpointUrl(next) !== endpointUrl(settings) || next.pollInterval !== settings.pollInterval);
    settings = next;
    draft = clone(next);
    if (!storageSet(SETTINGS_KEY, settings)) return;
    fillForm();
    render();
    toast('Settings saved');
    for (const w of warnings) log(w, 'warn');
    if (restart && wakeSleep) wakeSleep();
    if (agent.running) refreshCapabilities();
    if (agent.running) settings.wakeLock ? acquireWakeLock() : releaseWakeLock();
  }

  $('#btnSave').addEventListener('click', save);
  $('#btnRevert').addEventListener('click', () => {
    draft = clone(settings);
    fillForm();
  });
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's' && $('#tab-settings').classList.contains('active')) {
      e.preventDefault();
      save();
    }
  });
  window.addEventListener('beforeunload', (e) => {
    if (isDirty() || agent.running) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  $('#btnPairingCode').addEventListener('click', () => {
    const code = prompt('Paste the pairing code from your WordPress site (Localia → Settings):');
    if (!code) return;
    try {
      applyPairing(draft, decodePairingCode(code));
      fillForm();
      toast('Connection details filled in — press Save settings');
    } catch (err) {
      toast(err.message);
    }
  });

  $('#btnShowSecret').addEventListener('click', (e) => {
    const input = $('[data-setting="secret"]');
    input.type = input.type === 'password' ? 'text' : 'password';
    e.target.textContent = input.type === 'password' ? 'Show' : 'Hide';
  });
  $('#btnGenSecret').addEventListener('click', () => {
    if (draft.secret && !confirm('Replace the current secret? You must also update it in the server config.')) return;
    draft.secret = randomHex(32);
    const input = $('[data-setting="secret"]');
    input.value = draft.secret;
    input.type = 'text';
    $('#btnShowSecret').textContent = 'Hide';
    updateFormDerived();
    toast('New secret generated — copy it into your server config, then Save');
  });

  // ---- schedule rules editor

  function renderRules() {
    const box = $('#scheduleRules');
    box.textContent = '';
    const tpl = $('#ruleTemplate');
    draft.scheduleRules.forEach((rule, idx) => {
      const node = tpl.content.firstElementChild.cloneNode(true);
      $('[data-r="enabled"]', node).checked = rule.enabled !== false;
      $('[data-r="start"]', node).value = rule.start || '00:00';
      $('[data-r="end"]', node).value = rule.end || '00:00';
      $('[data-r="from"]', node).value = rule.from || '';
      $('[data-r="to"]', node).value = rule.to || '';
      const days = $('.days', node);
      // Monday-first display order
      [1, 2, 3, 4, 5, 6, 0].forEach((d) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'day' + (rule.days.includes(d) ? ' on' : '');
        b.textContent = Schedule.DAY_NAMES[d].slice(0, 2);
        b.title = Schedule.DAY_NAMES[d];
        b.addEventListener('click', () => {
          rule.days = rule.days.includes(d) ? rule.days.filter((x) => x !== d) : [...rule.days, d].sort();
          b.classList.toggle('on');
          updateFormDerived();
        });
        days.appendChild(b);
      });
      for (const key of ['enabled', 'start', 'end', 'from', 'to']) {
        const el = $(`[data-r="${key}"]`, node);
        el.addEventListener('input', (e) => {
          e.stopPropagation();
          rule[key] = el.type === 'checkbox' ? el.checked : el.value;
          updateFormDerived();
        });
      }
      $('[data-r="remove"]', node).addEventListener('click', () => {
        draft.scheduleRules.splice(idx, 1);
        renderRules();
        updateFormDerived();
      });
      box.appendChild(node);
    });
    if (!draft.scheduleRules.length) box.innerHTML = '<p class="muted small">No windows yet.</p>';
  }

  $('#btnAddRule').addEventListener('click', () => {
    draft.scheduleRules.push({ enabled: true, days: [1, 2, 3, 4, 5], start: '09:00', end: '17:00', from: '', to: '' });
    renderRules();
    updateFormDerived();
  });

  // ---- tests (use the draft so you can test before saving)

  const testOut = $('#testOutput');
  function showTest(text) {
    testOut.hidden = false;
    testOut.textContent = text;
  }

  async function withDraft(fn) {
    const saved = settings;
    settings = normalize(draft);
    try {
      return await fn();
    } finally {
      settings = saved;
    }
  }

  $('#btnTestServer').addEventListener('click', async (e) => {
    e.target.disabled = true;
    showTest('Contacting ' + (endpointUrl(normalize(draft)) || '(no address)') + ' …');
    try {
      const res = await withDraft(() => serverCall('ping', { agent: agentInfo() }));
      showTest(`✔ Connected and authenticated.\nJobs waiting: ${res.pending}\nServer time: ${new Date(res.server_time * 1000).toLocaleString()}${draft.verifyServer ? '\nServer signature verified.' : ''}`);
      render();
    } catch (err) {
      showTest('✘ ' + err.message);
    } finally {
      e.target.disabled = false;
    }
  });

  $('#btnTestLlm').addEventListener('click', async (e) => {
    e.target.disabled = true;
    const s = normalize(draft);
    if (!s.model) {
      showTest('✘ Choose a model first (try the Load button).');
      e.target.disabled = false;
      return;
    }
    showTest(`Asking ${s.model} for a test reply (first load can take a while)…`);
    const started = Date.now();
    try {
      const req = await withDraft(() => buildRequest({ prompt: 'Reply with one short sentence confirming you are ready to work.' }));
      const out = await LLM.chat(s, req, AbortSignal.timeout(s.requestTimeout * 1000));
      const text = s.stripThinking ? LLM.stripThinking(out.text) : out.text;
      showTest(`✔ ${out.model} answered in ${fmtDuration(Date.now() - started)}:\n\n${text}`);
    } catch (err) {
      showTest('✘ ' + err.message);
    } finally {
      e.target.disabled = false;
    }
  });

  $('#btnTestSearch').addEventListener('click', async (e) => {
    e.target.disabled = true;
    const s = normalize(draft);
    const p = Research.PROVIDERS[s.searchProvider];
    showTest(`Searching with ${p ? p.label : s.searchProvider}…`);
    try {
      if (!(await Research.helperInfo(true))) throw new Error('The helper is not running: open this page with the start script (see Help).');
      if (!p || !p.ready(s)) throw new Error('Choose a search provider and fill in its URL or key first.');
      const hits = await p.search(s, 'Super Mario Bros. 3 NES release date', 5, AbortSignal.timeout(30000));
      showTest(hits.length ? `✔ ${hits.length} results:\n\n${hits.map((h) => `${h.title}\n  ${h.url}`).join('\n')}` : '✘ The search worked but returned no results.');
    } catch (err) {
      showTest('✘ ' + err.message);
    } finally {
      e.target.disabled = false;
    }
  });

  $('#btnLoadModels').addEventListener('click', async (e) => {
    e.target.disabled = true;
    try {
      const models = await LLM.listModels(normalize(draft));
      const dl = $('#modelList');
      dl.textContent = '';
      for (const m of models) {
        const o = document.createElement('option');
        o.value = m;
        dl.appendChild(o);
      }
      if (!draft.model && models[0]) {
        draft.model = models[0];
        $('[data-setting="model"]').value = models[0];
        updateFormDerived();
      }
      showTest(models.length ? `Models available:\n${models.join('\n')}` : 'The runtime reported no models. Pull/download one first.');
    } catch (err) {
      showTest('✘ ' + err.message);
    } finally {
      e.target.disabled = false;
    }
  });

  // ---- import / export / reset

  $('#btnExport').addEventListener('click', () => {
    const blob = new Blob([JSON.stringify({ localia: VERSION, settings }, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'localia-settings.json';
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  });

  $('#importFile').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    try {
      const data = JSON.parse(await file.text());
      const imported = data.settings || data;
      draft = { ...clone(DEFAULTS), ...imported };
      fillForm();
      toast('Imported — review and press Save');
    } catch (err) {
      toast('Could not import: ' + err.message);
    }
  });

  $('#btnReset').addEventListener('click', () => {
    if (!confirm('Reset all settings to defaults? (Not saved until you press Save.)')) return;
    draft = clone(DEFAULTS);
    fillForm();
  });

  // ------------------------------------------------------------------ tabs & controls

  function showTab(name) {
    for (const t of $$('.tab')) t.classList.toggle('active', t.dataset.tab === name);
    for (const p of $$('.panel')) p.classList.toggle('active', p.id === 'tab-' + name);
    try { sessionStorage.setItem('localia.tab', name); } catch (e) { /* ignore */ }
  }
  for (const t of $$('.tab')) t.addEventListener('click', () => showTab(t.dataset.tab));
  for (const b of $$('[data-goto]')) b.addEventListener('click', () => showTab(b.dataset.goto));

  $('#btnStartStop').addEventListener('click', () => (agent.running ? stop() : start()));
  $('#btnPollNow').addEventListener('click', pollNow);
  $('#btnClearLog').addEventListener('click', () => { logEl.textContent = ''; });
  $('#btnClearHistory').addEventListener('click', () => {
    history = [];
    storageSet(HISTORY_KEY, history);
    renderHistory();
  });

  // ------------------------------------------------------------------ boot

  const preset = window.LOCALIA_PRESET;
  let presetApplied = false;
  if (preset && preset.serverUrl && preset.secret && settings.pairId !== preset.id) {
    applyPairing(settings, preset);
    storageSet(SETTINGS_KEY, settings);
    draft = clone(settings);
    presetApplied = true;
  }

  fillForm();
  renderHistory();
  render();
  let initialTab = 'dashboard';
  try { initialTab = sessionStorage.getItem('localia.tab') || initialTab; } catch (e) { /* ignore */ }
  showTab(setupProblems().length && !storageGet(SETTINGS_KEY, null) ? 'settings' : initialTab);
  log(`Localia agent ${VERSION} loaded${location.protocol === 'file:' ? ' from a local file (if the LLM refuses the connection, use the start script — see Help)' : ''}.`);
  if (presetApplied) log(`Connection to ${settings.siteName || 'your site'} set up automatically. Choose your model in Settings, then press Start.`, 'ok');
  if (settings.autoStart && !setupProblems().length) start();

  // Exposed for debugging from the browser console.
  window.localiaAgent = { agent, start, stop, pollNow, get settings() { return settings; } };
})();
