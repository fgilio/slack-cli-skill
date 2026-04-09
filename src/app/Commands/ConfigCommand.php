<?php

namespace App\Commands;

use App\Services\SlackClient;
use Fgilio\AgentSkillFoundation\Console\AgentCommand;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\password;

/**
 * Configuration and first-run setup.
 *
 * Shows current config or prompts for token setup.
 * Validates tokens and displays authenticated user on success.
 */
class ConfigCommand extends Command
{
    use AgentCommand;

    protected $signature = 'config';

    protected $description = 'Show configuration or setup tokens';

    public function __construct(private SlackClient $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->client->isConfigured()) {
            return $this->runSetup();
        }

        return $this->showConfig();
    }

    private function showConfig(): int
    {
        $config = $this->client->getConfig();

        if ($this->wantsJson()) {
            return $this->outputJson($config);
        }

        $this->line('');
        $this->line('<fg=cyan>Slack CLI Configuration</>');
        $this->line('');
        $this->line('<fg=gray>Status:</> '.($config['configured'] ? '<fg=green>Configured</>' : '<fg=red>Not configured</>'));
        $this->line("<fg=gray>Config file:</> {$config['config_path']}");
        $this->line('');

        return self::SUCCESS;
    }

    private function runSetup(): int
    {
        $jsCode = <<<'JS'
JSON.parse(localStorage.getItem('localConfig_v2'))?.teams?.[Object.keys(JSON.parse(localStorage.getItem('localConfig_v2'))?.teams || {})[0]]?.token
JS;

        // Copy to clipboard (macOS)
        $process = proc_open('pbcopy', [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (is_resource($process)) {
            fwrite($pipes[0], $jsCode);
            fclose($pipes[0]);
            proc_close($process);
            $copied = true;
        } else {
            $copied = false;
        }

        $this->line('');
        $this->line('<fg=cyan>Slack CLI Setup</>');
        $this->line('');
        $this->line('Open Slack in your <fg=white>browser</> and press <fg=white>F12</> for DevTools.');
        $this->line('');
        $this->line('<fg=yellow>Get xoxc token:</>');
        $this->line('  1. Go to <fg=white>Console</> tab');
        $this->line('  2. Paste code '.($copied ? '<fg=green>(copied to clipboard)</>' : 'and press Enter:'));
        if (! $copied) {
            $this->line('     <fg=gray>'.$jsCode.'</>');
        }
        $this->line('');
        $this->line('<fg=yellow>Get xoxd cookie:</>');
        $this->line('  1. Go to <fg=white>Application</> tab (or Storage in Firefox)');
        $this->line('  2. Expand <fg=white>Cookies</> > your workspace URL');
        $this->line('  3. Find cookie named <fg=white>d</> and copy its <fg=white>Value</>');
        $this->line('');

        $xoxc = password(
            label: 'Enter your xoxc token (starts with xoxc-)',
            required: true,
            validate: fn ($value) => str_starts_with($value, 'xoxc-') ? null : 'Token must start with xoxc-',
        );

        $xoxd = password(
            label: 'Enter your xoxd cookie value',
            required: true,
        );

        $this->client->setTokens($xoxc, $xoxd);

        $auth = $this->client->validateAuth();

        $user = $auth->get('user', 'Unknown');
        $team = $auth->get('team', 'Unknown');

        info("Authenticated as: {$user} @ {$team}");
        info('Configuration saved to ~/.slack-cli/.env');
        $this->line('');

        return self::SUCCESS;
    }
}
