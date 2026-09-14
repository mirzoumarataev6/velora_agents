#!/usr/bin/env bash
# Started as a fourth `composer run dev` process (see composer.json) alongside
# the PHP server, the queue worker and Vite. Creates its own venv on first run
# so it doesn't pollute (or depend on) whatever Python the rest of the machine
# has, then installs requirements and starts uvicorn on port 8001.
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -d venv ]; then
    python3 -m venv venv
fi

# shellcheck disable=SC1091
source venv/bin/activate
pip install --quiet --disable-pip-version-check -r requirements.txt

exec uvicorn main:app --host 127.0.0.1 --port 8001
