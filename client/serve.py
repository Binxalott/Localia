#!/usr/bin/env python3
"""Serve the Localia agent on http://localhost:8787 and open it in your browser.

Keep the same port every time: the browser stores your settings per address,
so a different port means a fresh, empty settings page.
Usage:  python3 serve.py [port] [--no-open]

Also runs the web-research helper the agent page uses for jobs that ask for web
research: browsers can't fetch other sites' pages (CORS), so the page asks this
helper to fetch search results and pages for it. The helper:
  - only answers requests addressed to localhost on this port (blocks DNS rebinding),
  - needs a random per-run token that only this page can read (blocks other websites),
  - refuses private, loopback and link-local addresses unless the page explicitly
    allows them for your own search server (e.g. SearXNG on localhost),
  - only fetches http(s), follows at most 5 redirects (each re-checked) and caps sizes.
"""
import functools
import http.server
import ipaddress
import json
import os
import secrets
import socket
import sys
import threading
import urllib.error
import urllib.parse
import urllib.request
import webbrowser

ARGS = [a for a in sys.argv[1:] if not a.startswith("--")]
PORT = int(ARGS[0]) if ARGS else 8787
OPEN = "--no-open" not in sys.argv
ROOT = os.path.dirname(os.path.abspath(__file__))
HELPER_VERSION = 1
TOKEN = secrets.token_hex(24)
ALLOWED_HOSTS = {f"localhost:{PORT}", f"127.0.0.1:{PORT}"}
FORWARD_HEADERS = {"accept", "accept-language", "authorization", "x-subscription-token", "content-type"}
USER_AGENT = "Mozilla/5.0 (compatible; LocaliaAgent/1.1; +https://github.com/Binxalott/Localia)"
MAX_BYTES = 3 * 1024 * 1024
TIMEOUT = 20


class FetchError(Exception):
    def __init__(self, code, message):
        super().__init__(message)
        self.code = code


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):  # we follow redirects ourselves, re-checking each hop
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def check_url(url, allow_private):
    parts = urllib.parse.urlsplit(url)
    if parts.scheme not in ("http", "https") or not parts.hostname:
        raise FetchError("bad_url", "Only http and https URLs can be fetched.")
    if allow_private:
        return
    try:
        infos = socket.getaddrinfo(parts.hostname, parts.port or (443 if parts.scheme == "https" else 80))
    except socket.gaierror:
        raise FetchError("dns_failed", f"Could not resolve {parts.hostname}.")
    for info in infos:
        ip = ipaddress.ip_address(info[4][0].split("%")[0])
        if getattr(ip, "ipv4_mapped", None):
            ip = ip.ipv4_mapped
        if not ip.is_global:
            raise FetchError("private_address", f"{parts.hostname} points to a private or local address; refusing to fetch it.")


def fetch(req):
    url = str(req.get("url") or "")
    method = "POST" if req.get("method") == "POST" else "GET"
    allow_private = bool(req.get("allow_private"))
    headers = {"User-Agent": USER_AGENT}
    for k, v in (req.get("headers") or {}).items():
        if isinstance(k, str) and isinstance(v, str) and k.lower() in FORWARD_HEADERS:
            headers[k] = v
    body = req.get("body")
    data = body.encode("utf-8") if method == "POST" and isinstance(body, str) else None

    for _ in range(6):
        check_url(url, allow_private)
        request = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            resp = OPENER.open(request, timeout=TIMEOUT)
        except urllib.error.HTTPError as e:  # 3xx (redirects are not followed automatically) and 4xx/5xx
            resp = e
        except (urllib.error.URLError, OSError) as e:
            raise FetchError("fetch_failed", f"Could not fetch {url}: {getattr(e, 'reason', e)}")
        status = resp.status if hasattr(resp, "status") else resp.code
        location = resp.headers.get("Location")
        if status in (301, 302, 303, 307, 308) and location:
            url = urllib.parse.urljoin(url, location)
            if status == 303 or (status in (301, 302) and method == "POST"):
                method, data = "GET", None
            continue
        content_type = resp.headers.get("Content-Type", "")
        raw = resp.read(MAX_BYTES + 1)[:MAX_BYTES]
        mime = content_type.split(";")[0].strip().lower()
        texty = mime.startswith("text/") or mime in ("application/json", "application/xhtml+xml", "application/xml", "")
        charset = resp.headers.get_content_charset() or "utf-8"
        try:
            text = raw.decode(charset, errors="replace") if texty else ""
        except LookupError:
            text = raw.decode("utf-8", errors="replace")
        return {"ok": True, "status": status, "url": url, "content_type": content_type, "body": text}
    raise FetchError("too_many_redirects", "Too many redirects.")


class Handler(http.server.SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header("Cache-Control", "no-cache")
        super().end_headers()

    def log_message(self, *args):  # keep the console quiet
        pass

    def host_ok(self):
        return (self.headers.get("Host") or "").lower() in ALLOWED_HOSTS

    def send_json(self, status, payload):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if not self.host_ok():
            return self.send_error(403)
        if self.path.split("?")[0] == "/helper/info":
            return self.send_json(200, {"ok": True, "helper": HELPER_VERSION, "token": TOKEN})
        return super().do_GET()

    def do_HEAD(self):
        if not self.host_ok():
            return self.send_error(403)
        return super().do_HEAD()

    def do_POST(self):
        if not self.host_ok() or self.path.split("?")[0] != "/helper/fetch":
            return self.send_error(403)
        if not secrets.compare_digest(self.headers.get("X-Localia-Helper", ""), TOKEN):
            return self.send_json(403, {"ok": False, "error": "bad_token", "message": "Helper token missing or wrong; reload the page."})
        try:
            length = min(int(self.headers.get("Content-Length") or 0), 1024 * 1024)
            req = json.loads(self.rfile.read(length) or b"{}")
            if not isinstance(req, dict):
                raise ValueError
        except ValueError:
            return self.send_json(400, {"ok": False, "error": "bad_request", "message": "Invalid JSON."})
        try:
            return self.send_json(200, fetch(req))
        except FetchError as e:
            return self.send_json(200, {"ok": False, "error": e.code, "message": str(e)})
        except Exception as e:  # never crash the server on one bad page
            return self.send_json(200, {"ok": False, "error": "fetch_failed", "message": str(e)[:300]})


def main():
    handler = functools.partial(Handler, directory=ROOT)
    try:
        server = http.server.ThreadingHTTPServer(("127.0.0.1", PORT), handler)
    except OSError:
        # Already running (e.g. started at login) - just open the page.
        print(f"Port {PORT} is busy; assuming Localia is already being served there.")
        if OPEN:
            webbrowser.open(f"http://localhost:{PORT}/")
        return
    url = f"http://localhost:{PORT}/"
    print(f"Localia agent: {url}  (only reachable from this computer)")
    print("Leave this window open. Press Ctrl+C to stop.")
    if OPEN:
        threading.Timer(0.6, lambda: webbrowser.open(url)).start()
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
