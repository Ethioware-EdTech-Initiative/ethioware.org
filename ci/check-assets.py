#!/usr/bin/env python3
"""Verify every local asset/link reference in the site resolves on disk.

Covers ALL HTML (root pages, certificates/, pages/, cognify/) — not just the
handful the html-validate/lychee jobs lint. It models how each page is *served*:

  * certificates/<CODE>.html is published at the root URL /<CODE>, so its
    relative `assets/...` references resolve against the SITE ROOT, not the
    certificates/ folder.
  * every other page resolves relative references against its own directory.
  * a leading "/" means site root; ".." is clamped at the root (as browsers do).

A baseline of already-missing references (ci/known-missing-assets.txt) is
accepted so the current backlog doesn't block CI, but ANY new missing reference
fails the build. When a missing asset is supplied, drop its line from the
baseline.

Run from the repo root:  python3 ci/check-assets.py
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BASELINE = os.path.join(ROOT, "ci", "known-missing-assets.txt")

REF_RE = re.compile(r'(?:src|href|poster)\s*=\s*"([^"]+)"', re.IGNORECASE)
SKIP_PREFIX = ("http://", "https://", "//", "#", "mailto:", "tel:", "data:",
               "javascript:")
HTML_DIRS = (".", "certificates", "pages", "cognify")


def served_base(html_relpath):
    """Site-root-relative directory that the page's relative refs resolve against."""
    d = os.path.dirname(html_relpath)
    if d == "certificates":          # served at site root
        return ""
    return d


def resolve(ref, base):
    ref = ref.split("?")[0].split("#")[0]
    if not ref:
        return None
    if ref.startswith("/"):
        parts = ref.lstrip("/").split("/")
    else:
        parts = (base.split("/") if base else []) + ref.split("/")
    # normalise . and .. with root clamping
    stack = []
    for p in parts:
        if p in ("", "."):
            continue
        if p == "..":
            if stack:
                stack.pop()
            continue
        stack.append(p)
    return "/".join(stack)


def load_baseline():
    if not os.path.exists(BASELINE):
        return set()
    out = set()
    for line in open(BASELINE, encoding="utf-8"):
        line = line.split("#")[0].strip()
        if line:
            out.add(line)
    return out


def main():
    baseline = load_baseline()
    missing = {}   # resolved path -> set(pages)
    pages = 0
    for d in HTML_DIRS:
        dp = os.path.join(ROOT, d)
        if not os.path.isdir(dp):
            continue
        for f in sorted(os.listdir(dp)):
            if not f.endswith(".html"):
                continue
            rel = os.path.normpath(os.path.join(d, f)) if d != "." else f
            pages += 1
            base = served_base(rel)
            text = open(os.path.join(ROOT, rel), encoding="utf-8", errors="ignore").read()
            for ref in REF_RE.findall(text):
                if ref.startswith(SKIP_PREFIX):
                    continue
                target = resolve(ref, base)
                if not target:
                    continue
                if not os.path.exists(os.path.join(ROOT, target)):
                    missing.setdefault(target, set()).add(rel)

    new = {t: p for t, p in missing.items() if t not in baseline}
    stale = sorted(baseline - set(missing))   # baseline entries now resolved

    print(f"scanned {pages} HTML pages")
    print(f"known-missing (baselined): {len(baseline)}")
    print(f"currently missing: {len(missing)}  |  new: {len(new)}")

    if stale:
        print(f"\nNote: {len(stale)} baselined entries now resolve — "
              f"please remove them from {os.path.relpath(BASELINE, ROOT)}:")
        for t in stale[:50]:
            print("  - " + t)

    if new:
        print(f"\nFAIL: {len(new)} NEW broken reference(s):")
        for t in sorted(new):
            ex = sorted(new[t])[:3]
            print(f"  {t}   <- {', '.join(ex)}" + (" ..." if len(new[t]) > 3 else ""))
        sys.exit(1)

    print("\nOK: no new broken references")


if __name__ == "__main__":
    main()
