#!/usr/bin/env bash
# Starts Apache in the foreground (logs stay visible in this terminal).
# Recreates the tmpfs-backed runtime dirs that are empty on every fresh boot.
set -euo pipefail

sudo mkdir -p /var/run/apache2 /var/lock /run/lock

exec sudo -E apache2ctl -D FOREGROUND
