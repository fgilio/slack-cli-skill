#!/bin/bash
# Wrapper script - runs via PHP with production flag
SLACK_CLI_PRODUCTION=1 php ~/.claude/skills/slack-cli/src/slack-cli "$@"
