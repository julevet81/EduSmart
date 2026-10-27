<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Messaging\QueueMessages;
use App\Enums\MessageChannel;
use App\Enums\MessageKind;
use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Support\Messaging\AudienceResolver;
use App\Support\Messaging\Placeholders;
use App\Support\Validation\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class MessageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'channel' => ['sometimes', Rule::enum(MessageChannel::class)],
            'kind' => ['sometimes', Rule::enum(MessageKind::class)],
            'status' => ['sometimes', Rule::enum(MessageStatus::class)],
            'student_id' => ['sometimes', 'integer'],
            'batch_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $query = Message::query();

        foreach (['channel', 'kind', 'status', 'student_id', 'batch_id'] as $filter) {
            $query->when($request->query($filter), fn ($q, $v) => $q->where($filter, $v));
        }

        $query->when($request->query('from'), fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->whereDate('created_at', '<=', $v));

        return MessageResource::collection($query->orderByDesc('id')->cursorPaginate((int) $request->query('per_page', 25)));
    }

    public function show(string $id): MessageResource
    {
        return new MessageResource(Message::query()->findOrFail($id));
    }

    /** Sends a text (or a template) to an audience. */
    public function store(Request $request, AudienceResolver $resolver, QueueMessages $queue): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::enum(MessageChannel::class)],
            'kind' => ['sometimes', Rule::in([MessageKind::Custom->value, MessageKind::Announcement->value])],
            'template_key' => ['nullable', 'string', 'max:50'],
            'subject' => ['nullable', 'string', 'max:150'],
            'body' => ['required_without:template_key', 'nullable', 'string', 'max:1000'],
            'audience' => ['required', 'array'],
            'audience.type' => ['required', 'in:guardians,students,users'],
            'audience.group_id' => ['sometimes', 'integer', TenantRules::exists('groups')],
            'audience.level_id' => ['sometimes', 'integer', TenantRules::exists('levels')],
            'audience.student_ids' => ['sometimes', 'array', 'max:2000'],
            'audience.student_ids.*' => ['integer', TenantRules::exists('students')],
            'audience.user_ids' => ['sometimes', 'array', 'max:2000'],
            'audience.user_ids.*' => ['integer', TenantRules::exists('users')],
            'audience.all' => ['sometimes', 'boolean'],
        ]);

        $channel = MessageChannel::from($data['channel']);
        $subject = $data['subject'] ?? null;
        $body = (string) ($data['body'] ?? '');

        if (! empty($data['template_key'])) {
            $template = MessageTemplate::query()->where('key', $data['template_key'])->where('is_active', true)->first();

            if ($template === null) {
                abort(422, 'The template does not exist or is inactive.');
            }

            if ($template->channel !== null && $template->channel !== $channel) {
                abort(422, "This template is meant for the {$template->channel->value} channel.");
            }

            $subject = $template->subject;
            $body = $template->body;
        }

        $unknown = array_merge(Placeholders::unknown($body), Placeholders::unknown((string) $subject));

        if ($unknown !== []) {
            abort(422, 'Unknown placeholder(s): {'.implode('}, {', $unknown).'}.');
        }

        $resolved = $resolver->resolve($data['audience'], $channel);

        if (count($resolved['recipients']) > (int) config('messaging.broadcast_limit')) {
            abort(422, 'The audience exceeds the limit of '.config('messaging.broadcast_limit').' recipients per request.');
        }

        if ($resolved['recipients'] === []) {
            abort(422, $resolved['skipped'] > 0
                ? "None of the {$resolved['skipped']} recipients has a contact point for this channel."
                : 'The audience is empty.');
        }

        $result = $queue->handle(
            $channel, MessageKind::from($data['kind'] ?? MessageKind::Custom->value), $resolved['recipients'], $subject, $body, $request->user(),
        );

        return response()->json($result + ['skipped_no_contact' => $resolved['skipped']], 201);
    }

    public function cancel(string $id): MessageResource
    {
        $message = Message::query()->findOrFail($id);

        if (Message::query()->whereKey($message->getKey())->where('status', MessageStatus::Queued->value)
            ->update(['status' => MessageStatus::Cancelled->value]) === 0) {
            abort(422, 'Only a queued message can be cancelled.');
        }

        return new MessageResource($message->refresh());
    }

    public function retry(string $id): MessageResource
    {
        $message = Message::query()->findOrFail($id);

        if (Message::query()->whereKey($message->getKey())->where('status', MessageStatus::Failed->value)
            ->update(['status' => MessageStatus::Queued->value, 'attempts' => 0, 'last_error' => null]) === 0) {
            abort(422, 'Only a failed message can be retried.');
        }

        return new MessageResource($message->refresh());
    }
}
