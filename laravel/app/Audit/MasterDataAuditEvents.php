<?php

namespace App\Audit;

final class MasterDataAuditEvents
{
    public const PATIENT_CREATED = 'patient.created';

    public const PATIENT_UPDATED = 'patient.updated';

    public const PATIENT_STATUS_CHANGED = 'patient.status_changed';

    public const LABORATORY_EXAM_CREATED = 'laboratory_exam.created';

    public const LABORATORY_EXAM_UPDATED = 'laboratory_exam.updated';

    public const LABORATORY_EXAM_STATUS_CHANGED = 'laboratory_exam.status_changed';

    public const COMMERCIAL_CLIENT_CREATED = 'commercial_client.created';

    public const COMMERCIAL_CLIENT_UPDATED = 'commercial_client.updated';

    public const COMMERCIAL_CLIENT_STATUS_CHANGED = 'commercial_client.status_changed';

    public const DOCTOR_CREATED = 'doctor.created';

    public const DOCTOR_UPDATED = 'doctor.updated';

    public const DOCTOR_STATUS_CHANGED = 'doctor.status_changed';

    public const LABORATORY_AREA_CREATED = 'laboratory_area.created';

    public const LABORATORY_AREA_UPDATED = 'laboratory_area.updated';

    public const LABORATORY_AREA_STATUS_CHANGED = 'laboratory_area.status_changed';

    public const SAMPLE_TYPE_CREATED = 'sample_type.created';

    public const SAMPLE_TYPE_UPDATED = 'sample_type.updated';

    public const SAMPLE_TYPE_STATUS_CHANGED = 'sample_type.status_changed';

    public const SUBJECT_PATIENT = 'patient';

    public const SUBJECT_LABORATORY_EXAM = 'laboratory_exam';

    public const SUBJECT_COMMERCIAL_CLIENT = 'commercial_client';

    public const SUBJECT_DOCTOR = 'doctor';

    public const SUBJECT_LABORATORY_AREA = 'laboratory_area';

    public const SUBJECT_SAMPLE_TYPE = 'sample_type';
}
