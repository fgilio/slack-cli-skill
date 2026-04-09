<?php

namespace App\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Show detailed channel information including members.
 */
class ChannelsInfoCommand extends BaseSlackCommand
{
    protected $signature = 'channels:info
        {channel : Channel name, #name, or ID}';

    protected $description = 'Show channel details';

    protected function doExecute(): int
    {
        $channel = $this->argument('channel');

        $info = $this->client->getChannelInfo($channel);

        if (! $info) {
            throw new RuntimeException('Channel not found. Check the name or ID');
        }

        if ($this->wantsJson()) {
            // Include members in JSON output
            $members = $this->client->getChannelMembers($channel);
            $info->put('members_list', $members->toArray());

            return $this->outputJson($info);
        }

        $this->outputChannelInfo($info);

        return self::SUCCESS;
    }

    /** @param Collection<string, mixed> $info */
    private function outputChannelInfo($info): void
    {
        $name = Arr::get($info, 'name', '');
        $isPrivate = Arr::get($info, 'is_private', false);
        $isIm = Arr::get($info, 'is_im', false);
        $isMpim = Arr::get($info, 'is_mpim', false);
        $purpose = Arr::get($info, 'purpose.value', '');
        $topic = Arr::get($info, 'topic.value', '');
        $numMembers = Arr::get($info, 'num_members', 0);
        $created = Arr::get($info, 'created', 0);
        $creator = Arr::get($info, 'creator', '');
        $isArchived = Arr::get($info, 'is_archived', false);

        $type = $isIm ? 'dm' : ($isMpim ? 'mpim' : ($isPrivate ? 'private' : 'public'));

        $this->line('');
        $this->line("<fg=cyan>Channel:</> #{$name}");
        $this->line("<fg=gray>Type:</> {$type}");

        if ($purpose) {
            $this->line("<fg=gray>Purpose:</> {$purpose}");
        }

        if ($topic) {
            $this->line("<fg=gray>Topic:</> {$topic}");
        }

        if (! $isIm) {
            $this->line("<fg=gray>Members:</> {$numMembers}");
        }

        if ($created) {
            $creatorName = $creator ? $this->client->getUserName($creator) : 'unknown';
            $this->line('<fg=gray>Created:</> '.date('Y-m-d', $created)." by @{$creatorName}");
        }

        $this->line('<fg=gray>Archived:</> '.($isArchived ? 'yes' : 'no'));

        // Show members
        if (! $isIm) {
            $this->line('');

            $members = $this->client->getChannelMembers(Arr::get($info, 'id'));

            if ($members->count() > 100) {
                $this->line("<fg=yellow>Large channel: {$members->count()} members</>");
            }

            $this->line("<fg=cyan>Members ({$members->count()}):</>");

            foreach ($members as $member) {
                $this->line('  '.$this->formatUserLine($member->toArray()));
            }
        }

        $this->line('');
    }
}
