#!/usr/bin/env python3
"""Render ```mermaid blocks in Markdown to PNG; write processed Markdown."""
from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path

MERMAID_BLOCK = re.compile(r"```mermaid\s*\n(.*?)```", re.DOTALL | re.IGNORECASE)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("source", type=Path)
    parser.add_argument("output", type=Path)
    parser.add_argument("--assets-dir", type=Path, required=True)
    parser.add_argument("--image-rel-prefix", required=True)
    parser.add_argument(
        "--puppeteer-config",
        type=Path,
        default=Path(__file__).resolve().parent / "puppeteer-config.json",
    )
    args = parser.parse_args()

    text = args.source.read_text(encoding="utf-8")
    count = 0
    args.assets_dir.mkdir(parents=True, exist_ok=True)
    args.output.parent.mkdir(parents=True, exist_ok=True)

    def replacer(match: re.Match[str]) -> str:
        nonlocal count
        count += 1
        png = args.assets_dir / f"diagram-{count:02d}.png"
        mmd = args.assets_dir / f"diagram-{count:02d}.mmd"
        mmd.write_text(match.group(1).strip() + "\n", encoding="utf-8")

        cmd = [
            "npx",
            "--yes",
            "@mermaid-js/mermaid-cli@11.15.0",
            "-i",
            str(mmd),
            "-o",
            str(png),
            "-b",
            "white",
            "-p",
            str(args.puppeteer_config),
        ]
        result = subprocess.run(cmd, capture_output=True, text=True)
        mmd.unlink(missing_ok=True)

        if result.returncode != 0:
            print(
                f"Warning: diagram {count} in {args.source} could not be rendered; keeping source block.",
                file=sys.stderr,
            )
            print(result.stderr or result.stdout, file=sys.stderr)
            return match.group(0)

        rel = f"{args.image_rel_prefix}/diagram-{count:02d}.png"
        return f"\n\n![Diagram {count}]({rel})\n\n"

    processed = MERMAID_BLOCK.sub(replacer, text)
    args.output.write_text(processed, encoding="utf-8")
    print(f"{args.source}: {count} mermaid diagram(s)", file=sys.stderr)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
