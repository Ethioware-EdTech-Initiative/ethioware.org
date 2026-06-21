#!/usr/bin/env python3
"""Generate sitemap.xml for ethioware.org.

Lists the primary public pages plus every certificate/interview page at its
canonical SHORT URL (e.g. https://ethioware.org/WI10092516). lastmod is taken
from each file's last git commit date so the output is deterministic (a CI job
can regenerate and assert it is unchanged).

Run from the repo root:  python3 ci/gen-sitemap.py
"""
import os
import subprocess
import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BASE = "https://ethioware.org"
TODAY = datetime.date.today().isoformat()

PRIMARY = [
    ("/", "index.html", "1.0", "weekly"),
    ("/apply.html", "apply.html", "0.8", "monthly"),
    ("/pay.html", "pay.html", "0.8", "monthly"),
    ("/privacy.html", "privacy.html", "0.4", "yearly"),
    ("/anteneh.html", "anteneh.html", "0.6", "monthly"),
    ("/biniyam.html", "biniyam.html", "0.6", "monthly"),
    ("/samuel.html", "samuel.html", "0.6", "monthly"),
]


def lastmod(path):
    try:
        out = subprocess.run(
            ["git", "log", "-1", "--format=%cs", "--", path],
            cwd=ROOT, capture_output=True, text=True, check=True,
        ).stdout.strip()
        return out or TODAY
    except Exception:
        return TODAY


def url_entry(loc, path, priority, changefreq):
    return (
        "  <url>\n"
        f"    <loc>{BASE}{loc}</loc>\n"
        f"    <lastmod>{lastmod(path)}</lastmod>\n"
        f"    <changefreq>{changefreq}</changefreq>\n"
        f"    <priority>{priority}</priority>\n"
        "  </url>\n"
    )


def main():
    parts = [
        '<?xml version="1.0" encoding="UTF-8"?>\n',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n',
    ]
    for loc, path, pr, cf in PRIMARY:
        parts.append(url_entry(loc, os.path.join(ROOT, path), pr, cf))

    cert_dir = os.path.join(ROOT, "certificates")
    for name in sorted(os.listdir(cert_dir)):
        if not name.endswith(".html"):
            continue
        code = name[:-5]
        parts.append(url_entry(f"/{code}", os.path.join("certificates", name), "0.5", "yearly"))

    parts.append("</urlset>\n")
    out = os.path.join(ROOT, "sitemap.xml")
    with open(out, "w", encoding="utf-8") as fh:
        fh.write("".join(parts))
    print(f"wrote {out} with {len(PRIMARY)} primary + "
          f"{len(os.listdir(cert_dir))} cert URLs")


if __name__ == "__main__":
    main()
