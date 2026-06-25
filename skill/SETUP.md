# slack-cli - Setup

## Install

```bash
./install              # Symlinks to ~/.local/bin
./install /usr/local/bin  # Custom location
```

Or manually:

```bash
ln -sf $AGENT_HOME/skills/slack-cli/slack-cli ~/.local/bin/slack-cli
```

## Token Setup

Run `slack-cli config` and follow the prompts.

### Extract Tokens from Browser

1. Open Slack in your browser (not desktop app) at `yourworkspace.slack.com`
2. Press F12 to open Developer Tools
3. Go to Console tab
4. Paste and run:

```javascript
(function() {
    const xoxc = JSON.parse(localStorage.getItem('localConfig_v2'))
        ?.teams?.[Object.keys(JSON.parse(localStorage.getItem('localConfig_v2'))?.teams || {})[0]]
        ?.token;
    const xoxd = document.cookie.split('; ').find(c => c.startsWith('d='))?.slice(2);
    console.log('xoxc:', xoxc);
    console.log('xoxd:', xoxd);
})();
```

5. Copy the two values and paste into `slack-cli config`

## Security

- Tokens stored in `~/.slack-cli/.env` (0600 permissions)
- Never share tokens - they provide full Slack access
- Re-run `slack-cli config` if you get auth errors

## Verify

```bash
which slack-cli && slack-cli --version
slack-cli channels:list
```

## Uninstall

```bash
rm ~/.local/bin/slack-cli
rm -rf ~/.slack-cli
```
