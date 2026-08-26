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

The suite runs on plain PHPUnit test cases: the binary drops `illuminate/testing` to stay small, so nothing here boots the console application.

## License

MIT
