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

| Command                                | Purpose                       |
| -------------------------------------- | ----------------------------- |
| `slack-cli config`                     | Setup/show tokens             |
| `slack-cli channels:list`              | List channels                 |
| `slack-cli channels:info <channel>`    | Channel details + members     |
| `slack-cli messages:history <channel>` | Read messages                 |
| `slack-cli archive <target>`           | Dump a whole conversation     |
| `slack-cli archive:batch <manifest>`   | Refresh many archives at once |
| `slack-cli archive:render <dir>`       | Rebuild raw.md from disk      |
| `slack-cli thread:read <url>`          | Read thread from URL          |
| `slack-cli search <query>`             | Search messages               |
| `slack-cli users:lookup <query>`       | Find users                    |
| `slack-cli users:info <user>`          | User details                  |
| `slack-cli files:get <file-id>`        | Download a file               |

## Example Prompts

- "Read the last 20 messages in #engineering"
- "Archive the last year of #eng-leadership to ./archive"
- "Refresh every Slack archive in my team repo"
- "Re-render the raw.md of my Slack archives"
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

# Rebuild raw.md from messages.jsonl, fetching no messages (see Re-rendering below)
slack-cli archive:render ./eng-leadership

# Refresh every archive listed in a manifest (see Batches below)
slack-cli archive:batch ~/pla/team/slack-archives.json --since-last

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

**11:02 Ana Pérez:** [mensaje compartido de Bruno Díaz, 2026-08-03 09:40]
  > El deploy de hoy quedó para la tarde.
  > Aviso cuando esté.
```

A message someone shared or forwarded renders under the message that shared it: a `[mensaje compartido de <autor>, <YYYY-MM-DD HH:MM>]` line, then the shared text, title and files quoted with `> `. Sharing without a comment leaves the message text empty, so the label sits on the message line itself. Previews of ordinary web links are left out. Grep `mensaje compartido` to find every share.

### Resuming and refreshing

Every page that reaches disk updates `.archive-checkpoint.json` in the output directory. If a run is interrupted, rerun the same command with `--resume` and it picks up at the page it was about to fetch. Without `--resume` it refuses to touch a directory that holds a checkpoint, or one that already holds a finished archive.

`--since-last` is the refresh path: it reads the newest message already in `messages.jsonl` and fetches only what came after, appending to both files and moving the header's closing date forward. History is never rewritten, which means replies posted to an old thread after that thread was archived do not come back. Re-archive into a clean directory when you need them.

Every finished run also drops an `.archive-meta.json` naming the channel the directory holds and whether each run nested thread replies, which is what lets `archive:batch --init` rebuild a manifest later.

### Re-rendering

`archive:render` rebuilds `raw.md` from the `messages.jsonl` beside it, so an archive picks up changes to how `raw.md` is written without archiving the conversation again.

```bash
slack-cli archive:render <dir>
```

It writes what `archive` would write for the same messages: the same header, day grouping and thread nesting, and an archive extended with `--since-last` comes out as one continuous copy. It fetches no messages and never touches `messages.jsonl`.

- The channel label, date range, workspace line and timezone come from the four header lines of the current `raw.md`. Those are what the run wrote, and the API's answer may have changed since (a renamed channel, a new member count, a different timezone on your profile). A `raw.md` without that header is refused.
- People's names go through the same lookup `archive` uses, served from the cached user directory, so the command still needs `slack-cli config`.
- Replies nest under their parent unless `.archive-meta.json` says the run that archived that parent used `--no-threads`. Each `--since-last` run that switches the setting is recorded from the message it extended from, so a mixed archive renders each part the way it was written.
- A directory holding `.archive-checkpoint.json` is refused: finish that run with `archive --resume` first.
- The new copy is written to `raw.md.part` and moved into place only when complete.

## Batches

Keeping a whole tree of archives current one command at a time is a chore. `archive:batch` reads a manifest and walks it.

```bash
slack-cli archive:batch ~/pla/team/slack-archives.json --since-last
```

The manifest is a JSON array. Each entry needs a `target` (whatever the single `archive` command accepts) and an `out` (an absolute path, or one starting with `~`), and may carry `after`, `before`, and `no_threads`.

```json
[
  { "target": "@gparra", "out": "~/pla/team/People/Gonza/Slack-DM" },
  { "target": "engineering-team", "out": "~/pla/team/Equipo/Slack/engineering-team", "after": "2025-08-25" },
  { "target": "C0123ABCD", "out": "~/notes/slack/product-sync", "no_threads": true }
]
```

| Option | What it does |
| --- | --- |
| `--since-last` | Apply the refresh path to every entry. Each directory has its own history, so each one picks up where it left off. |
| `--only=<glob>` | Archive only the entries whose target or output directory name matches. A bare word matches anywhere, so `--only=gparra` finds `@gparra`. |
| `--init` | Print a manifest built from the archives already sitting in the given directories, rather than archiving anything. |
| `--json` | Emit the summary as JSON instead of a table. |
| `-v` | Show each entry's page-by-page progress under its line. |

Entries run one at a time, in manifest order, because Slack rate limits per workspace. The whole manifest is validated before the first call goes out, so a typo in the last entry surfaces in a second rather than forty minutes in. An entry that fails is recorded and the batch moves on to the next one.

```
[1/3] @gparra → Slack-DM ... 128 messages, 34 replies appended
[2/3] engineering-team → engineering-team ... up to date
[3/3] design-team → design-team ... FAILED: Channel not found. Check the name or ID

+------------------+------------------+----------+---------+---------+------------+
| Target           | Out              | Messages | Replies | Threads | Status     |
+------------------+------------------+----------+---------+---------+------------+
| @gparra          | Slack-DM         | 128      | 34      | 12      | archived   |
| engineering-team | engineering-team | 0        | 0       | 0       | up to date |
| design-team      | design-team      | 0        | 0       | 0       | failed     |
+------------------+------------------+----------+---------+---------+------------+
```

The exit code is 0 when every entry worked and 1 when any of them did not.

`--since-last` beats an entry's own `after`: the run starts from whichever of the two sits later, the same way the single command resolves it. A directory an interrupted batch left mid-run is picked up and continued, so a batch that dies partway needs no flag to finish.

### Building a manifest from what you already have

```bash
slack-cli archive:batch --init ~/pla/team ~/notes > ~/pla/team/slack-archives.json
```

`--init` walks the directories you give it, finds every archive (any directory holding a `messages.jsonl`), and prints a manifest to stdout. Targets come from the `.archive-meta.json` a run leaves behind, and fall back to the channel name in the title line of `raw.md`. Every `out` comes out as an absolute path, even when you pass the directories relative to where you run it, so the manifest works from any directory.

A DM's title line holds a person's real name rather than the `@handle` Slack takes back, so those entries come out with `"target": "FIXME"` and a note on stderr saying which directory needs a hand. Fix them up and the manifest is ready.

## Notes

- v1 is read-only - no sending messages
- Uses xoxc/xoxd tokens for full access (same as web app)
- All commands support `--json` for machine-readable output
- Tokens stored in ~/.slack-cli/.env
