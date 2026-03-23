<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Slack API client (xoxc/xoxd tokens).
 *
 * Handles authentication, caching, pagination, and all read operations.
 * All public methods return Collections for fluent chaining.
 */
class SlackClient
{
    private const API_BASE = 'https://slack.com/api/';

    private const CONFIG_DIR = '/.slack-cli';

    private const AUTH_CACHE_TTL = 600; // 10 minutes

    private const USER_CACHE_TTL = 3600; // 1 hour

    private ?string $xoxc = null;

    private ?string $xoxd = null;

    /** @var Collection<string, array<string, mixed>>|null */
    private ?Collection $cachedUsers = null;

    public function __construct()
    {
        $this->loadConfig();
    }

    // =========================================================================
    // Config & Auth
    // =========================================================================

    public function isConfigured(): bool
    {
        return ! empty($this->xoxc) && ! empty($this->xoxd);
    }

    public function setTokens(string $xoxc, string $xoxd): void
    {
        $this->xoxc = $xoxc;
        $this->xoxd = $xoxd;
        $this->saveConfig();
        $this->clearAuthCache();
    }

    /** @return Collection<string, mixed> */
    public function validateAuth(): Collection
    {
        $cacheKey = 'slack:auth:validated';

        $cached = $this->getCacheValue($cacheKey);
        if ($cached) {
            return collect($cached);
        }

        $response = $this->request('auth.test');

        if (! $response->get('ok')) {
            throw new RuntimeException('Token expired. Run `slack-cli config` to re-authenticate');
        }

        $this->setCacheValue($cacheKey, $response->toArray(), self::AUTH_CACHE_TTL);

        return $response;
    }

    public function clearAuthCache(): void
    {
        $this->deleteCacheValue('slack:auth:validated');
    }

    // =========================================================================
    // Channels
    // =========================================================================

    /** @return Collection<int, array<string, mixed>> */
    public function listChannels(?string $types = null, int $limit = 50): Collection
    {
        $types = $types ?? 'public_channel,private_channel,mpim,im';

        return $this->paginatedRequest('conversations.list', [
            'types' => $types,
            'exclude_archived' => true,
        ], $limit, 'channels');
    }

    /** @return Collection<string, mixed>|null */
    public function getChannelInfo(string $channel): ?Collection
    {
        $channelId = $this->resolveChannelId($channel);

        $response = $this->request('conversations.info', [
            'channel' => $channelId,
            'include_num_members' => true,
        ]);

        if (! $response->get('ok')) {
            return null;
        }

        return collect($response->get('channel'));
    }

    /** @return Collection<int, Collection<string, mixed>> */
    public function getChannelMembers(string $channel): Collection
    {
        $channelId = $this->resolveChannelId($channel);

        $memberIds = $this->paginatedRequest('conversations.members', [
            'channel' => $channelId,
        ], 1000, 'members');

        // Resolve all member IDs to user info
        return $memberIds->map(fn ($id) => $this->getUserInfo($id) ?? collect(['id' => $id]));
    }

    public function resolveChannelId(string $identifier): string
    {
        // Already an ID
        if (preg_match('/^[CDG][A-Z0-9]+$/', $identifier)) {
            return $identifier;
        }

        // Strip # prefix
        $name = ltrim($identifier, '#');

        // Search through channels
        $channels = $this->listChannels(null, 1000);

        $matches = $channels->filter(fn ($c) => strcasecmp(Arr::get($c, 'name', ''), $name) === 0);

        if ($matches->isEmpty()) {
            throw new RuntimeException('Channel not found. Check the name or ID');
        }

        // Prefer non-archived
        $active = $matches->filter(fn ($c) => ! Arr::get($c, 'is_archived', false));

        if ($active->isNotEmpty()) {
            if ($active->count() < $matches->count()) {
                // There's an archived channel with same name
                fwrite(STDERR, "Multiple channels named '{$name}'. Using active channel. (archived channel also exists)\n");
            }

            return $active->first()['id'];
        }

        return $matches->first()['id'];
    }

    // =========================================================================
    // Messages
    // =========================================================================

    /** @return Collection<int, array<string, mixed>> */
    public function getHistory(string $channel, int $limit = 50, string $sort = 'newest'): Collection
    {
        $channelId = $this->resolveChannelId($channel);

        $messages = $this->paginatedRequest('conversations.history', [
            'channel' => $channelId,
        ], $limit, 'messages');

        if ($sort === 'oldest') {
            $messages = $messages->reverse()->values();
        }

        return $messages;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getThreadReplies(string $channel, string $threadTs): Collection
    {
        $channelId = $this->resolveChannelId($channel);

        // Fetch all replies (no limit for threads as per spec)
        $messages = $this->paginatedRequest('conversations.replies', [
            'channel' => $channelId,
            'ts' => $threadTs,
        ], 1000, 'messages');

        return $messages;
    }

    // =========================================================================
    // Search
    // =========================================================================

    /**
     * @param  array<string, string>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function searchMessages(string $query, array $filters = [], int $limit = 20, string $sort = 'timestamp'): Collection
    {
        $searchQuery = $query;

        // Add filters to query string
        if (isset($filters['in'])) {
            $channelId = $this->resolveChannelId($filters['in']);
            $searchQuery .= " in:<#{$channelId}>";
        }

        if (isset($filters['from'])) {
            $userId = $this->resolveUserId($filters['from']);
            $searchQuery .= " from:<@{$userId}>";
        }

        if (isset($filters['after'])) {
            $searchQuery .= " after:{$filters['after']}";
        }

        if (isset($filters['before'])) {
            $searchQuery .= " before:{$filters['before']}";
        }

        $response = $this->request('search.messages', [
            'query' => $searchQuery,
            'sort' => $sort,
            'sort_dir' => $sort === 'timestamp' ? 'desc' : 'desc',
            'count' => min($limit, 100),
        ]);

        if (! $response->get('ok')) {
            throw new RuntimeException('Search failed: '.$response->get('error', 'unknown error'));
        }

        return collect(Arr::get($response, 'messages.matches', []));
    }

    // =========================================================================
    // Users
    // =========================================================================

    /** @return Collection<string, mixed>|null */
    public function getUserInfo(string $userId): ?Collection
    {
        // Try cache first
        $users = $this->getAllUsers();
        $user = $users->get($userId);

        if ($user) {
            return collect($user);
        }

        // Fetch directly
        $response = $this->request('users.info', ['user' => $userId]);

        if (! $response->get('ok')) {
            return null;
        }

        return collect($response->get('user'));
    }

    /** @return Collection<int, array<string, mixed>> */
    public function searchUsers(string $query): Collection
    {
        $users = $this->getAllUsers();

        $query = strtolower($query);

        return $users->filter(function ($user) use ($query) {
            $name = strtolower(Arr::get($user, 'name', ''));
            $realName = strtolower(Arr::get($user, 'real_name', ''));
            $displayName = strtolower(Arr::get($user, 'profile.display_name', ''));

            return Str::contains($name, $query)
                || Str::contains($realName, $query)
                || Str::contains($displayName, $query);
        })->values();
    }

    public function getUserName(string $userId): string
    {
        $user = $this->getUserInfo($userId);

        if (! $user) {
            return $userId;
        }

        return Arr::get($user, 'profile.display_name')
            ?: Arr::get($user, 'real_name')
            ?: Arr::get($user, 'name')
            ?: $userId;
    }

    /** @return Collection<string, array<string, mixed>> */
    public function getAllUsers(): Collection
    {
        if ($this->cachedUsers !== null) {
            return $this->cachedUsers;
        }

        $cacheKey = 'slack:users:all';
        $cached = $this->getCacheValue($cacheKey);

        if ($cached) {
            $this->cachedUsers = collect($cached)->keyBy('id');

            return $this->cachedUsers;
        }

        $users = $this->paginatedRequest('users.list', [], 1000, 'members');

        $this->cachedUsers = $users->keyBy('id');
        $this->setCacheValue($cacheKey, $users->toArray(), self::USER_CACHE_TTL);

        return $this->cachedUsers;
    }

    /** @return Collection<string, array<string, mixed>> */
    public function refreshUserCache(): Collection
    {
        $this->deleteCacheValue('slack:users:all');
        $this->cachedUsers = null;

        return $this->getAllUsers();
    }

    public function resolveUserId(string $identifier): string
    {
        // Already an ID
        if (preg_match('/^[UW][A-Z0-9]+$/', $identifier)) {
            return $identifier;
        }

        // Search by name
        $users = $this->getAllUsers();
        $identifier = strtolower(ltrim($identifier, '@'));

        $match = $users->first(function ($user) use ($identifier) {
            return strtolower(Arr::get($user, 'name', '')) === $identifier
                || strtolower(Arr::get($user, 'profile.display_name', '')) === $identifier;
        });

        if (! $match) {
            // Refresh and try again
            fwrite(STDERR, "User not found in cache, refreshing...\n");
            $users = $this->refreshUserCache();

            $match = $users->first(function ($user) use ($identifier) {
                return strtolower(Arr::get($user, 'name', '')) === $identifier
                    || strtolower(Arr::get($user, 'profile.display_name', '')) === $identifier;
            });
        }

        if (! $match) {
            throw new RuntimeException("User not found: {$identifier}");
        }

        return $match['id'];
    }

    // =========================================================================
    // Files
    // =========================================================================

    /** @return array{id: string, name: string, mimetype: string, size: int, local_path: string} */
    public function downloadFile(string $fileId): array
    {
        $response = $this->request('files.info', ['file' => $fileId]);

        if (! $response->get('ok')) {
            throw new RuntimeException('File not found: '.$response->get('error', 'unknown'));
        }

        $file = $response->get('file');
        $name = Arr::get($file, 'name', 'file');
        $mimetype = Arr::get($file, 'mimetype', 'application/octet-stream');
        $size = Arr::get($file, 'size', 0);
        $downloadUrl = Arr::get($file, 'url_private_download', Arr::get($file, 'url_private'));

        if (! $downloadUrl) {
            throw new RuntimeException('File has no download URL');
        }

        // Sanitize filename
        $sanitized = preg_replace('/[^\w\-. ]/', '_', $name);
        $sanitized = preg_replace('/_+/', '_', $sanitized);
        $sanitized = trim($sanitized, '_');

        if (empty($sanitized)) {
            $sanitized = 'file';
        }

        // Create downloads dir
        $dir = $this->getConfigDir().'/downloads';
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        // Handle duplicates
        $localPath = $dir.'/'.$sanitized;
        if (file_exists($localPath)) {
            $info = pathinfo($sanitized);
            $base = $info['filename'];
            $ext = isset($info['extension']) ? '.'.$info['extension'] : '';
            $counter = 1;
            while (file_exists($localPath)) {
                $localPath = $dir.'/'.$base.'_'.$counter.$ext;
                $counter++;
            }
        }

        // Download with auth
        $this->ensureConfigured();

        $httpResponse = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->xoxc,
        ])
            ->withCookies(['d' => $this->xoxd], 'slack.com')
            ->withOptions(['sink' => $localPath])
            ->get($downloadUrl);

        if (! $httpResponse->successful()) {
            @unlink($localPath);
            throw new RuntimeException('Download failed: HTTP '.$httpResponse->status());
        }

        return [
            'id' => $fileId,
            'name' => $name,
            'mimetype' => $mimetype,
            'size' => $size,
            'local_path' => $localPath,
        ];
    }

    // =========================================================================
    // URL Parsing
    // =========================================================================

    /** @return Collection<string, string|null> */
    public function parseSlackUrl(string $url): Collection
    {
        // Format 1: workspace.slack.com/archives/C01ABC123/p1234567890123456
        if (preg_match('/archives\/([A-Z0-9]+)\/p(\d+)(?:\?thread_ts=(\d+\.\d+))?/i', $url, $matches)) {
            $ts = substr($matches[2], 0, 10).'.'.substr($matches[2], 10);

            return collect([
                'channel' => $matches[1],
                'ts' => $ts,
                'thread_ts' => $matches[3] ?? null,
            ]);
        }

        // Format 2: app.slack.com/client/T01ABC/C01ABC123/thread/C01ABC123-1234567890.123456
        if (preg_match('/client\/[A-Z0-9]+\/([A-Z0-9]+)(?:\/thread\/[A-Z0-9]+-(\d+\.\d+))?/i', $url, $matches)) {
            return collect([
                'channel' => $matches[1],
                'ts' => $matches[2] ?? null,
                'thread_ts' => $matches[2] ?? null,
            ]);
        }

        // Format 3: Simple channel link
        if (preg_match('/\/([CDG][A-Z0-9]+)/i', $url, $matches)) {
            return collect([
                'channel' => $matches[1],
                'ts' => null,
                'thread_ts' => null,
            ]);
        }

        throw new RuntimeException('Unable to parse Slack URL');
    }

    // =========================================================================
    // Text Formatting
    // =========================================================================

    public function formatMessageText(string $text): string
    {
        return Str::of($text)
            // User mentions: <@U123> -> @display_name
            ->replaceMatches('/<@([UW][A-Z0-9]+)>/i', fn ($m) => '@'.$this->getUserName($m[1]))
            // Channel links: <#C123|name> -> #name
            ->replaceMatches('/<#[A-Z0-9]+\|([^>]+)>/i', '#$1')
            // Channel links without name: <#C123> -> #channel
            ->replaceMatches('/<#([A-Z0-9]+)>/i', fn ($m) => '#'.$this->getChannelName($m[1]))
            // Special mentions
            ->replace('<!here>', '@here')
            ->replace('<!channel>', '@channel')
            ->replace('<!everyone>', '@everyone')
            // Subteam mentions: <!subteam^ID|name> -> @name
            ->replaceMatches('/<!subteam\^[A-Z0-9]+\|([^>]+)>/i', '@$1')
            // Links with text: <url|text> -> [text](url)
            ->replaceMatches('/<(https?:\/\/[^|>]+)\|([^>]+)>/', '[$2]($1)')
            // Plain links: <url> -> url
            ->replaceMatches('/<(https?:\/\/[^>]+)>/', '$1')
            ->toString();
    }

    public function getChannelName(string $channelId): string
    {
        $info = $this->getChannelInfo($channelId);

        return $info?->get('name') ?? $channelId;
    }

    // =========================================================================
    // Timestamp Formatting
    // =========================================================================

    public function formatTimestamp(string $ts, bool $withRelative = true): string
    {
        $timestamp = (int) explode('.', $ts)[0];
        $date = date('Y-m-d H:i', $timestamp);

        if (! $withRelative) {
            return $date;
        }

        $relative = $this->relativeTime($timestamp);

        return "{$date} ({$relative})";
    }

    public function relativeTime(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'just now';
        }

        if ($diff < 3600) {
            $mins = (int) ($diff / 60);

            return "{$mins}m ago";
        }

        if ($diff < 86400) {
            $hours = (int) ($diff / 3600);

            return "{$hours}h ago";
        }

        $days = (int) ($diff / 86400);

        return "{$days}d ago";
    }

    // =========================================================================
    // Configuration
    // =========================================================================

    /** @return array{configured: bool, config_path: string} */
    public function getConfig(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'config_path' => $this->getConfigPath(),
        ];
    }

    private function getConfigDir(): string
    {
        return ($_SERVER['HOME'] ?? getenv('HOME')).self::CONFIG_DIR;
    }

    private function getConfigPath(): string
    {
        return $this->getConfigDir().'/.env';
    }

    private function getCachePath(): string
    {
        return $this->getConfigDir().'/cache';
    }

    private function loadConfig(): void
    {
        $path = $this->getConfigPath();

        if (! file_exists($path)) {
            return;
        }

        $content = file_get_contents($path);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");

                match ($key) {
                    'SLACK_XOXC' => $this->xoxc = $value,
                    'SLACK_XOXD' => $this->xoxd = $value,
                    default => null,
                };
            }
        }
    }

    private function saveConfig(): void
    {
        $dir = $this->getConfigDir();

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $content = "# Slack CLI configuration\n";
        $content .= "# Tokens extracted from browser - do not share!\n\n";

        if ($this->xoxc) {
            $content .= "SLACK_XOXC={$this->xoxc}\n";
        }

        if ($this->xoxd) {
            $content .= "SLACK_XOXD={$this->xoxd}\n";
        }

        file_put_contents($this->getConfigPath(), $content);
        chmod($this->getConfigPath(), 0600);
    }

    // =========================================================================
    // Cache Helpers (file-based)
    // =========================================================================

    private function getCacheValue(string $key): mixed
    {
        $path = $this->getCachePath().'/'.md5($key).'.json';

        if (! file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);

        if (! $data || ($data['expires_at'] ?? 0) < time()) {
            @unlink($path);

            return null;
        }

        return $data['value'];
    }

    private function setCacheValue(string $key, mixed $value, int $ttl): void
    {
        $dir = $this->getCachePath();

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $path = $dir.'/'.md5($key).'.json';

        file_put_contents($path, json_encode([
            'key' => $key,
            'value' => $value,
            'expires_at' => time() + $ttl,
        ]));
    }

    private function deleteCacheValue(string $key): void
    {
        $path = $this->getCachePath().'/'.md5($key).'.json';
        @unlink($path);
    }

    // =========================================================================
    // HTTP
    // =========================================================================

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<string, mixed>
     */
    private function request(string $method, array $params = []): Collection
    {
        $this->ensureConfigured();

        $response = Http::asForm()
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->xoxc,
            ])
            ->withCookies(['d' => $this->xoxd], 'slack.com')
            ->retry(3, function (int $attempt, \Throwable $exception) {
                $response = $exception instanceof RequestException
                    ? $exception->response
                    : null;

                if ($response?->status() === 429) {
                    $retryAfter = (int) ($response->header('Retry-After') ?: 1);
                    fwrite(STDERR, "Rate limited. Waiting {$retryAfter}s...\n");

                    return $retryAfter * 1000;
                }

                return 1000;
            }, throw: false)
            ->post(self::API_BASE.$method, $params);

        if ($response->status() === 401 || $response->json('error') === 'invalid_auth') {
            throw new RuntimeException('Token expired. Run `slack-cli config` to re-authenticate');
        }

        return collect($response->json());
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, mixed>
     */
    private function paginatedRequest(string $method, array $params, int $limit, string $dataKey): Collection
    {
        $results = collect();
        $cursor = null;

        do {
            $requestParams = array_merge($params, [
                'limit' => min($limit - $results->count(), 200),
            ]);

            if ($cursor) {
                $requestParams['cursor'] = $cursor;
            }

            $response = $this->request($method, $requestParams);

            if (! $response->get('ok')) {
                $error = $response->get('error', 'unknown');
                throw new RuntimeException("Slack API error: {$error}");
            }

            $data = $response->get($dataKey, []);
            $results = $results->concat($data);

            $cursor = Arr::get($response, 'response_metadata.next_cursor');

        } while ($cursor && $results->count() < $limit);

        return $results->take($limit);
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Run `slack-cli config` first');
        }
    }
}
