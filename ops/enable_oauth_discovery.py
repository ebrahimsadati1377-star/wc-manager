#!/usr/bin/env python3
"""Enable only WC Manager's public OAuth metadata routes; run as server admin."""
import os
import pathlib
import re
import shutil
import subprocess
import time

CONFIG = pathlib.Path("/etc/nginx/sites-available/wc-manager")
MARKER = "# WC Manager public OAuth discovery"

def patched(text):
    if MARKER in text:
        return text
    anchor = "    listen 443 ssl;"
    if text.count(anchor) != 1:
        raise RuntimeError("Expected exactly one WC Manager HTTPS server; refusing to edit")
    https = text.split(anchor, 1)[1]
    if not re.search(r"server_name\s+manage\.bajistyle\.ir\s*;", https):
        raise RuntimeError("Unexpected HTTPS hostname")
    root = re.search(r"^\s*root\s+([^;]+);", https, re.M)
    if not root or not re.fullmatch(r"/[A-Za-z0-9_./-]+", root.group(1)):
        raise RuntimeError("Cannot safely identify the public document root")
    docroot = root.group(1)
    routes = [
        ("/.well-known/oauth-protected-resource", "oauth-protected-resource"),
        ("/.well-known/oauth-protected-resource/mcp.php", "oauth-protected-resource"),
        ("/.well-known/oauth-authorization-server", "oauth-authorization-server"),
    ]
    blocks = ["", "    " + MARKER]
    for route, filename in routes:
        blocks += [
            "    location = " + route + " {",
            "        alias " + docroot + "/.well-known/" + filename + ";",
            "        default_type application/json;",
            "        add_header Cache-Control \"no-store\" always;",
            "        allow all;",
            "    }",
        ]
    return text.replace(anchor, anchor + "\n" + "\n".join(blocks), 1)

def main():
    if os.geteuid() != 0:
        raise SystemExit("Run with sudo as the server administrator.")
    old = CONFIG.read_text()
    new = patched(old)
    if old == new:
        print("OAuth discovery locations already configured.")
        return
    backup = CONFIG.with_name(CONFIG.name + ".oauth-backup-" + str(time.time_ns()))
    shutil.copy2(CONFIG, backup)
    try:
        CONFIG.write_text(new)
        subprocess.run(["nginx", "-t"], check=True)
        subprocess.run(["systemctl", "reload", "nginx"], check=True)
    except BaseException:
        shutil.copy2(backup, CONFIG)
        subprocess.run(["nginx", "-t"], check=True)
        subprocess.run(["systemctl", "reload", "nginx"], check=True)
        raise
    print("OAuth metadata routes enabled. Backup:", backup)
    print("Verify both public metadata URLs return HTTP 200 and application/json.")

if __name__ == "__main__":
    main()
