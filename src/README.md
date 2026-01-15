# slack-cli - Development

## Built With

This skill was created using [php-cli-builder](../php-cli-builder/SKILL.md).

## Setup

```bash
cd ~/.claude/skills/slack-cli/src
composer install
./slack-cli --help
```

## Building

First-time setup (builds PHP + micro.sfx):
```bash
php-cli-builder-spc-setup --doctor
php-cli-builder-spc-build
```

Build and install to skill root:
```bash
./slack-cli build              # builds + copies to ../slack-cli
./slack-cli build --no-install # only builds to builds/slack-cli
```

## Testing

```bash
./vendor/bin/pest
```

## License

MIT
