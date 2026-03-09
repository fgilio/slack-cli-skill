# slack-cli

Read-only Slack CLI using browser tokens. Access DMs, private channels, and everything visible in your Slack workspace.

Self-contained binary - no PHP required.

## Install

See [skill/SETUP.md](skill/SETUP.md) or run `./skill/install`

## Commands

```bash
slack-cli config                    # Setup tokens
slack-cli channels:list             # List channels
slack-cli channels:info <channel>   # Channel details + members
slack-cli messages:history <channel> # Read messages
slack-cli thread:read <url>         # Read thread from URL
slack-cli search <query>            # Search messages
slack-cli users:lookup <query>      # Find users
slack-cli users:info <user>         # User details
```

## Examples

```bash
# List DMs
slack-cli channels:list --type=dm

# Read recent messages
slack-cli messages:history general --limit=20

# Read thread from URL
slack-cli thread:read "https://workspace.slack.com/archives/C01234/p1234567890"

# Search with filters
slack-cli search "bug" --in=engineering --from=john --after=2024-01-01

# JSON output
slack-cli channels:list --json | jq '.[] | .name'
```

## Development

```bash
cd src
./slack-cli build   # Build standalone binary
```

See [src/README.md](src/README.md) for more details.
