<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Enums\EnrollmentStatus;
use App\Enums\MessageChannel;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns an audience description into recipients with the contact point of the chosen channel.
 * Recipients without a contact are counted as skipped, never silently dropped.
 */
final class AudienceResolver
{
    /**
     * @param  array<string, mixed>  $audience  {type: guardians|students|users, group_id?, level_id?, student_ids?, user_ids?, all?}
     * @return array{recipients: list<array<string, mixed>>, skipped: int}
     */
    public function resolve(array $audience, MessageChannel $channel): array
    {
        return match ($audience['type']) {
            'guardians' => $this->guardians($audience, $channel),
            'students' => $this->students($audience, $channel),
            'users' => $this->users($audience, $channel),
        };
    }

    /** @return Collection<int, int> */
    public function studentIds(array $audience): Collection
    {
        $query = Enrollment::query()->where('status', EnrollmentStatus::Active->value);

        if (! empty($audience['group_id'])) {
            $query->where('group_id', $audience['group_id']);
        } elseif (! empty($audience['level_id'])) {
            $query->whereHas('group', fn (Builder $g) => $g->where('level_id', $audience['level_id']));
        } elseif (! empty($audience['student_ids'])) {
            return Student::query()->whereIn('id', $audience['student_ids'])->pluck('id');
        } elseif (empty($audience['all'])) {
            return collect();
        }

        return $query->pluck('student_id')->unique()->values();
    }

    /**
     * One recipient per (guardian, student) pair, for messages that talk about ONE child (absences, debts).
     * With $financialOnly the guardians flagged as financially responsible are preferred (all of them if none is flagged).
     *
     * @param  list<int>  $studentIds
     * @return array{recipients: list<array<string, mixed>>, skipped: int}
     */
    public function guardiansOf(array $studentIds, MessageChannel $channel, bool $financialOnly = false): array
    {
        if ($studentIds === []) {
            return ['recipients' => [], 'skipped' => 0];
        }

        $groups = $this->groupNames($studentIds);
        $recipients = [];
        $skipped = 0;

        foreach (Student::query()->with('guardians')->whereIn('id', $studentIds)->orderBy('id')->get() as $student) {
            $guardians = $student->guardians;

            if ($financialOnly && $guardians->contains(fn (Guardian $g): bool => (bool) $g->pivot->is_financial_responsible)) {
                $guardians = $guardians->filter(fn (Guardian $g): bool => (bool) $g->pivot->is_financial_responsible);
            }

            if ($guardians->isEmpty()) {
                $skipped++;
            }

            foreach ($guardians as $guardian) {
                $to = $channel === MessageChannel::Email ? $guardian->email : $guardian->phone;

                if ($to === null || $to === '') {
                    $skipped++;

                    continue;
                }

                $name = $guardian->first_name.' '.$guardian->last_name;
                $recipients[] = [
                    'type' => 'guardian', 'id' => $guardian->getKey(), 'to' => $to, 'name' => $name, 'student_id' => $student->getKey(),
                    'vars' => [
                        'recipient_name' => $name, 'guardian_name' => $name,
                        'student_name' => $student->first_name.' '.$student->last_name, 'group_name' => $groups[$student->getKey()] ?? '',
                    ],
                ];
            }
        }

        return ['recipients' => $recipients, 'skipped' => $skipped];
    }

    /** @return array{recipients: list<array<string, mixed>>, skipped: int} */
    private function guardians(array $audience, MessageChannel $channel): array
    {
        $ids = $this->studentIds($audience);
        $groups = $this->groupNames($ids->all());

        $guardians = $ids->isEmpty() ? collect() : Guardian::query()
            ->whereHas('students', fn (Builder $q) => $q->whereIn('students.id', $ids))
            ->with(['students' => fn ($q) => $q->whereIn('students.id', $ids)])
            ->orderBy('id')->get();

        $recipients = [];
        $skipped = 0;
        $accounts = $this->activeAccounts($channel, $guardians->pluck('user_id'));

        foreach ($guardians as $guardian) {
            $contact = $this->contact($channel, 'guardian', $guardian->getKey(), $guardian->user_id, $guardian->email, $guardian->phone, $accounts);

            if ($contact === null) {
                $skipped++;

                continue;
            }

            $student = $guardian->students->first();
            $recipients[] = $contact + [
                'name' => $guardian->first_name.' '.$guardian->last_name,
                'student_id' => $student->getKey(),
                'vars' => [
                    'recipient_name' => $guardian->first_name.' '.$guardian->last_name,
                    'guardian_name' => $guardian->first_name.' '.$guardian->last_name,
                    'student_name' => $student->first_name.' '.$student->last_name,
                    'group_name' => $groups[$student->getKey()] ?? '',
                ],
            ];
        }

        return ['recipients' => $recipients, 'skipped' => $skipped];
    }

    /** @return array{recipients: list<array<string, mixed>>, skipped: int} */
    private function students(array $audience, MessageChannel $channel): array
    {
        $ids = $this->studentIds($audience);
        $groups = $this->groupNames($ids->all());
        $recipients = [];
        $skipped = 0;

        $people = Student::query()->whereIn('id', $ids)->orderBy('id')->get();
        $accounts = $this->activeAccounts($channel, $people->pluck('user_id'));

        foreach ($people as $student) {
            // students have no email address: only sms / whatsapp (or their portal inbox) reach them
            $contact = $this->contact($channel, 'student', $student->getKey(), $student->user_id, null, $student->phone, $accounts);

            if ($contact === null) {
                $skipped++;

                continue;
            }

            $name = $student->first_name.' '.$student->last_name;
            $recipients[] = $contact + [
                'name' => $name, 'student_id' => $student->getKey(),
                'vars' => ['recipient_name' => $name, 'student_name' => $name, 'guardian_name' => '', 'group_name' => $groups[$student->getKey()] ?? ''],
            ];
        }

        return ['recipients' => $recipients, 'skipped' => $skipped];
    }

    /** @return array{recipients: list<array<string, mixed>>, skipped: int} */
    private function users(array $audience, MessageChannel $channel): array
    {
        if (! in_array($channel, [MessageChannel::InApp, MessageChannel::Email], true)) {
            abort(422, 'Staff are reached through in_app or email.');
        }

        $query = User::query()->where('is_active', true);

        if (! empty($audience['user_ids'])) {
            $query->whereIn('id', $audience['user_ids']);
        } elseif (empty($audience['all'])) {
            return ['recipients' => [], 'skipped' => 0];
        } else {
            // "everyone" means the staff: families are addressed through the guardians / students audiences
            $query->whereDoesntHave('roles', fn (Builder $r) => $r->whereIn('name', ['parent', 'student']));
        }

        $recipients = [];
        $skipped = 0;

        foreach ($query->orderBy('id')->get() as $user) {
            if ($channel === MessageChannel::Email && empty($user->email)) {
                $skipped++;

                continue;
            }

            $recipients[] = [
                'type' => 'user', 'id' => $user->getKey(), 'to' => $channel === MessageChannel::Email ? $user->email : null,
                'name' => $user->name, 'student_id' => null,
                'vars' => ['recipient_name' => $user->name, 'guardian_name' => '', 'student_name' => '', 'group_name' => ''],
            ];
        }

        return ['recipients' => $recipients, 'skipped' => $skipped];
    }

    /**
     * The identity and contact point of a person for a channel, or null when they cannot be reached.
     * in_app reaches a guardian or student through their active portal account (the inbox belongs to the user).
     *
     * @param  array<int, true>  $accounts
     * @return array{type: string, id: int, to: string|null}|null
     */
    private function contact(MessageChannel $channel, string $kind, int $id, ?int $userId, ?string $email, ?string $phone, array $accounts): ?array
    {
        if ($channel === MessageChannel::InApp) {
            return $userId !== null && isset($accounts[$userId]) ? ['type' => 'user', 'id' => $userId, 'to' => null] : null;
        }

        $to = $channel === MessageChannel::Email ? $email : $phone;

        return $to === null || $to === '' ? null : ['type' => $kind, 'id' => $id, 'to' => $to];
    }

    /**
     * @param  Collection<int, int|null>  $userIds
     * @return array<int, true> ids of active portal accounts (only looked up for in_app)
     */
    private function activeAccounts(MessageChannel $channel, Collection $userIds): array
    {
        $ids = $userIds->filter()->values()->all();

        if ($channel !== MessageChannel::InApp || $ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->where('is_active', true)->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => true])->all();
    }

    /** @param  list<int>  $studentIds @return array<int, string> student id => group name */
    private function groupNames(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        return Enrollment::query()->with('group:id,name')
            ->whereIn('student_id', $studentIds)->where('status', EnrollmentStatus::Active->value)->get()
            ->mapWithKeys(fn (Enrollment $e): array => [$e->student_id => (string) $e->group?->name])->all();
    }
}
