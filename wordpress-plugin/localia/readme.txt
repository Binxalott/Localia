=== Localia – Use Your Own AI ===
Contributors: binxalott
Tags: ai, ollama, content generation, local llm, automation
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Write and refresh posts with the AI running on your own PC (Ollama, LM Studio and more). No API bills, no port forwarding.

== Description ==

Localia connects your WordPress site to an AI model running on **your own computer**. You get AI-written content without paying per token to a cloud provider, and your prompts never leave your own hardware and your own site.

**How it works**

1. Install a free local AI app such as [Ollama](https://ollama.com) or LM Studio on your PC.
2. Download the Localia agent from the plugin’s Settings page. It comes pre-configured for your site.
3. Start it. Your PC checks your site for work, runs it on your local model and sends the result back.

Your PC always makes the outgoing connection, so your home router, firewall and changing IP address don’t matter. Nothing on your PC is exposed to the internet.

**Features**

* **Generate with my AI**: a box in the post editor (block editor and classic editor) to draft intros, sections, FAQs and more, then insert them with one click.
* **Tasks**: reusable recipes such as “write a post about the next topic on my list” or “refresh my oldest post in this category”.
* **Daily scheduler**: run each task at any times of day, on the days of the week you choose.
* **Topic lists**: give a list of topics and Localia works through them in order or at random.
* **Avoids repeats**: optionally shows the AI your recent post titles so it picks fresh angles.
* **Safe by default**: new posts are saved as drafts; rewrites keep the old version under Revisions.
* **Dashboard**: see whether your PC is online, what’s queued, what changed and a full activity log.
* **Secure connection**: every request is signed (HMAC-SHA256) with a secret only your site and your PC know, with replay protection.

**Localia Pro** (separate add-on) adds calendar scheduling with recurring rules, AI comment moderation and reply drafts. See the “Free vs Pro” tab.

**Requirements**

* A PC (Windows, macOS or Linux) that can run a local AI model, with a modern browser.
* Python 3 or Node.js on that PC to start the agent (most Macs and Linux PCs already have Python).

== Installation ==

1. Install and activate the plugin.
2. Go to **Localia → Settings** and follow the three “Connect your PC” steps.
3. Go to **Localia → Tasks** and create your first task, or use the “Localia” box when editing a post.

== Frequently Asked Questions ==

= Does this send my content to an AI company? =

No. Prompts go from your site to your own PC and the answer comes back. The plugin doesn’t contact any third-party service.

= My PC is off. What happens? =

Jobs wait in the queue and run when the agent next connects. If a task’s previous run is still waiting, the next run is skipped instead of piling up.

= Which models work? =

Anything your local runtime can run. With Ollama, `llama3.1:8b`, `qwen2.5:7b` or `mistral` are good starting points for writing. Larger models write better but are slower.

= Scheduled tasks start late. =

WordPress runs its scheduler when someone visits the site. Localia also runs it every time your PC checks in. For exact timing, add a server cron job: see **Localia → Schedule → Running on time**.

= The agent says “Could not reach Ollama”. =

Start the agent with the included start script (it opens `http://localhost:8787`), which Ollama allows by default. For LM Studio, switch on “Enable CORS” in its server settings.

= What if my site gets hacked? Could someone use it to attack my PC? =

The agent on your PC has a **prompt guard**. It checks every job before it reaches your AI and blocks attempts to override instructions, look for files, passwords or crypto wallets, run commands or send data elsewhere. Blocked jobs never reach the AI, and the agent pauses so you can look. For extra safety, choose the language your instructions are sent in (Settings → Writing defaults) and tick “disregard prompts in other languages”: the agent then blocks anything written in another language. Your local AI can only read and write text: it can't open files or run programs on your PC.

= Is it secure? =

The agent signs every request with a 48-character secret and a one-time nonce, and verifies the site’s signed replies. The secret is stored in your WordPress options and in the downloaded agent’s `js/config.js`, so keep that folder private. If it leaks, use **Regenerate secret** in Settings.

== Screenshots ==

1. Dashboard: PC status, queue, upcoming runs and recent changes.
2. Task editor: instructions, topics, times of day and post settings.
3. Schedule: your day at a glance.
4. “Generate with my AI” in the block editor.
5. Connect your PC in three steps.

== Changelog ==

= 0.1.0 =
* First release: editor box, tasks (new posts and rewrites), daily scheduler, dashboard and log, pre-configured agent download.

== Upgrade Notice ==

= 0.1.0 =
First release.

== Privacy ==

Localia doesn’t send data to any third party. The content you ask it to write, and for rewrites the existing post text, is sent to the Localia agent on the PC you connected. Request history is kept in your database for the number of days set in Settings (30 by default).

The bundled desktop agent (the `agent` folder) is also available under the MIT license from https://github.com/binxalott/localia.
