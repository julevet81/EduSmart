<?php

declare(strict_types=1);

namespace App\Actions\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Models\Message;
use App\Support\Messaging\ChannelManager;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends the queued messages of EVERY tenant (system job: run it from the scheduler, e.g. every minute).
 * Each message is claimed with a conditional UPDATE, so two workers never send the same one.
 */
final class DispatchMessages
{
    public function __construct(private readonly ChannelManager $channels) {}

    /** @return array{sent: int, retried: int, failed: int} */
    public function handle(int $limit = 200): array
    {
        // A worker that died mid-send leaves "sending" rows behind: put them back in the queue.
        Message::query()->withoutGlobalScopes()->where('status', MessageStatus::Sending->value)
            ->where('updated_at', '<', now()->subMinutes(15))->update(['status' => MessageStatus::Queued->value]);

        $ids = Message::query()->withoutGlobalScopes()
            ->where('status', MessageStatus::Queued->value)->where('channel', '!=', MessageChannel::InApp->value)
            ->orderBy('id')->limit($limit)->pluck('id');

        $result = ['sent' => 0, 'retried' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            $claimed = Message::query()->withoutGlobalScopes()->where('id', $id)->where('status', MessageStatus::Queued->value)
                ->update(['status' => MessageStatus::Sending->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $message = Message::query()->withoutGlobalScopes()->findOrFail($id);

            try {
                $this->channels->driver($message->channel)->send($message);

                Message::query()->withoutGlobalScopes()->where('id', $id)
                    ->update(['status' => MessageStatus::Sent->value, 'sent_at' => now(), 'last_error' => null, 'updated_at' => now()]);
                $result['sent']++;
            } catch (Throwable $e) {
                $final = $message->attempts >= (int) config('messaging.max_attempts', 3);

                Message::query()->withoutGlobalScopes()->where('id', $id)->update([
                    'status' => ($final ? MessageStatus::Failed : MessageStatus::Queued)->value,
                    'last_error' => mb_substr($e->getMessage(), 0, 250),
                    'updated_at' => now(),
                ]);
                $result[$final ? 'failed' : 'retried']++;
            }
        }

        return $result;
    }
}
