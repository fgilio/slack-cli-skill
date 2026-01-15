<?php

namespace App\Commands;

use App\Services\SlackClient;
use Fgilio\AgentSkillFoundation\Output\OutputsJson;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\error;
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
    protected $signature = 'config {--json : Output as JSON}';

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

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function showConfig(): int
    {
        $config = $this->client->getConfig();

        if ($this->wantsJson()) {
            return OutputsJson::jsonOkPretty($this, $config);
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
(function() {
  const xoxc = JSON.parse(localStorage.getItem('localConfig_v2'))?.teams?.[Object.keys(JSON.parse(localStorage.getItem('localConfig_v2'))?.teams || {})[0]]?.token;
  const xoxd = document.cookie.split('; ').find(c => c.startsWith('d='))?.slice(2);
  console.log('xoxc:', xoxc);
  console.log('xoxd:', xoxd);
})();
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
        $this->line('<fg=yellow>Steps:</>');
        $this->line('1. Open Slack in your browser (not the desktop app)');
        $this->line('2. Press F12 to open Developer Tools');
        $this->line('3. Go to the Console tab');
        $this->line('4. Paste the code '.($copied ? '<fg=green>(already copied to clipboard)</>' : 'below'));
        $this->line('5. Copy the xoxc and xoxd values shown');
        $this->line('');

        if (! $copied) {
            $this->line('<fg=gray>'.$jsCode.'</>');
            $this->line('');
        }

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

        try {
            $auth = $this->client->validateAuth();

            $user = $auth->get('user', 'Unknown');
            $team = $auth->get('team', 'Unknown');

            info("Authenticated as: {$user} @ {$team}");
            info('Configuration saved to ~/.slack-cli/.env');
            $this->line('');

            return self::SUCCESS;

        } catch (RuntimeException $e) {
            error($e->getMessage());

            if ($this->wantsJson()) {
                fwrite(STDERR, json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT)."\n");
            }

            return self::FAILURE;
        }
    }
}
