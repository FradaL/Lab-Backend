<?php

namespace Tests\Feature\ClinicalValidation;

use App\ClinicalValidation\ReferenceResolver;
use App\Models\Patient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReferenceResolverTest extends TestCase
{
    #[DataProvider('contexts')]
    public function test_three_valued_resolution_does_not_infer_missing_context(array $rules, array $input, string $status, ?string $key): void
    {
        $patient = new Patient(['birth_date' => '2026-10-01', 'gender' => 'M']);
        $patient->id = 1;
        $resolved = app(ReferenceResolver::class)->resolve([['code' => 'DEMO', 'references' => $rules]], $patient, ['reference_date' => '2026-10-11'] + $input, 7);
        $this->assertSame($status, $resolved['references'][0]['status']);
        $this->assertSame($key, $resolved['references'][0]['reference_key']);
        $this->assertSame(in_array($status, ['insufficient_context', 'ambiguous', 'conflicting_context']), $resolved['blocked']);
        $this->assertArrayNotHasKey('interpretation', $resolved['references'][0]);
    }

    public static function contexts(): array
    {
        $general = ['key' => 'general', 'priority' => 1, 'min' => 0];
        $specific = ['key' => 'specific', 'priority' => 2, 'min' => 1, 'sex' => 'DEMO-S'];

        return [
            'none' => [[], [], 'not_configured', null],
            'general' => [[$general], [], 'resolved', 'general'],
            'higher priority unknown blocks' => [[$general, $specific], [], 'insufficient_context', null],
            'gender is not clinical sex' => [[$specific], [], 'insufficient_context', null],
            'specific confirmed' => [[$general, $specific], ['sex' => 'DEMO-S'], 'resolved', 'specific'],
            'not applicable' => [[$specific], ['sex' => 'OTHER'], 'not_applicable', null],
            'completed age days inclusive' => [[['key' => 'age', 'priority' => 1, 'age_min_days' => 10, 'age_max_days' => 10, 'min' => 0]], [], 'resolved', 'age'],
            'missing condition' => [[['key' => 'condition', 'priority' => 1, 'conditions' => ['demo' => 'yes'], 'min' => 0]], [], 'insufficient_context', null],
            'explicit condition' => [[['key' => 'condition', 'priority' => 1, 'conditions' => ['demo' => 'yes'], 'min' => 0]], ['conditions' => ['demo' => 'yes']], 'resolved', 'condition'],
            'false excludes despite missing age' => [[['key' => 'rule', 'priority' => 1, 'age_min_days' => 20, 'sex' => 'M', 'min' => 0]], [], 'not_applicable', null],
            'ambiguous defense' => [[$general, ['key' => 'other', 'priority' => 1, 'min' => 1]], [], 'ambiguous', null],
            'unknown lower priority does not displace' => [[$specific + ['conditions' => ['demo' => 'yes']], $general + ['sex' => 'UNKNOWN']], ['sex' => 'DEMO-S', 'conditions' => ['demo' => 'yes']], 'resolved', 'specific'],
        ];
    }

    public function test_only_unknown_competitors_that_can_displace_the_winner_require_context(): void
    {
        $patient = new Patient;
        $resolution = app(ReferenceResolver::class)->resolve([['code' => 'DEMO', 'references' => [
            ['key' => 'known', 'priority' => 10],
            ['key' => 'irrelevant_lower', 'priority' => 1, 'sex' => 'DEMO'],
            ['key' => 'unknown_higher', 'priority' => 11, 'conditions' => ['needed' => 'DEMO']],
        ]]], $patient, ['reference_date' => '2026-10-11'], 1);
        $this->assertSame(['conditions.needed'], $resolution['references'][0]['missing_fields']);
        $this->assertSame(['conditions.needed'], $resolution['context']['missing_relevant_fields']);
    }
}
