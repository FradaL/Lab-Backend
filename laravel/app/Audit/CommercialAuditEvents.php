<?php

namespace App\Audit;

final class CommercialAuditEvents
{
    public const PRICE_LIST_CREATED = 'price_list.created';

    public const PRICE_LIST_UPDATED = 'price_list.updated';

    public const PRICE_LIST_STATUS_CHANGED = 'price_list.status_changed';

    public const PRICE_LIST_DEFAULT_CHANGED = 'price_list.default_changed';

    public const EXAM_PRICE_CREATED = 'exam_price.created';

    public const EXAM_PRICE_CHANGED = 'exam_price.changed';

    public const EXAM_PRICE_STATUS_CHANGED = 'exam_price.status_changed';

    public const COMMERCIAL_ASSIGNMENT_CREATED = 'commercial_price_assignment.created';

    public const COMMERCIAL_ASSIGNMENT_UPDATED = 'commercial_price_assignment.updated';

    public const COMMERCIAL_ASSIGNMENT_STATUS_CHANGED = 'commercial_price_assignment.status_changed';

    public const SUBJECT_PRICE_LIST = 'price_list';

    public const SUBJECT_PRICE_LIST_EXAM = 'price_list_exam';

    public const SUBJECT_COMMERCIAL_ASSIGNMENT = 'commercial_client_price_list';
}
