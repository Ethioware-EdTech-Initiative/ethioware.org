#!/usr/bin/env python3
"""Emulate the root .htaccess rewrite rules against the on-disk tree.

This is a static, offline check: it does not run Apache. It encodes the
intent of .htaccess so CI (and humans) can confirm that every certificate
short URL still resolves to a real file after the certificates/ relocation,
and that top-level pages are unaffected.

Run from the repo root:  python3 ci/htaccess-route-check.py
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

CERT_RE = re.compile(r"^([A-Za-z0-9_-]+)(?:\.html)?$")


def resolve(url_path):
    """Return the file served for a request path (relative, no leading /)."""
    p = url_path.lstrip("/")

    # Real file / directory wins (assets/, cognify/, etc.)
    if p and os.path.isfile(os.path.join(ROOT, p)):
        return p
    if p and os.path.isdir(os.path.join(ROOT, p)):
        return p + "/  (dir)"

    # Certificate internal rewrite
    m = CERT_RE.match(p)
    if m:
        cert = os.path.join("certificates", m.group(1) + ".html")
        if os.path.isfile(os.path.join(ROOT, cert)):
            return cert

    # Extensionless pretty URL for top-level pages
    if os.path.isfile(os.path.join(ROOT, p + ".html")):
        return p + ".html"

    return None  # 404


def main():
    failures = []
    checked = 0

    cert_dir = os.path.join(ROOT, "certificates")
    for name in sorted(os.listdir(cert_dir)):
        if not name.endswith(".html"):
            continue
        code = name[:-5]
        expected = os.path.join("certificates", name)
        for url in (code, code + ".html"):
            checked += 1
            got = resolve(url)
            if got != expected:
                failures.append(f"/{url}  ->  {got!r}  (expected {expected!r})")

    # Top-level pages must NOT be captured by the certificate rule.
    for page in ("index", "apply", "pay", "privacy", "anteneh", "biniyam", "samuel"):
        checked += 1
        got = resolve(page)
        if got != page + ".html":
            failures.append(f"/{page}  ->  {got!r}  (expected {page}.html)")

    # A bogus URL must 404.
    checked += 1
    if resolve("definitely-not-a-real-page") is not None:
        failures.append("/definitely-not-a-real-page resolved but should 404")

    print(f"checked {checked} request paths")
    if failures:
        print(f"FAIL: {len(failures)} route(s) did not resolve as expected:")
        for f in failures[:40]:
            print("  " + f)
        sys.exit(1)
    print("OK: all certificate short URLs and top-level pages resolve correctly")


if __name__ == "__main__":
    main()
