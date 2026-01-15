<?php

namespace App\Commands;

use App\Services\SlackClient;
use Fgilio\AgentSkillFoundation\Output\OutputsJson;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

/**
 * Base command for all Slack CLI commands.
 *
 * Handles:
 * - JSON output flag
 * - Error formatting (JSON or human-readable)
 * - Auth preflight check with caching
 * - SlackClient injection via constructor
 */
abstract class BaseSlackCommand extends Command
{
    protected bool $requiresAuth = true;

    public function __construct(protected SlackClient $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            if ($this->requiresAuth) {
                $this->ensureAuthenticated();
            }

            return $this->doExecute();
        } catch (RuntimeException $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Execute the command logic.
     */
    abstract protected function doExecute(): int;

    /**
     * Check if JSON output is requested.
     */
    protected function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * Output JSON data.
     */
    protected function outputJson(mixed $data): int
    {
        if ($data instanceof Collection) {
            $data = $data->toArray();
        }

        return OutputsJson::jsonOkPretty($this, $data);
    }

    /**
     * Output error and return failure code.
     */
    protected function handleError(RuntimeException $e): int
    {
        if ($this->wantsJson()) {
            fwrite(STDERR, json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT)."\n");
        } else {
            $this->error($e->getMessage());
        }

        return self::FAILURE;
    }

    /**
     * Ensure user is authenticated before running command.
     */
    protected function ensureAuthenticated(): void
    {
        if (! $this->client->isConfigured()) {
            throw new RuntimeException('Run `slack-cli config` first');
        }

        // Auth check with cache
        $this->client->validateAuth();
    }

    /**
     * Format a message for output.
     *
     * @param  array<string, mixed>  $message
     */
    protected function formatMessage(array $message): string
    {
        $userId = Arr::get($message, 'user', Arr::get($message, 'bot_id', 'unknown'));
        $userName = $this->client->getUserName($userId);
        $ts = Arr::get($message, 'ts', '');
        $text = Arr::get($message, 'text', '');
        $edited = Arr::get($message, 'edited');

        // Check if deleted
        if (Arr::get($message, 'subtype') === 'tombstone') {
            return sprintf(
                "[deleted message] | %s\n",
                $this->client->formatTimestamp($ts)
            );
        }

        // Format text
        $formattedText = $this->client->formatMessageText($text);

        // Build output
        $output = sprintf(
            "@%s | %s%s\n%s",
            $userName,
            $this->client->formatTimestamp($ts),
            $edited ? ' (edited '.$this->client->relativeTime((int) $edited['ts']).')' : '',
            $formattedText
        );

        // Add attachments
        $files = Arr::get($message, 'files', []);
        foreach ($files as $file) {
            $name = Arr::get($file, 'name', 'file');
            $url = Arr::get($file, 'url_private', Arr::get($file, 'permalink', ''));
            $output .= "\n[File: {$name}]({$url})";
        }

        // Add reactions
        $reactions = Arr::get($message, 'reactions', []);
        if (! empty($reactions)) {
            $reactionStrs = [];
            foreach ($reactions as $reaction) {
                $name = Arr::get($reaction, 'name', '');
                $count = Arr::get($reaction, 'count', 0);
                $reactionStrs[] = ":{$name}: {$count}";
            }
            $output .= "\n  ".implode(', ', $reactionStrs);
        }

        return $output;
    }

    /**
     * Format channel for list output.
     *
     * @param  array<string, mixed>  $channel
     */
    protected function formatChannelLine(array $channel): string
    {
        $name = Arr::get($channel, 'name', '');
        $isPrivate = Arr::get($channel, 'is_private', false);
        $isIm = Arr::get($channel, 'is_im', false);
        $isMpim = Arr::get($channel, 'is_mpim', false);
        $numMembers = Arr::get($channel, 'num_members', 0);
        $purpose = Arr::get($channel, 'purpose.value', '');

        if ($isIm) {
            $userId = Arr::get($channel, 'user', '');
            $userName = $this->client->getUserName($userId);

            return "DM: {$userName} [dm]";
        }

        if ($isMpim) {
            // Multi-person IM - resolve user names
            $name = Arr::get($channel, 'name', '');
            // Names are like "mpdm-user1--user2--user3"
            if (str_starts_with($name, 'mpdm-')) {
                $userPart = substr($name, 5);
                $userNames = str_replace('--', ', ', $userPart);

                return "DM: {$userNames} [mpim]";
            }

            return "DM: {$name} [mpim]";
        }

        $type = $isPrivate ? 'private' : 'public';
        $purposeStr = $purpose ? " - {$purpose}" : ' - (no purpose set)';

        return "#{$name} [{$type}] ({$numMembers} members){$purposeStr}";
    }

    /**
     * Format user for list output.
     *
     * @param  array<string, mixed>  $user
     */
    protected function formatUserLine(array $user): string
    {
        $name = Arr::get($user, 'name', '');
        $displayName = Arr::get($user, 'profile.display_name', '');
        $realName = Arr::get($user, 'real_name', Arr::get($user, 'profile.real_name', ''));

        $display = $displayName ?: $realName ?: $name;

        if ($display !== $name) {
            return "{$name} ({$display})";
        }

        return $name;
    }
}
