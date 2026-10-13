/*
 * Adapters for local LLM runtimes.
 *  - "ollama": Ollama's native API (http://localhost:11434)
 *  - "openai": any OpenAI-compatible server: LM Studio (http://localhost:1234),
 *              llama.cpp server, vLLM, LocalAI, Jan, KoboldCpp, text-generation-webui ...
 */
(function (global) {
  'use strict';

  const trimSlash = (u) => String(u || '').trim().replace(/\/+$/, '');

  function openaiBase(url) {
    const u = trimSlash(url);
    return /\/v\d+$/.test(u) ? u : u + '/v1';
  }

  async function request(url, opts, what) {
    let res;
    try {
      res = await fetch(url, opts);
    } catch (e) {
      if (e.name === 'AbortError' || e.name === 'TimeoutError') throw e;
      throw new Error(`Could not reach ${what} at ${url}. Is it running, and does it allow this page's origin (CORS)? See the Help tab.`);
    }
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch (e) { /* not JSON */ }
    if (!res.ok) {
      const msg = data && (data.error && (data.error.message || data.error)) || text.slice(0, 300) || res.statusText;
      throw new Error(`${what} returned HTTP ${res.status}: ${msg}`);
    }
    if (data === null) throw new Error(`${what} returned a non-JSON response`);
    return data;
  }

  function headers(s) {
    const h = { 'Content-Type': 'application/json' };
    if (s.llmApiKey) h.Authorization = 'Bearer ' + s.llmApiKey;
    return h;
  }

  async function listModels(s) {
    if (s.provider === 'ollama') {
      const data = await request(trimSlash(s.llmUrl) + '/api/tags', { headers: headers(s) }, 'Ollama');
      return (data.models || []).map((m) => m.name || m.model).filter(Boolean);
    }
    const data = await request(openaiBase(s.llmUrl) + '/models', { headers: headers(s) }, 'LLM server');
    return (data.data || []).map((m) => m.id).filter(Boolean);
  }

  /**
   * @param s        settings
   * @param req      { messages, model, options:{temperature,max_tokens,top_p,seed,stop,num_ctx,format} }
   * @param signal   AbortSignal
   * @returns {Promise<{text:string, model:string, usage:object|null}>}
   */
  async function chat(s, req, signal) {
    const o = req.options || {};
    if (s.provider === 'ollama') {
      const options = {};
      if (o.temperature != null) options.temperature = Number(o.temperature);
      if (o.max_tokens != null) options.num_predict = Number(o.max_tokens);
      if (o.top_p != null) options.top_p = Number(o.top_p);
      if (o.seed != null) options.seed = Number(o.seed);
      if (o.stop != null) options.stop = Array.isArray(o.stop) ? o.stop : [String(o.stop)];
      if (o.num_ctx != null) options.num_ctx = Number(o.num_ctx);
      const body = { model: req.model, messages: req.messages, stream: false, options };
      if (o.format) body.format = o.format; // "json" or a JSON schema object
      if (s.keepAlive) body.keep_alive = s.keepAlive;
      const data = await request(trimSlash(s.llmUrl) + '/api/chat', { method: 'POST', headers: headers(s), body: JSON.stringify(body), signal }, 'Ollama');
      return {
        text: (data.message && data.message.content) || '',
        model: data.model || req.model,
        usage: { prompt_tokens: data.prompt_eval_count || null, completion_tokens: data.eval_count || null },
      };
    }

    const body = { model: req.model, messages: req.messages, stream: false };
    if (o.temperature != null) body.temperature = Number(o.temperature);
    if (o.max_tokens != null) body.max_tokens = Number(o.max_tokens);
    if (o.top_p != null) body.top_p = Number(o.top_p);
    if (o.seed != null) body.seed = Number(o.seed);
    if (o.stop != null) body.stop = o.stop;
    if (o.format === 'json') body.response_format = { type: 'json_object' };
    else if (o.format && typeof o.format === 'object') body.response_format = { type: 'json_schema', json_schema: { name: 'response', schema: o.format } };
    const data = await request(openaiBase(s.llmUrl) + '/chat/completions', { method: 'POST', headers: headers(s), body: JSON.stringify(body), signal }, 'LLM server');
    const choice = (data.choices || [])[0] || {};
    return {
      text: (choice.message && choice.message.content) || choice.text || '',
      model: data.model || req.model,
      usage: data.usage || null,
    };
  }

  /** Remove <think>...</think> reasoning blocks (DeepSeek-R1, Qwen3, ...). */
  function stripThinking(text) {
    let t = String(text).replace(/<think>[\s\S]*?<\/think>/gi, '');
    if (/<\/think>/i.test(t)) t = t.replace(/^[\s\S]*<\/think>/i, ''); // opening tag omitted by template
    return t.trim();
  }

  global.LocaliaLLM = { listModels, chat, stripThinking };
})(window);
