<?php

namespace App\Cash;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuthorizeOrderCredit
{
    public function __construct(private CashAuthority $authority, private AuditWriter $audit, private OrderFinancialState $financial, private CashLedgerProjection $projection) {}

    public function authorize(Laboratory $lab, User $actor, int $orderId, array $input, string $key): array
    {
        return $this->write($lab, $actor, $orderId, $input, $key, false);
    }

    public function revoke(Laboratory $lab, User $actor, int $orderId, int $authorizationId, array $input, string $key): array
    {
        return $this->write($lab, $actor, $orderId, $input + ['authorization_id' => $authorizationId], $key, true);
    }

    private function write(Laboratory $lab, User $actor, int $orderId, array $input, string $key, bool $revoke): array
    {
        return $this->authority->run($lab, $actor, CashAuthority::CREDIT, function () use ($lab, $actor, $orderId, $input, $key, $revoke): array {
            $input = $this->validate($input, $key, $revoke);
            $type = $revoke ? 'revoke_credit' : 'authorize_credit';
            $canonical = ['operation' => $type, 'laboratory_order_id' => $orderId, 'target_id' => $input['authorization_id'] ?? null, 'actor_id' => $actor->id, 'expected_revision' => $input['expected_revision'], 'amount' => $input['amount'] ?? null, 'reason' => $input['reason']]; ksort($canonical);
            $keyHash = hash('sha256', $key); $requestHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$lab->id.':'.$type.':'.$keyHash]);
            $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $prior = DB::table('laboratory_order_cash_operations')->where('laboratory_id',$lab->id)->where('operation_type',$type)->where('key_hash',$keyHash)->first();
            if ($prior) { if (!hash_equals($prior->request_hash,$requestHash)) throw new ClinicalConflict('cash_idempotency_conflict','La clave identifica otra operación de Caja.'); return ['body'=>json_decode($prior->response_snapshot,true,flags:JSON_THROW_ON_ERROR),'replayed'=>true]; }
            if ($order->delivery_policy_snapshot === null) throw new ClinicalConflict('cash_context_unavailable','La orden no tiene contexto financiero disponible.');
            $state = DB::table('laboratory_order_financial_states')->where(['laboratory_id'=>$lab->id,'laboratory_order_id'=>$orderId])->lockForUpdate()->first();
            $latest = $state === null ? null : DB::table('laboratory_order_financial_revisions')->where(['laboratory_id'=>$lab->id,'laboratory_order_id'=>$orderId,'revision'=>$state->revision])->first();
            if (!$latest || $latest->total_snapshot !== $order->total) throw new ClinicalConflict('cash_evidence_unavailable','El contexto financiero de la orden está incompleto.',status:503);
            if ($state->revision !== $input['expected_revision']) throw new ClinicalConflict('cash_revision_conflict','La revisión financiera cambió. Actualiza la orden antes de continuar.');
            if (!$revoke && ($order->delivery_policy_snapshot['mode'] ?? null) !== 'payment_or_credit') throw new ClinicalConflict('cash_credit_policy_incompatible','La política de la orden no permite crédito.');
            $active = $state->active_credit_authorization_id === null ? null : DB::table('laboratory_order_credit_events')->where('id',$state->active_credit_authorization_id)->first();
            if ($revoke && (!$active || $active->id !== $input['authorization_id'])) throw new ClinicalConflict('cash_credit_not_active','La autorización ya no está activa.');
            $balance = $this->projection->totals(DB::table('laboratory_order_cash_movements')->where(['laboratory_id'=>$lab->id,'laboratory_order_id'=>$orderId]), $order->total)['balance_due'];
            if (!$revoke && $this->lessThan($input['amount'], $balance)) throw new ClinicalConflict('cash_credit_insufficient','El crédito no cubre el saldo vigente.',['amount'=>['El crédito debe cubrir el saldo vigente.']],422);
            $revision=$this->financial->next($state->revision); $operation=(string)Str::ulid(); $time=now()->toISOString();
            DB::table('laboratory_order_financial_states')->where('id',$state->id)->update(['revision'=>$revision,'updated_at'=>$time]);
            $id=DB::table('laboratory_order_credit_events')->insertGetId(['laboratory_id'=>$lab->id,'laboratory_order_id'=>$orderId,'operation_id'=>$operation,'financial_revision'=>$revision,'type'=>$revoke?'revoked':'authorized','amount'=>$revoke?null:$input['amount'],'reason'=>$input['reason'],'target_authorization_id'=>$revoke?$active->id:null,'replaces_authorization_id'=>$revoke?null:$active?->id,'created_by'=>$actor->id,'recorded_at'=>$time]);
            DB::table('laboratory_order_financial_states')->where('id',$state->id)->update(['active_credit_authorization_id'=>$revoke ? null : $id]);
            $resource=['id'=>$id,'financial_revision'=>$revision,'created_by'=>$actor->id,'recorded_at'=>$time,'type'=>$revoke?'revoked':'authorized','amount'=>$revoke?null:$input['amount'],'reason'=>$input['reason'],'target_authorization_id'=>$revoke?$active->id:null,'replaces_authorization_id'=>$revoke?null:$active?->id];
            $body=['data'=>['operation_id'=>$operation,'operation'=>$type,'laboratory_order_id'=>$orderId,'financial_revision'=>$revision,'resource'=>$resource]];
            $this->financial->revision($order,$revision,$state->revision,$revoke?'credit_revoked':'credit_authorized',$actor->id,$time,$id,'credit_event');
            DB::table('laboratory_order_cash_operations')->insert(['id'=>$operation,'laboratory_id'=>$lab->id,'laboratory_order_id'=>$orderId,'operation_type'=>$type,'key_hash'=>$keyHash,'request_hash'=>$requestHash,'created_by'=>$actor->id,'recorded_at'=>$time,'http_status'=>201,'response_snapshot'=>json_encode($body,JSON_THROW_ON_ERROR)]);
            $this->audit->record($lab,$actor,new AuditEvent($revoke?'cash.credit_revoked':'cash.credit_authorized','credit_event',$id,newValues:$resource+['operation_id'=>$operation,'laboratory_order_id'=>$orderId]));
            return ['body'=>json_decode(DB::table('laboratory_order_cash_operations')->where('id',$operation)->value('response_snapshot'),true,flags:JSON_THROW_ON_ERROR),'replayed'=>false];
        });
    }

    private function validate(array $input,string $key,bool $revoke): array
    {
        $allowed=$revoke?['expected_revision','reason','authorization_id']:['expected_revision','amount','reason']; $errors=[];
        foreach(array_diff(array_keys($input),$allowed) as $field) $errors[$field]=['Este campo no está permitido.'];
        if(!preg_match('/\A[A-Za-z0-9_.-]{16,100}\z/D',$key)) $errors['Idempotency-Key']=['Se requiere una clave válida de 16 a 100 caracteres.'];
        if(!is_int($input['expected_revision']??null)||$input['expected_revision']<1||$input['expected_revision']>2147483647) $errors['expected_revision']=['La revisión debe ser un entero JSON positivo.'];
        if(!$revoke&&(!is_string($input['amount']??null)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$input['amount'])||$input['amount']==='0.00')) $errors['amount']=['El monto debe ser una cadena decimal positiva de dos decimales dentro del rango permitido.'];
        if($revoke&&(!is_int($input['authorization_id']??null)||$input['authorization_id']<1)) $errors['authorization_id']=['La autorización debe ser un entero positivo.'];
        if(!is_string($input['reason']??null)||mb_strlen($input['reason'])>1000||trim($input['reason'])==='') $errors['reason']=['El motivo es obligatorio y debe tener hasta 1000 caracteres.']; else $input['reason']=trim($input['reason']);
        if($errors) throw ValidationException::withMessages($errors); return $input;
    }

    private function lessThan(string $left, string $right): bool
    {
        return strcmp(str_pad(str_replace('.', '', $left), 12, '0', STR_PAD_LEFT), str_pad(str_replace('.', '', $right), 12, '0', STR_PAD_LEFT)) < 0;
    }
}
