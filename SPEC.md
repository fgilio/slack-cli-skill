# slack-cli Specification

## Overview

Read-only Slack CLI using browser tokens (xoxc/xoxd). Provides full access to DMs, private channels, and everything visible in the user's Slack workspace.

## Design Decisions

### Authentication
- Uses browser tokens (xoxc/xoxd) extracted from web Slack
- Single workspace configuration stored at `~/.slack-cli/.env`
- Auth validation cached for 10 minutes to reduce API calls
- On 401: prompt re-auth immediately
- On 429: auto-retry once after Retry-After delay

### Caching
- File-based cache in `~/.slack-cli/cache/`
- User list cached for 1 hour, auto-refreshed on lookup miss
- Auth cache: 10-minute TTL

### Output
- Human-readable by default, `--json` for machine output
- Timestamps: "YYYY-MM-DD HH:MM (Xh ago)"
- Messages: resolve @mentions, #channels, links to readable format
- All emoji normalized to `:shortcode:` format

### Laravel Patterns
- Collections for all API responses
- Http::retry() for rate limit handling
- Str helpers for text parsing
- Service injection via AppServiceProvider

## Commands

| Command | Description |
|---------|-------------|
| `config` | Setup tokens or show config |
| `channels:list` | List accessible channels |
| `channels:info` | Channel details + members |
| `messages:history` | Read channel messages |
| `thread:read` | Read thread from URL |
| `search` | Search messages |
| `users:lookup` | Find users by name |
| `users:info` | User details |
| `files:get` | Download a file by ID |

## API Endpoints

- `auth.test` - Validate tokens
- `conversations.list` - List channels
- `conversations.info` - Channel details
- `conversations.members` - Channel members
- `conversations.history` - Message history
- `conversations.replies` - Thread replies
- `search.messages` - Search
- `users.list` - All users
- `users.info` - User details
- `files.info` - File metadata + download URL

## v2 Scope (Deferred)

- `messages:send` - Send messages
- `messages:reply` - Reply to threads
- File uploads
- Reactions
- Multi-workspace support
