---
name: slack-cli
description: >
  Read-only Slack CLI. Access DMs, private channels, and everything visible in Slack. Keywords: slack, slack-cli. Use when user asks about Slack messages, channels, or DMs.
user-invocable: true
disable-model-invocation: false
---

# slack-cli

Read-only Slack CLI. Access DMs, private channels, and everything you can see in Slack.

## Execution

`slack-cli` is a self-contained binary available on PATH.
Run it directly - never prefix with `bun`, `node`, `php`, or any runtime.

## Quick Reference

| Command | Purpose |
|---------|---------|
| `slack-cli config` | Setup/show tokens |
| `slack-cli channels:list` | List channels |
| `slack-cli channels:info <channel>` | Channel details + members |
| `slack-cli messages:history <channel>` | Read messages |
| `slack-cli thread:read <url>` | Read thread from URL |
| `slack-cli search <query>` | Search messages |
| `slack-cli users:lookup <query>` | Find users |
| `slack-cli users:info <user>` | User details |
| `slack-cli files:get <file-id>` | Download a file |

## Example Prompts

- "Read the last 20 messages in #engineering"
- "Summarize this Slack thread: [paste URL]"
- "Search Slack for messages about deployment from john"
- "Who is in the #frontend channel?"
- "Look up user john.smith"
- "What channels am I in?"
- "Find all messages mentioning the API outage from last week"
- "Show me recent DMs"
- "Download the PDF from this Slack thread"

## Usage

```bash
# Setup (first time)
slack-cli config

# List channels
slack-cli channels:list
slack-cli channels:list --type=dm

# Read messages
slack-cli messages:history general --limit=20
slack-cli thread:read "https://workspace.slack.com/archives/..."

# Search
slack-cli search "deployment" --in=engineering --from=john

# Users
slack-cli users:lookup john
slack-cli users:info john.smith

# Download files
slack-cli files:get F0AK7U1V9PE
slack-cli files:get F0AK7U1V9PE --json
```

## Notes

- v1 is read-only - no sending messages
- Uses xoxc/xoxd tokens for full access (same as web app)
- All commands support `--json` for machine-readable output
- Tokens stored in ~/.slack-cli/.env
