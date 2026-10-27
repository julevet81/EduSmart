<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\MessageChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\InboxResource;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The signed-in user's own in-app notifications. No permission needed: a user only ever sees their own rows. */
final class InboxController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'unread' => ['sometimes', 'boolean']]);

        return InboxResource::collection(
            $this->mine($request)->when($request->boolean('unread'), fn (Builder $q) => $q->whereNull('read_at'))
                ->orderByDesc('id')->cursorPaginate((int) $request->query('per_page', 25))
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->mine($request)->whereNull('read_at')->count()]);
    }

    public function markRead(Request $request, string $id): InboxResource
    {
        $message = $this->mine($request)->findOrFail($id);
        $message->read_at ?? $message->update(['read_at' => now()]);

        return new InboxResource($message);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return response()->json(['marked' => $this->mine($request)->whereNull('read_at')->update(['read_at' => now()])]);
    }

    /** @return Builder<Message> */
    private function mine(Request $request): Builder
    {
        return Message::query()->where('channel', MessageChannel::InApp->value)
            ->where('recipient_type', 'user')->where('recipient_id', $request->user()->getKey());
    }
}
