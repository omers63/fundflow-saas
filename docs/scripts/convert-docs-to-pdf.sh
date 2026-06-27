#!/usr/bin/env bash
# Convert docs/**/*.md to docs/pdf/**/*.pdf, rendering Mermaid diagrams as PNGs.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCS_DIR="$ROOT/docs"
PDF_DIR="$DOCS_DIR/pdf"
BUILD_DIR="$PDF_DIR/_build"
ASSETS_DIR="$PDF_DIR/_assets"
SCRIPT_DIR="$DOCS_DIR/scripts"
PUPPETEER_CONFIG="$SCRIPT_DIR/puppeteer-config.json"

cd "$ROOT"

mkdir -p "$BUILD_DIR" "$ASSETS_DIR"

ok=0
failed=0
mermaid_files=0

while IFS= read -r md; do
  rel="${md#$DOCS_DIR/}"
  stem="${rel%.md}"
  pdf="$PDF_DIR/${stem}.pdf"
  build_md="$BUILD_DIR/${stem}.md"
  assets="$ASSETS_DIR/${stem}"
  image_rel="../_assets/${stem}"

  mkdir -p "$(dirname "$pdf")" "$(dirname "$build_md")" "$assets"

    if grep -q '```mermaid' "$md" 2>/dev/null; then
    mermaid_files=$((mermaid_files + 1))
    python3 "$SCRIPT_DIR/preprocess-mermaid.py" \
      "$md" \
      "$build_md" \
      --assets-dir "$assets" \
      --image-rel-prefix "$image_rel" \
      --puppeteer-config "$PUPPETEER_CONFIG" 2>&1 || true
    source_md="$build_md"
  else
    source_md="$md"
  fi

  resource_paths="$(dirname "$source_md")"
  if [[ -d "$assets" ]]; then
    resource_paths="${resource_paths}:${assets}"
  fi

  if pandoc "$source_md" -o "$pdf" \
    --pdf-engine=xelatex \
    -V geometry:margin=1in \
    -V linkcolor:blue \
    --resource-path="$resource_paths" 2>/dev/null; then
    ok=$((ok + 1))
  else
    echo "FAILED (pandoc): $md" >&2
    failed=$((failed + 1))
  fi
done < <(find "$DOCS_DIR" -name '*.md' \
  -not -path "$PDF_DIR/*" \
  -not -path "$SCRIPT_DIR/*" \
  | sort)

echo "PDFs: $ok ok, $failed failed ($mermaid_files files with mermaid diagrams)"
exit "$failed"
