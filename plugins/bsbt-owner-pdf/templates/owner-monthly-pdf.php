<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.1.0
 * Owner Monthly PDF Template
 * * RU: Шаблон месячного отчета PDF (с разделением визуального отображения Модели А и В)
 * - v1.1.0: Интеграция с PdfEngine (замена внешнего URL на безопасный локальный путь)
 * EN: Monthly PDF template (with visual separation for Model A and B)
 * - v1.1.0: PdfEngine integration (replaced remote URL with safe local path)
 *
 * Variables available:
 * @var array $d (from $pdf_data)
 */

$e = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// ==========================================
// RU: Получение логотипа
// EN: Logo retrieval
// ==========================================
// Используем безопасный локальный путь вместо внешнего URL для предотвращения SSRF
$logo = \StayFlow\Support\PdfEngine::logoPath();
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<style>
body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 11px;
    color: #212F54;
    line-height: 1.4;
}
table { border-collapse: collapse; width: 100%; }
.header td { vertical-align: top; }
.logo img { height: 36px; }

.contact { text-align: right; font-size: 10px; color: #555; }
h1 { font-size: 18px; margin: 12px 0 6px 0; color: #082567; border-bottom: 2px solid #E0B849; padding-bottom: 5px; }

.info-box { margin-bottom: 20px; width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; background: #f8fafc; }
.info-box td { padding: 3px 5px; }
.info-label { font-weight: bold; color: #64748b; font-size: 10px; text-transform: uppercase; width: 120px; }

.data-table { margin-bottom: 20px; border: 1px solid #cbd5e1; }
.data-table th { background: #082567; color: #E0B849; font-weight: bold; text-align: left; padding: 8px; font-size: 10px; text-transform: uppercase; border-right: 1px solid #cbd5e1; }
.data-table td { padding: 8px; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; font-size: 11px; vertical-align: top; }
.data-table tr:nth-child(even) { background: #fdfdfd; }
.text-right { text-align: right !important; }
.total-row td { background: #f1f5f9; font-weight: bold; font-size: 13px; border-top: 2px solid #082567; }

/* RU: Стили для бейджей Моделей (A / B) */
/* EN: Styles for Model badges (A / B) */
.badge { padding: 2px 5px; border-radius: 4px; font-size: 8px; font-weight: bold; margin-left: 6px; text-transform: uppercase; }
.badge-a { background-color: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
.badge-b { background-color: #fef9c3; color: #ca8a04; border: 1px solid #fef08a; }

/* RU: Стили для налоговых блоков в футере */
/* EN: Styles for tax blocks in the footer */
.footer-note { font-size: 10px; color: #555; margin-top: 25px; border-top: 1px dashed #cbd5e1; padding-top: 15px; }
.tax-box { margin-bottom: 12px; padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; }
.tax-box-a { border-left: 4px solid #0284c7; }
.tax-box-b { border-left: 4px solid #ca8a04; }
.highlight { font-weight: bold; color: #082567; }
</style>
</head>

<body>

<table class="header" style="margin-bottom: 15px;">
    <tr>
        <td class="logo">
            <?php if ($logo !== '') : ?>
                <img src="<?php echo $e($logo); ?>" alt="Stay4Fair">
            <?php endif; ?>
        </td>
        <td class="contact">
            <strong>Stay4Fair.com</strong><br>
            Tel / WhatsApp: +49 176 24615269<br>
            E-Mail: business@stay4fair.com<br>
            Owner Portal: stay4fair.com/owner-login/
        </td>
    </tr>
</table>

<h1>Monatsabrechnung – <?php echo $e($d['month'] . ' / ' . $d['year']); ?></h1>

<table class="info-box">
    <tr>
        <td class="info-label">Vermieter:</td>
        <td><strong><?php echo $e($d['owner_name']); ?></strong></td>
        <td class="info-label">Adresse:</td>
        <td><?php echo $e($d['owner_address']); ?></td>
    </tr>
    <tr>
        <td class="info-label">Steuer-ID (VAT/DAC7):</td>
        <td><?php echo $e($d['owner_tax'] ?: '—'); ?></td>
        <td class="info-label">Auszahlungskonto (IBAN):</td>
        <td><strong><?php echo $e($d['owner_iban'] ?: 'Nicht hinterlegt'); ?></strong></td>
    </tr>
</table>

<table class="data-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Apartment &amp; Adresse</th>
            <th>Check-in / Check-out</th>
            <th class="text-right">Gäste</th>
            <th class="text-right">Buchungspreis<br><span style="font-weight:normal; font-size:8px;">(Brutto)</span></th>
            <th class="text-right">S4F Provision<br><span style="font-weight:normal; font-size:8px;">(inkl. 19% MwSt)</span></th>
            <th class="text-right" style="background:#E0B849; color:#082567;">Auszahlung<br><span style="font-weight:normal; font-size:8px;">(Netto)</span></th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($d['items'])): ?>
            <tr>
                <td colspan="7" style="text-align: center; padding: 30px; font-size: 14px; color: #64748b; font-weight: bold;">
                    In diesem Zeitraum gab es keine Buchungen oder Auszahlungen.<br>
                    <span style="font-size: 11px; font-weight: normal;">(Nullmeldung)</span>
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($d['items'] as $item): ?>
                <tr>
                    <td>#<?php echo $e($item['booking_id']); ?></td>
                    <td>
                        <strong><?php echo $e($item['apt_title']); ?></strong>
                        
                        <?php if ($item['model'] === 'model_a'): ?>
                            <span class="badge badge-a">Modell A</span>
                        <?php elseif ($item['model'] === 'model_b'): ?>
                            <span class="badge badge-b">Modell B</span>
                        <?php endif; ?>
                        
                        <br>
                        <span style="font-size: 9px; color: #64748b;"><?php echo $e($item['apt_address']); ?></span>
                    </td>
                    <td><?php echo $e(date('d.m.y', strtotime($item['check_in']))); ?> – <?php echo $e(date('d.m.y', strtotime($item['check_out']))); ?></td>
                    <td class="text-right"><?php echo $e($item['guests']); ?></td>
                    <td class="text-right"><?php echo $e(number_format((float)$item['gross'], 2, ',', '.')); ?> €</td>
                    
                    <?php if ($item['model'] === 'model_b'): ?>
                        <td class="text-right">
                            <strong><?php echo $e(number_format((float)$item['prov_gross'], 2, ',', '.')); ?> €</strong><br>
                            <?php if ((float)$item['prov_gross'] > 0): ?>
                            <span style="font-size:8px; color:#64748b; font-weight:normal; line-height: 1.2; display: inline-block; margin-top: 3px;">
                                Netto: <?php echo $e(number_format((float)$item['prov_net'], 2, ',', '.')); ?> €<br>
                                19% MwSt: <?php echo $e(number_format((float)$item['prov_vat'], 2, ',', '.')); ?> €
                            </span>
                            <?php endif; ?>
                        </td>
                    <?php else: ?>
                        <td class="text-right" style="color:#94a3b8;">— (Modell A)</td>
                    <?php endif; ?>

                    <td class="text-right highlight"><?php echo $e(number_format((float)$item['payout'], 2, ',', '.')); ?> €</td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <tr class="total-row">
            <td colspan="4" class="text-right">GESAMT FÜR <?php echo $e($d['month'] . '/' . $d['year']); ?>:</td>
            <td class="text-right"><?php echo $e(number_format((float)$d['total_gross'], 2, ',', '.')); ?> €</td>
            <td class="text-right">
                <?php echo $e(number_format((float)$d['total_prov'], 2, ',', '.')); ?> €<br>
                <?php if ($d['total_prov'] > 0): ?>
                <span style="font-size:8px; color:#64748b; font-weight:normal;">
                    Netto: <?php echo $e(number_format((float)$d['total_prov_net'], 2, ',', '.')); ?> €<br>
                    19% MwSt: <?php echo $e(number_format((float)$d['total_prov_vat'], 2, ',', '.')); ?> €
                </span>
                <?php endif; ?>
            </td>
            <td class="text-right" style="color: #082567;"><?php echo $e(number_format((float)$d['total_net'], 2, ',', '.')); ?> €</td>
        </tr>
    </tbody>
</table>

<div class="footer-note">
    <?php 
    $contentReg = get_option('stayflow_registry_content', []);
    
    // RU: Если в админке не переопределен текст, выводим наш новый структурированный формат
    // EN: If custom text is not set in admin, output the structured default layout
    if (!empty($contentReg['tax_notice_monthly'])) {
        $tax_text = $contentReg['tax_notice_monthly'];
        $tax_text = str_replace('<p>', '<p style="margin:0 0 10px 0;">', $tax_text);
        echo wp_kses_post($tax_text);
    } else {
        echo '<p style="margin:0 0 15px 0; font-size: 11px;">Die Auszahlung erfolgt in der Regel innerhalb von 3–7 Werktagen nach Abreise des Gastes.</p>';
        
        if ($d['has_model_a']) {
            echo '<div class="tax-box tax-box-a">';
            echo '<strong style="color: #0284c7; font-size: 10px;">MODELL A (Direkt):</strong><br>';
            echo 'Die Abführung der Beherbergungsteuer (City-Tax) für diese Buchungen wurde von Stay4Fair übernommen. Für die Versteuerung Ihrer Einkünfte (Einkommensteuer) sind Sie selbst verantwortlich.';
            echo '</div>';
        }
        
        if ($d['has_model_b']) {
            echo '<div class="tax-box tax-box-b">';
            echo '<strong style="color: #ca8a04; font-size: 10px;">MODELL B (Vermittlung):</strong><br>';
            echo 'Die erzielten Einkünfte aus der Vermietung sind steuerpflichtig. Die Verantwortung für die korrekte Versteuerung sowie die Einhaltung lokaler Satzungen zur Beherbergungssteuer (City Tax) liegt vollständig beim Vermieter.';
            echo '</div>';
        }
        
        echo '<p style="margin:15px 0 0 0; color: #64748b; font-style: italic;">Stay4Fair unterstützt Sie mit der Bereitstellung der Buchungsdaten, übernimmt jedoch keine steuerliche Beratung oder Haftung.</p>';
    }
    ?>
</div>

</body>
</html>