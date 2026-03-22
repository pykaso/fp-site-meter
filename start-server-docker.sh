#!/usr/bin/env bash
set -euo pipefail

IMAGE_NAME="${IMAGE_NAME:-my-php}"
PORT="${PORT:-8080}"

docker run --rm -it \
  -p "${PORT}:8080" \
  -v "$(pwd):/app" \
  -w /app \
  "${IMAGE_NAME}" \
  php -S 0.0.0.0:8080