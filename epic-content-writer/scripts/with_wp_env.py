#!/usr/bin/env python3
"""Run a command with only the EPIC WordPress connection values from website/.env.

Credentials are added to the child process environment and are never printed.
Use this for the shell publishing wrappers in unattended Codex automations.
"""

from __future__ import annotations

import argparse
import os
from pathlib import Path
import sys

from update_post_content import DEFAULT_ENV, parse_env_file


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--env-file", type=Path, default=DEFAULT_ENV)
    parser.add_argument("command", nargs=argparse.REMAINDER,
                        help="Command and arguments to run after --env-file")
    args = parser.parse_args()
    command = args.command
    if command and command[0] == "--":
        command = command[1:]
    if not command:
        parser.error("provide a command to run")

    environment = {**parse_env_file(args.env_file), **os.environ}
    if not environment.get("WC_URL", "").strip():
        sys.exit("WC_URL is missing from the environment and selected env file")
    if not environment.get("WP_USER", "").strip():
        sys.exit("WP_USER is missing from the environment and selected env file")
    if not (environment.get("WP_APP_PASSWORD", "").strip()
            or environment.get("WP_PASSWORD", "").strip()):
        sys.exit("WP_APP_PASSWORD or WP_PASSWORD is missing from the environment and selected env file")

    os.execvpe(command[0], command, environment)
    return 127


if __name__ == "__main__":
    raise SystemExit(main())
