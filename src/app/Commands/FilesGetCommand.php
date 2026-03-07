<?php

namespace App\Commands;

/**
 * Download a file by its Slack file ID.
 */
class FilesGetCommand extends BaseSlackCommand
{
    protected $signature = 'files:get
        {file : File ID (e.g. F0AK7U1V9PE)}
        {--json : Output as JSON}';

    protected $description = 'Download a file';

    protected function doExecute(): int
    {
        $fileId = $this->argument('file');

        $result = $this->client->downloadFile($fileId);

        if ($this->wantsJson()) {
            return $this->outputJson($result);
        }

        $this->line("Downloaded: {$result['local_path']}");

        return self::SUCCESS;
    }
}
