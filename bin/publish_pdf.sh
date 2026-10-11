#!/usr/bin/env bash
# Prints the public resume to PDF with headless Chrome on this machine and
# uploads it into the site's wwwroot/, where the "Download PDF" link finds it.
# Run after every import. The PDF is personal data: gitignored, never committed.
#
#   bin/publish_pdf.sh            # ssh alias "resume"
#   bin/publish_pdf.sh otherhost
set -euo pipefail

HOST="${1:-resume}"
URL="https://resume.robnugen.com/"
FILENAME="Rob_Nugen_resume.pdf"   # must equal \Resume\PublishedPdf::FILENAME
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

google-chrome --headless=new --disable-gpu --no-pdf-header-footer \
    --print-to-pdf="$WORK/$FILENAME" "$URL"
test -s "$WORK/$FILENAME"
rsync -a "$WORK/$FILENAME" "$HOST:resume.robnugen.com/wwwroot/$FILENAME"
echo "Published $URL$FILENAME"
