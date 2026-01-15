#!/bin/bash
# Wrapper script - runs via PHP with production flag
SKILL_PRODUCTION=1 php ~/.claude/skills/slack-cli/src/slack-cli "$@"
