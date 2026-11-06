<?php

namespace Tests\Unit;

use App\ClinicalValidation\ResultClassification;
use PHPUnit\Framework\TestCase;

class ResultClassificationTest extends TestCase
{
    public function test_numeric_classification_is_exact_and_critical_thresholds_are_inclusive(): void
    {
        $service = new ResultClassification;
        $results = [['parameter_code' => 'N', 'value_type' => 'numeric', 'value' => '12345678901234567890.123456789']];
        $reference = ['parameter_code' => 'N', 'status' => 'resolved', 'reference' => ['min' => '12345678901234567890.123456780', 'max' => '12345678901234567890.123456790']];
        $this->assertSame('normal', $service->classify($results, [$reference])[0]['status']);
        $reference['reference']['max'] = '12345678901234567890.123456788';
        $this->assertSame('high', $service->classify($results, [$reference])[0]['status']);
        $reference['reference']['critical_max'] = '12345678901234567890.123456789';
        $this->assertSame('critical', $service->classify($results, [$reference])[0]['status']);
        $results[0]['value'] = '-5';
        $reference['reference'] = ['min' => '0', 'max' => '10', 'critical_min' => '-5'];
        $this->assertSame('critical', $service->classify($results, [$reference])[0]['status']);
        $results[0]['value'] = '-4.999999999';
        $this->assertSame('low', $service->classify($results, [$reference])[0]['status']);
        $this->assertSame('not_classified', $service->classify($results, [])[0]['status']);
    }
}
