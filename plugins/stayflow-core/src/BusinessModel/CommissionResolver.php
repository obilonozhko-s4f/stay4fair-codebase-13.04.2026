<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

final class CommissionResolver
{
    public function resolveFromGuestTotal(float $guestTotal): array
    {
        $guestTotal = round(max(0.0, $guestTotal), 2);

        if ($guestTotal <= 0.0) {
            return [
                'guest_total'      => 0.0,
                'commission_net'   => 0.0,
                'commission_vat'   => 0.0,
                'commission_gross' => 0.0,
                'owner_payout'     => 0.0,
            ];
        }

        $settings = get_option('stayflow_core_settings', []);
        
        $fee_raw = isset($settings['commission_default']) ? (float)$settings['commission_default'] : (defined('BSBT_FEE') ? (float)BSBT_FEE * 100 : 15.0);
        if ($fee_raw > 0.0 && $fee_raw <= 1.0) $fee_raw *= 100;
        $feeRate = $fee_raw / 100.0;

        $vat_raw = isset($settings['platform_vat_rate']) ? (float)$settings['platform_vat_rate'] : (defined('BSBT_VAT_ON_FEE') ? (float)BSBT_VAT_ON_FEE * 100 : 19.0);
        $vatRate = $vat_raw / 100.0;

        $commissionNet   = round($guestTotal * max(0.0, $feeRate), 2);
        $commissionVat   = round($commissionNet * max(0.0, $vatRate), 2);
        $commissionGross = round($commissionNet + $commissionVat, 2);
        $ownerPayout     = round($guestTotal - $commissionNet, 2);

        return [
            'guest_total'      => $guestTotal,
            'commission_net'   => $commissionNet,
            'commission_vat'   => $commissionVat,
            'commission_gross' => $commissionGross,
            'owner_payout'     => $ownerPayout,
        ];
    }
}