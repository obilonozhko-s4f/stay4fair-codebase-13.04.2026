<?php

declare(strict_types=1);

namespace StayFlow\Integration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.0.0
 * RU: Кастомизация интерфейса MotoPress Hotel Booking.
 * Заменяет кнопки на модель "Запроса" (Request Booking).
 */
final class MphbUiCustomizer
{
    public function register(): void
    {
        // 1. Фильтры самого MotoPress (для скорости)
        add_filter('mphb_search_results_book_button_text', [$this, 'changeBookNowLabel']);
        add_filter('mphb_confirm_reservation_button_label', [$this, 'changeConfirmLabel']);

        // 2. Универсальный фильтр gettext (как страховка для других мест)
        add_filter('gettext', [$this, 'translateSpecificLabels'], 20, 3);
    }

    public function changeBookNowLabel($default_text): string
    {
        return str_starts_with(get_locale(), 'de_') ? 'Buchung anfragen' : 'Request Booking';
    }

    public function changeConfirmLabel($default_text): string
    {
        return str_starts_with(get_locale(), 'de_') ? 'Buchungsanfrage bestätigen' : 'Confirm Booking Request';
    }

    public function translateSpecificLabels(string $translated, string $text, string $domain): string
    {
        if ($domain !== 'motopress-hotel-booking') {
            return $translated;
        }

        $is_german = str_starts_with(get_locale(), 'de_');

        if ($text === 'Book Now') {
            return $is_german ? 'Buchung anfragen' : 'Request Booking';
        }

        if ($text === 'Confirm Reservation') {
            return $is_german ? 'Buchungsanfrage bestätigen' : 'Confirm Booking Request';
        }

        return $translated;
    }
}