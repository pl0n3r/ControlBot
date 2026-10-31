#!/usr/bin/env bash
# Read-only deployment smoke. Credentials and CA must be private NAME_FILE paths.
set -euo pipefail
python3 - <<'PY'
from __future__ import annotations

import base64
import http.cookiejar
import http.client
from html.parser import HTMLParser
import json
import os
import re
import ssl
import stat
import sys
import urllib.error
import urllib.parse
import urllib.request


def fail() -> None:
    print("NO-GO: deployment smoke could not verify HTTPS, login, private access and release identity.", file=sys.stderr)
    raise SystemExit(1)


def private_file(name: str, *, max_size: int = 4096) -> str:
    path = os.environ.get(name, "")
    if not path or "://" in path or not os.path.isabs(path):
        fail()
    try:
        metadata = os.lstat(path)
        if (not stat.S_ISREG(metadata.st_mode) or stat.S_IMODE(metadata.st_mode) & 0o077
                or (hasattr(os, "getuid") and metadata.st_uid != os.getuid())
                or metadata.st_size == 0 or metadata.st_size > max_size):
            fail()
        with open(path, "rb") as handle:
            content = handle.read(max_size + 1)
        if not content or len(content) > max_size:
            fail()
        value = content.decode("utf-8").rstrip("\r\n")
        if not value or "\n" in value or "\r" in value or "\0" in value:
            fail()
        return value
    except (OSError, UnicodeError):
        fail()


class CSRFParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.tokens: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag != "input":
            return
        fields = dict(attrs)
        if fields.get("name") == "csrf" and fields.get("type", "hidden") == "hidden":
            value = fields.get("value")
            if isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9_-]{16,256}", value):
                self.tokens.append(value)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


def request(opener, url: str, method: str = "GET", data: bytes | None = None,
            headers: dict[str, str] | None = None) -> tuple[int, object, bytes]:
    req = urllib.request.Request(url, data=data, headers=headers or {}, method=method)
    try:
        response = opener.open(req, timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        body = response.read(65537)
        if len(body) > 65536:
            fail()
        return response.status, response.headers, body


def location_matches(headers, expected: str, origin: str) -> bool:
    value = headers.get("Location")
    if not value:
        return False
    target = urllib.parse.urlsplit(urllib.parse.urljoin(origin + "/", value))
    expected_parts = urllib.parse.urlsplit(origin)
    return (target.scheme == "https" and target.netloc == expected_parts.netloc
            and target.path == expected and not target.query and not target.fragment)


def main() -> None:
    origin = os.environ.get("SMOKE_BASE_URL", "")
    parts = urllib.parse.urlsplit(origin)
    if (parts.scheme != "https" or not parts.hostname or parts.username or parts.password
            or parts.path not in ("", "/") or parts.query or parts.fragment
            or origin != f"https://{parts.netloc}" and origin != f"https://{parts.netloc}/"):
        fail()
    origin = f"https://{parts.netloc}"
    sha = os.environ.get("SMOKE_EXPECTED_SHA", "")
    version = os.environ.get("SMOKE_EXPECTED_VERSION", "")
    if (not re.fullmatch(r"[0-9a-f]{40}", sha)
            or not re.fullmatch(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)", version)):
        fail()
    user = private_file("SMOKE_USERNAME_FILE", max_size=256)
    password = private_file("SMOKE_PASSWORD_FILE")
    health_user = private_file("SMOKE_HEALTH_USERNAME_FILE", max_size=256) if os.environ.get("SMOKE_HEALTH_USERNAME_FILE") else user
    health_password = private_file("SMOKE_HEALTH_PASSWORD_FILE") if os.environ.get("SMOKE_HEALTH_PASSWORD_FILE") else password
    cafile = os.environ.get("SMOKE_CA_FILE")
    if cafile:
        try:
            meta = os.lstat(cafile)
            if (not stat.S_ISREG(meta.st_mode) or stat.S_IMODE(meta.st_mode) & 0o077
                    or meta.st_size <= 0 or meta.st_size > 8192):
                fail()
        except OSError:
            fail()
    context = ssl.create_default_context(cafile=cafile)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=context),
                                         urllib.request.HTTPCookieProcessor(jar), NoRedirect())
    auth = base64.b64encode((health_user + ":" + health_password).encode()).decode("ascii")
    status, headers, data = request(opener, origin + "/health", headers={"Authorization": "Basic " + auth})
    if status != 200 or not headers.get("Content-Type", "").lower().startswith("application/json"):
        fail()
    try:
        identity = json.loads(data)
    except (ValueError, UnicodeError):
        fail()
    if (not isinstance(identity, dict) or set(identity) != {"status", "version", "release_sha"}
            or identity["status"] != "ok" or identity["version"] != version
            or identity["release_sha"] != sha):
        fail()
    status, headers, _ = request(opener, origin + "/overview")
    if not (status in (401, 403) or status in (302, 303) and location_matches(headers, "/login", origin)):
        fail()
    status, headers, data = request(opener, origin + "/login")
    if status != 200:
        fail()
    parser = CSRFParser()
    parser.feed(data.decode("utf-8", "strict"))
    if len(parser.tokens) != 1:
        fail()
    form = urllib.parse.urlencode({"csrf": parser.tokens[0], "username": user, "password": password}).encode("utf-8")
    status, headers, _ = request(opener, origin + "/login", method="POST", data=form,
                                 headers={"Content-Type": "application/x-www-form-urlencoded"})
    if status != 303 or not location_matches(headers, "/overview", origin) or not list(jar):
        fail()
    status, _, _ = request(opener, origin + "/overview")
    if status != 200:
        fail()
    print("GO: verified HTTPS, restricted access, test login and exact release identity.")


try:
    main()
except Exception:
    fail()
PY
