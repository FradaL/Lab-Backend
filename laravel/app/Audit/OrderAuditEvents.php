<?php

namespace App\Audit;

final class OrderAuditEvents
{
    public const CREATED = 'order.created';

    public const EXAM_ADDED = 'order_exam.added';

    public const EXAM_REMOVED = 'order_exam.removed';

    public const DISCOUNT_SET = 'order.discount_set';

    public const DISCOUNT_REMOVED = 'order.discount_removed';

    public const STATUS_CHANGED = 'order.status_changed';

    public const SUBJECT_ORDER = 'laboratory_order';

    public const SUBJECT_ORDER_EXAM = 'laboratory_order_exam';
}
