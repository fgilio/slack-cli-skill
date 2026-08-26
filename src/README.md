# slack-cli - Development

## Setup

```bash
cd $AGENT_HOME/skills/slack-cli/src
composer install
./slack-cli --help
```

## Building

First-time setup (builds PHP + micro.sfx):

```bash
php-cli-skill-runtime-setup --doctor
php-cli-skill-runtime-build
```

Build and install to skill root:

```bash
./slack-cli build              # builds + copies to ../skill/slack-cli
./slack-cli build --no-install # only builds to builds/slack-cli
```

## Testing

```bash
composer test
```

The suite runs on plain PHPUnit test cases: the binary drops `illuminate/testing` to stay small, so nothing here boots the console application. A command that needs an end-to-end run goes through Symfony's `CommandTester` on a bare container (`Tests\Support\ConsoleContainer`), with `Tests\Support\FakeSlackClient` standing in for the API. Nothing in the suite reaches Slack.

The binary also drops `doctrine/inflector`, so `Str::plural` and friends are not available at runtime even though `composer install` pulls them in for the tests.

## Archiving

`app/Archive` holds the pieces both archive commands share:

| Class | Role |
| --- | --- |
| `ChannelArchiver` | Streams one conversation to `messages.jsonl` and `raw.md`, checkpointing every page. |
| `Jsonl` | Line-at-a-time reads, writes, joins, and timestamp ordering for the archive files. |
| `ArchiveRequest` / `ArchiveSummary` | What a run was asked to do, and what it produced. |
| `ArchiveCheckpoint` | The progress marker that makes `--resume` lossless. Deleted when a run finishes. |
| `ArchiveMetadata` | The `.archive-meta.json` naming the channel a directory holds. Outlives the run. |
| `DayBoundary` | Turns a `YYYY-MM-DD` day into the Slack timestamp that bounds it. |
| `BatchManifest` / `BatchEntry` | The batch manifest, validated up front. |
| `BatchRunner` / `BatchResult` | Walks the entries, records what each one did, never aborts on one failure. |
| `SlackEntryArchiver` | Turns an entry into an `ArchiveRequest` and hands it to a `ChannelArchiver`. |
| `ArchiveScan` / `ScannedArchive` | Finds existing archives on disk for `archive:batch --init`. |

`ArchiveCommand` and `ArchiveBatchCommand` are both thin: they parse options and print. Everything else lives in `app/Archive` so both paths behave the same.

### Page order is never assumed

`conversations.history` walks a channel backwards from its newest message, so page 0 normally holds the newest slice. Give it an `oldest` floor and no ceiling (what `--after` does) and it pins to the floor and walks **forwards** instead, handing back the oldest slice first while still ordering each page newest message first.

The archiver therefore treats arrival order as meaningless. Each page is sorted by timestamp before it is written, and the ordering pass sorts the page files by the timestamp each one opens with. Pages never overlap, so ordering the files orders the channel without ever holding it in memory. `render` then refuses to write a stream whose timestamps step backwards, which turns any future ordering fault into a non-zero exit rather than a quietly scrambled archive.

## License

MIT
