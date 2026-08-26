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

`slack-cli` is a self-contained binary available on PATH. Run it directly - never prefix with `bun`, `node`, `php`, or any runtime.

## Quick Reference

| Command                                | Purpose                   |
| -------------------------------------- | ------------------------- |
| `slack-cli config`                     | Setup/show tokens         |
| `slack-cli channels:list`              | List channels             |
| `slack-cli channels:info <channel>`    | Channel details + members |
| `slack-cli messages:history <channel>` | Read messages             |
| `slack-cli archive <target>`           | Dump a whole conversation |
| `slack-cli thread:read <url>`          | Read thread from URL      |
| `slack-cli search <query>`             | Search messages           |
| `slack-cli users:lookup <query>`       | Find users                |
| `slack-cli users:info <user>`          | User details              |
| `slack-cli files:get <file-id>`        | Download a file           |

## Example Prompts

- "Read the last 20 messages in #engineering"
- "Archive the last year of #eng-leadership to ./archive"
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

# Archive a whole conversation to disk (see Archiving below)
slack-cli archive '#eng-leadership' --after=2026-01-01 --before=2026-08-26 --out=./eng-leadership
slack-cli archive @nacho --out=./dm-nacho
slack-cli archive '#eng-leadership' --since-last --out=./eng-leadership

# Search (options: --in, --from, --after, --before, --sort=recent, --limit=20)
slack-cli search "deployment" --in=engineering --from=john --limit=10

# Users
slack-cli users:lookup john
slack-cli users:info john.smith

# Download files
slack-cli files:get F0AK7U1V9PE
slack-cli files:get F0AK7U1V9PE --json
```

## Archiving

`messages:history` answers a question and prints the answer, so it holds the whole result in memory and dies past a few thousand messages. `archive` writes straight to disk instead: it pages the conversation, flushes every page as it arrives, and never holds more than one page and one thread at a time. Channel size stops mattering.

```bash
slack-cli archive <target> --out=<dir> [options]
```

`<target>` is a channel ID (`C…`/`G…`/`D…`), a `#channel-name`, or a `@username`, which resolves to the DM you share with that person.

| Option | What it does |
| --- | --- |
| `--out=<dir>` | Required. Where the two output files go. |
| `--after=YYYY-MM-DD` | First day to archive, inclusive, in your own Slack timezone. |
| `--before=YYYY-MM-DD` | Last day to archive, inclusive, so the whole day is covered. |
| `--resume` | Continue the interrupted run recorded in the output directory. |
| `--since-last` | Append only what is newer than the newest message already archived. |
| `--no-threads` | Skip thread replies. |

Two files land in `--out`:

- `messages.jsonl` — the raw Slack message objects, one per line, oldest first, with each thread's replies right after the message they hang off.
- `raw.md` — the reading copy, grouped by day, with replies nested as blockquotes under their parent.

```
# #eng-leadership — Dump completo (2026-08-03 a 2026-08-05)

Workspace: publica.la. Canal privado, 3 miembros.
Horarios en Europe/London .

## 2026-08-03

**10:18 Franco Gilio:** Solucionado el problema al deployar
  > **10:43 Ignacio Milano:** bieeen, estaba siendo molesto ya
```

### Resuming and refreshing

Every page that reaches disk updates `.archive-checkpoint.json` in the output directory. If a run is interrupted, rerun the same command with `--resume` and it picks up at the page it was about to fetch. Without `--resume` it refuses to touch a directory that holds a checkpoint, or one that already holds a finished archive.

`--since-last` is the refresh path: it reads the newest message already in `messages.jsonl` and fetches only what came after, appending to both files and moving the header's closing date forward. History is never rewritten, which means replies posted to an old thread after that thread was archived do not come back. Re-archive into a clean directory when you need them.

## Notes

- v1 is read-only - no sending messages
- Uses xoxc/xoxd tokens for full access (same as web app)
- All commands support `--json` for machine-readable output
- Tokens stored in ~/.slack-cli/.env
