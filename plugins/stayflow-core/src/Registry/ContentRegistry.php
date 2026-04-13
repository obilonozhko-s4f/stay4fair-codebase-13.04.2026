<?php

declare(strict_types=1);

namespace StayFlow\Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * File: /stayflow-core/src/Registry/ContentRegistry.php
 * Version: 1.3.1
 * RU: Реестр контента (тексты/лейблы). Добавлены тексты для попапа сравнения моделей.
 * EN: Content registry (texts/labels). Added texts for the model comparison popup.
 */
final class ContentRegistry extends AbstractRegistry
{
    protected function optionKey(): string
    {
        return 'stayflow_registry_content';
    }

    /**
     * RU: Переопределяем метод get, чтобы отдавать дефолтные тексты, если опция пуста.
     * EN: Override get method to provide default texts if option is empty.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $data = $this->all();
        
        // RU: Дефолтные тексты на случай, если в админке еще ничего не сохраняли
        // EN: Default fallback texts in case nothing is saved in the admin panel yet
        $defaults = [
            'contract_party_text_a' => 'The contracting party is Stay4Fair.com. This property is managed by our professional partner.',
            'contract_party_text_b' => 'The contracting party for the accommodation is the respective property owner. Stay4Fair acts as an authorized intermediary.',
            
            // RU: Тексты для попапа смены модели (Modell Wechseln)
            'model_a_compare_title' => '🔵 Modell A (Direkt)',
            'model_b_compare_title' => '🟡 Modell B (Vermittlung)',
            'model_a_compare_desc'  => '<strong>Stay4Fair zahlt die City-Tax</strong>.<br><br>Sie geben nur Ihren Netto-Auszahlungswunsch an. Wir kümmern uns um den Endpreis. <em>(Für die Einkommensteuer bleiben Sie selbst verantwortlich.)</em><br><br><em>Ideal für weniger Bürokratie.</em>',
            'model_b_compare_desc'  => '<strong>Sie zahlen die City-Tax & Steuern selbst</strong>.<br><br>Sie bestimmen den Brutto-Endpreis für den Gast. Stay4Fair berechnet 15% Provision.<br><br><em>Volle Preiskontrolle für den Vermieter.</em>',
            'model_compare_footer'  => 'Der Wechsel des Modells wird von unserem Team geprüft. Laufende Buchungen bleiben unberührt.',
        ];

        if (empty($data) && array_key_exists($key, $defaults)) {
            return $defaults[$key];
        }

        // Если есть сохраненные данные, но конкретный ключ пуст - отдаем дефолт
        if (isset($data[$key]) && trim((string)$data[$key]) === '' && array_key_exists($key, $defaults)) {
            return $defaults[$key];
        }

        return $data[$key] ?? ($defaults[$key] ?? $default);
    }
}