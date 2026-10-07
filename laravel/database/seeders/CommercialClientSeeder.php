<?php

namespace Database\Seeders;

use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\Laboratory;
use App\Models\PriceList;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use LogicException;

class CommercialClientSeeder extends Seeder
{
    /**
     * Seed demo commercial clients with deterministic effective assignments.
     */
    public function run(): void
    {
        $laboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->firstOrFail();
        $priceLists = PriceList::query()
            ->where('laboratory_id', $laboratory->id)
            ->whereIn('name', ['Tarifa Convenios', 'Tarifa Aseguradoras'])
            ->get()
            ->keyBy('name');

        $definitions = [
            [
                'name' => 'Seguros Vida Plena',
                'type' => CommercialClient::TYPE_INSURANCE,
                'tax_id' => 'DEMO-SEGUROS-001',
                'price_list' => 'Tarifa Aseguradoras',
            ],
            [
                'name' => 'Corporación Atlas',
                'type' => CommercialClient::TYPE_COMPANY,
                'tax_id' => 'DEMO-EMPRESA-001',
                'price_list' => 'Tarifa Convenios',
            ],
            [
                'name' => 'Convenio Salud Integral',
                'type' => CommercialClient::TYPE_AGREEMENT,
                'tax_id' => 'DEMO-CONVENIO-001',
                'price_list' => 'Tarifa Convenios',
            ],
        ];

        foreach ($definitions as $definition) {
            $commercialClient = CommercialClient::query()->updateOrCreate(
                [
                    'laboratory_id' => $laboratory->id,
                    'name' => $definition['name'],
                ],
                [
                    'type' => $definition['type'],
                    'tax_id' => $definition['tax_id'],
                    'phone' => '+50222000100',
                    'email' => null,
                    'address' => 'Ciudad de Guatemala',
                    'notes' => 'Cliente comercial del dataset demo.',
                    'status' => CommercialClient::STATUS_ACTIVE,
                ],
            );
            $priceList = $priceLists->get($definition['price_list'])
                ?? throw new LogicException("Missing demo price list [{$definition['price_list']}].");

            CommercialClientPriceList::query()->updateOrCreate(
                [
                    'laboratory_id' => $laboratory->id,
                    'commercial_client_id' => $commercialClient->id,
                    'price_list_id' => $priceList->id,
                    'starts_at' => CarbonImmutable::parse('2026-01-01'),
                ],
                [
                    'ends_at' => null,
                    'status' => CommercialClientPriceList::STATUS_ACTIVE,
                ],
            );
        }
    }
}
