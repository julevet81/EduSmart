<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Messaging\DispatchMessages;
use Illuminate\Console\Command;

final class DispatchMessagesCommand extends Command
{
    protected $signature = 'messages:dispatch {--limit=200 : Messages handled per run}';

    protected $description = 'Send the queued SMS / WhatsApp / email messages of every tenant. Schedule it every minute.';

    public function handle(DispatchMessages $dispatch): int
    {
        $r = $dispatch->handle((int) $this->option('limit'));
        $this->info("sent {$r['sent']}, to retry {$r['retried']}, failed {$r['failed']}");

        return self::SUCCESS;
    }
}
