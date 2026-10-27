<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Models\MessageTemplate;

/**
 * Texts used by the automatic alerts when the institution has not defined its own template (key absence_alert / payment_reminder).
 * They are written in Arabic because they are read by parents; the API itself stays single-language.
 */
final class DefaultTemplates
{
    public const ABSENCE_ALERT = 'absence_alert';

    public const PAYMENT_REMINDER = 'payment_reminder';

    private const BODIES = [
        self::ABSENCE_ALERT => 'السيد(ة) {guardian_name}، نعلمكم أن التلميذ(ة) {student_name} تغيّب {absences} مرات دون مبرر خلال آخر {days} يوما. {institution}',
        self::PAYMENT_REMINDER => 'السيد(ة) {guardian_name}، لدى التلميذ(ة) {student_name} مبلغ مستحق قدره {amount} دج وأقدم استحقاق بتاريخ {due_date}. {institution}',
    ];

    /** @return array{subject: ?string, body: string} the institution's active template for the key, else the built-in text */
    public static function for(string $key): array
    {
        $template = MessageTemplate::query()->where('key', $key)->where('is_active', true)->first();

        return $template === null
            ? ['subject' => null, 'body' => self::BODIES[$key]]
            : ['subject' => $template->subject, 'body' => $template->body];
    }
}
