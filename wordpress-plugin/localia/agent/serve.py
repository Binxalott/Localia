#!/usr/bin/env python3
"""Serve the Localia agent on http://localhost:8787 and open it in your browser.

Keep the same port every time: the browser stores your settings per address,
so a different port means a fresh, empty settings page.
Usage:  python3 serve.py [port]
"""
import functools
import http.server
import os
import sys
import threading
import webbrowser

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8787
ROOT = os.path.dirname(os.path.abspath(__file__))


class Handler(http.server.SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header("Cache-Control", "no-cache")
        super().end_headers()

    def log_message(self, *args):  # keep the console quiet
        pass


def main():
    handler = functools.partial(Handler, directory=ROOT)
    try:
        server = http.server.ThreadingHTTPServer(("127.0.0.1", PORT), handler)
    except OSError:
        # Already running (e.g. started at login) - just open the page.
        print(f"Port {PORT} is busy; assuming Localia is already being served there.")
        webbrowser.open(f"http://localhost:{PORT}/")
        return
    url = f"http://localhost:{PORT}/"
    print(f"Localia agent: {url}  (only reachable from this computer)")
    print("Leave this window open. Press Ctrl+C to stop.")
    threading.Timer(0.6, lambda: webbrowser.open(url)).start()
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
