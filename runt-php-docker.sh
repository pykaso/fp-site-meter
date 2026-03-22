#!/usr/bin/env bash
set -euo pipefail

IMAGE_NAME="${IMAGE_NAME:-my-php}"

if [ $# -lt 1 ]; then
  echo "Usage: $0 <script.php> [args...]"
  exit 1
fi

SCRIPT="$1"
shift

if [ ! -f "$SCRIPT" ]; then
  echo "File not found: $SCRIPT"
  exit 1
fi

docker run --rm -it \
  -v "$(pwd):/app" \
  -w /app \
  "${IMAGE_NAME}" \
  php "$SCRIPT" "$@"