<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

use DOMDocument;
use DOMXPath;

/**
 * Version: 1.8.2 (Bulletproof + DB Option Interceptor)
 * RU: Модификатор документов бронирования.
 * - [FIX] Перехватывает get_option('mphb_invoice_title') во время генерации PDF.
 * - Добавляет юридические дисклеймеры для Моделей А и В.
 * - Комиссия платформы (Model B) вынесена из таблицы в инфоблок.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class InvoiceModifier
{
    public static function init(): void
    {
        static $booted = false;
        if ($booted) return;
        $booted = true;

        // Хуки перед и после генерации PDF
        add_action('mphb_invoices_print_pdf_before', [self::class, 'pdfBefore'], 1);
        add_action('mphb_invoices_print_pdf_after', [self::class, 'pdfAfter'], 99);

        // Хук для переменных (таблицы, тексты)
        add_filter(
            'mphb_invoices_print_pdf_variables',
            [self::class, 'filterInvoiceVariables'],
            20,
            2
        );
    }

    private static function getSettings(): array
    {
        $settings = get_option('stayflow_core_settings', []);
        
        $fee_raw = isset($settings['commission_default']) ? (float)$settings['commission_default'] : 15.0;
        if ($fee_raw > 0.0 && $fee_raw <= 1.0) {
            $fee_raw *= 100;
        }
        
        $vat_b = isset($settings['platform_vat_rate']) ? (float)$settings['platform_vat_rate'] : 19.0;
        $vat_a = isset($settings['platform_vat_rate_a']) ? (float)$settings['platform_vat_rate_a'] : 7.0;

        return [
            'fee'       => $fee_raw / 100.0,
            'vat_b'     => $vat_b / 100.0,
            'vat_a'     => $vat_a / 100.0,
            'vat_b_raw' => $vat_b,
            'vat_a_raw' => $vat_a,
        ];
    }

    private static function resolveModel(int $bookingId): string
    {
        $snapshot = trim((string)get_post_meta($bookingId, '_bsbt_snapshot_model', true));
        if ($snapshot !== '') {
            return $snapshot === 'model_b' ? 'model_b' : 'model_a';
        }

        $roomDetails = get_post_meta($bookingId, 'mphb_room_details', true);
        if (is_array($roomDetails) && !empty($roomDetails)) {
            $first = reset($roomDetails);
            if (isset($first['room_type_id'])) {
                $roomType = (int)$first['room_type_id'];
                $model = trim((string)get_post_meta($roomType, '_bsbt_business_model', true));
                if ($model === 'model_b') return 'model_b';
            }
        }
        return 'model_a';
    }

    // ==========================================
    // PDF GENERATION HOOKS (TITLE INTERCEPTION)
    // ==========================================

    public static function pdfBefore($booking): void
    {
        // 1. Принудительный английский
        if (function_exists('switch_to_locale')) {
            switch_to_locale('en_US');
        }

        // 2. Определяем ID бронирования
        $bookingId = is_object($booking) && method_exists($booking, 'getId')
            ? (int)$booking->getId()
            : (is_numeric($booking) ? (int)$booking : 0);

        if ($bookingId > 0) {
            $model = self::resolveModel($bookingId);
            
            // Если это Модель B — перехватываем запрос к базе данных для опции заголовка
            if ($model === 'model_b') {
                add_filter('option_mphb_invoice_title', [self::class, 'overrideOptionTitle'], 999);
            }
        }
    }

    public static function pdfAfter($booking): void
    {
        // 1. Возвращаем локаль
        if (function_exists('restore_previous_locale')) {
            restore_previous_locale();
        }

        // 2. Отключаем перехватчик базы данных, чтобы не сломать другие места
        remove_filter('option_mphb_invoice_title', [self::class, 'overrideOptionTitle'], 999);
    }

    /**
     * RU: Заменяет слово в настройках MotoPress на лету.
     */
    public static function overrideOptionTitle($title)
    {
        if (!is_string($title)) {
            return $title;
        }

        $replaced = str_ireplace('BOOKING INVOICE', 'BOOKING CONFIRMATION & PAYMENT SUMMARY', $title);
        
        // Если слово BOOKING INVOICE не найдено, ищем просто INVOICE
        if ($replaced === $title) {
            $replaced = str_ireplace('INVOICE', 'BOOKING CONFIRMATION & PAYMENT SUMMARY', $title);
        }

        return $replaced;
    }

    // ==========================================
    // VARIABLES & CONTENT MODIFICATION
    // ==========================================

    public static function filterInvoiceVariables(array $vars, $booking): array
    {
        if (!function_exists('MPHB')) return $vars;

        $bookingId = is_object($booking) && method_exists($booking, 'getId')
            ? (int)$booking->getId()
            : (int)$booking;

        if ($bookingId <= 0) return $vars;

        try {
            $bookingObj = \MPHB()->getBookingRepository()->findById($bookingId);
            if (!$bookingObj) return $vars;

            $model = self::resolveModel($bookingId);

            // Блок клиента и логика расчетов
            $vars = self::replaceCustomerBlock($vars, $bookingObj, $bookingId);
            $vars = self::modifyBookingDetails($vars, $bookingObj, $bookingId, $model);

        } catch (\Throwable $e) {
            // Silence
        }

        return $vars;
    }

    private static function modifyBookingDetails(array $vars, $booking, int $bookingId, string $model): array
    {
        if (empty($vars['BOOKING_DETAILS']) || !is_string($vars['BOOKING_DETAILS'])) return $vars;
        if (!function_exists('mphb_format_price')) return $vars;

        $html = $vars['BOOKING_DETAILS'];
        $gross = (float)$booking->getTotalPrice();
        $s = self::getSettings();

        $fee = 0.0;
        $vat = 0.0;

        // --- CALCULATIONS ---
        if ($model === 'model_b') {
            $snapFee = get_post_meta($bookingId, '_bsbt_snapshot_fee_brut_total', true);
            $snapVat = get_post_meta($bookingId, '_bsbt_snapshot_fee_vat_total', true);

            if ($snapFee !== '') {
                $fee = (float)$snapFee;
                $vat = (float)$snapVat;
            } else {
                $rate = get_post_meta($bookingId, '_bsbt_snapshot_fee_rate', true);
                $f = $rate !== '' ? (float)$rate : $s['fee'];
                $fee = round($gross * $f, 2);
                $net = round($fee / (1 + $s['vat_b']), 2);
                $vat = round($fee - $net, 2);
            }
        } else {
            // В Модели A добавляем строку с НДС в таблицу
            $vat = round($gross - ($gross / (1 + $s['vat_a'])), 2);
            if ($vat > 0) {
                $vatPercent = fmod((float)$s['vat_a_raw'], 1) == 0 ? (int)$s['vat_a_raw'] : round((float)$s['vat_a_raw'], 1);
                $html = self::insertRowBeforeTotal($html, "VAT ({$vatPercent}%) included", mphb_format_price($vat));
            }
        }

        // --- LEGAL DISCLOSURE BLOCK ---
        $disclaimerText = '';
        if ($model === 'model_b') {
            $disclaimerText .= '<strong style="color:#082567; font-size:13px;">Contracting Party (Accommodation): Property Owner</strong><br><br>';
            $disclaimerText .= 'Stay4Fair acts as an intermediary platform facilitating this booking between the guest and the property owner. ';
            $disclaimerText .= 'The accommodation service is provided by the property owner. A direct contractual relationship exists between the guest and the property owner.<br><br>';
            
            // Выводим комиссию отдельным информационным блоком
            if ($fee > 0) {
                $disclaimerText .= '<strong>Included platform service component (for information only):</strong><br>';
                $disclaimerText .= 'The total amount paid includes the Stay4Fair platform service fee of <strong>' . mphb_format_price($fee) . '</strong> ';
                $disclaimerText .= '(which includes applicable VAT of ' . mphb_format_price($vat) . ').<br><br>';
            }

            $disclaimerText .= 'This document is a payment summary and booking confirmation; it does not constitute a tax invoice for the accommodation services. ';
            $disclaimerText .= 'If required, a separate tax invoice for the accommodation may be obtained directly from the owner. ';
            $disclaimerText .= 'Local city tax is treated in accordance with the applicable local rules and the status of the accommodation provider.';
        } else {
            $disclaimerText .= '<strong style="color:#082567; font-size:13px;">Contracting Party: Stay4Fair (Sole Proprietorship)</strong><br><br>';
            $disclaimerText .= 'Stay4Fair acts as the contractual partner and direct service provider for this accommodation booking. ';
            $disclaimerText .= 'Stay4Fair provides the accommodation service in its own name and on its own account. ';
            $disclaimerText .= 'The total amount includes the statutory applicable VAT on accommodation services as well as local city tax where applicable. ';
            $disclaimerText .= 'Stay4Fair is responsible for the proper invoicing and remittance of applicable taxes to the relevant authorities. ';
            $disclaimerText .= 'If you require a VAT invoice for business purposes, this document serves as your official receipt.';
        }

        $disclaimerHtml = '<br><table style="width:100%; border-collapse:collapse;"><tr><td style="padding:15px; border:1px solid #D3D7E0; font-size:11px; color:#555; line-height:1.5; text-align:left; border-radius:10px;">' . $disclaimerText . '</td></tr></table>';

        if (isset($vars['PAYMENT_INFO'])) {
            $vars['PAYMENT_INFO'] .= $disclaimerHtml;
        } elseif (isset($vars['PAYMENT_DETAILS'])) {
            $vars['PAYMENT_DETAILS'] .= $disclaimerHtml;
        } else {
            $html .= $disclaimerHtml;
        }

        $vars['BOOKING_DETAILS'] = $html;
        return $vars;
    }

    private static function replaceCustomerBlock(array $vars, $booking, int $bookingId): array
    {
        $customer = $booking->getCustomer();
        if (!$customer) return $vars;

        $name = trim($customer->getFirstName() . ' ' . $customer->getLastName());
        $company = self::metaFirstNonEmpty($bookingId, ['mphb_company', '_mphb_company', 'company']);
        $street = self::metaFirstNonEmpty($bookingId, ['mphb_address1', 'mphb_street']);
        $house = self::metaFirstNonEmpty($bookingId, ['mphb_house', 'mphb_house_number']);
        $zip = self::metaFirstNonEmpty($bookingId, ['mphb_zip']);
        $city = self::metaFirstNonEmpty($bookingId, ['mphb_city']);
        $country = self::countryFullName((string)$customer->getCountry());

        $html = '';
        if ($name !== '') $html .= '<strong>' . esc_html($name) . '</strong><br/>';
        if ($company !== '') $html .= esc_html($company) . '<br/>';
        
        $line1 = trim($street . ' ' . $house);
        if ($line1 !== '') $html .= esc_html($line1) . '<br/>';
        
        $line2 = trim($zip . ' ' . $city);
        if ($line2 !== '') $html .= esc_html($line2) . '<br/>';
        if ($country !== '') $html .= esc_html($country);

        $vars['CUSTOMER_INFORMATION'] = $html;
        $vars['CUSTOMER_INFO'] = $html;
        $vars['customer_info'] = $html;

        return $vars;
    }

    private static function countryFullName(string $code): string
    {
        $code = trim($code);
        if ($code === '') return '';
        if (function_exists('WC') && \WC() && \WC()->countries) {
            try {
                $countries = \WC()->countries->get_countries();
                $upper = strtoupper($code);
                if (isset($countries[$upper])) return $countries[$upper];
            } catch (\Throwable $e) {}
        }
        return $code;
    }

    private static function metaFirstNonEmpty(int $postId, array $keys): string
    {
        foreach ($keys as $k) {
            $v = get_post_meta($postId, $k, true);
            if (is_scalar($v)) {
                $v = trim((string)$v);
                if ($v !== '') return $v;
            }
        }
        return '';
    }

    private static function insertRowBeforeTotal(string $html, string $label, string $value): string
    {
        if (!class_exists('DOMDocument')) return $html;

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<html><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        $rows = $xpath->query("//tr[th and (translate(normalize-space(th[1]),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ')='TOTAL' or translate(normalize-space(th[1]),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ')='GESAMT')]");

        if ($rows && $rows->length > 0) {
            $target = $rows->item(0);
            $tr = $dom->createElement('tr');
            $th = $dom->createElement('th', $label);
            $td = $dom->createElement('td', wp_strip_all_tags($value));
            $tr->appendChild($th);
            $tr->appendChild($td);
            $target->parentNode->insertBefore($tr, $target);
        }

        $rendered = $dom->saveHTML();
        $rendered = preg_replace('~^.*?<body>(.*)</body>.*$~is', '$1', $rendered);
        
        return (is_string($rendered) && trim($rendered) !== '') ? $rendered : $html;
    }
}