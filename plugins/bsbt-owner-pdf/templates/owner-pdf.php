<?php
/**
 * Version: 1.1.0
 * * Owner PDF Template
 *
 * Variables available:
 * @var array $d
 */

if (!defined('ABSPATH')) {
    exit;
}

// RU: Используем нативную функцию WP для безопасного вывода
$e = static function ($v) {
    return esc_html((string)$v);
};

// RU: Получаем локальный путь к логотипу из нашего безопасного движка
$logo = \StayFlow\Support\PdfEngine::logoPath();

/* ============================= */
/* Guest address block */
/* ============================= */

$guest_address_lines = [];

if (!empty($d['guest_addr'])) {
    $guest_address_lines[] = $d['guest_addr'];
}

if (!empty($d['guest_zip']) || !empty($d['guest_city'])) {
    $guest_address_lines[] = trim(($d['guest_zip'] ?? '') . ' ' . ($d['guest_city'] ?? ''));
}

if (!empty($d['guest_country'])) {
    $guest_address_lines[] = $d['guest_country'];
} else {
    $guest_address_lines[] = 'Deutschland';
}

// Безопасно склеиваем уже экранированные строки
$guest_address = implode('<br>', array_map($e, $guest_address_lines));
$owner_name    = !empty($d['owner_name']) ? $d['owner_name'] : '—';
$model_key     = $d['model_key'] ?? '';

$is_model_b = ($model_key === 'model_b');

/* ============================= */
/* Dynamic wording by model */
/* ============================= */

$document_title = $is_model_b
    ? 'Buchungsbestätigung (Vermittlung) – #'
    : 'Leistungsabrechnung / Buchungsbestätigung – #';

$compensation_label = $is_model_b
    ? 'Vergütung an Vermieter'
    : 'Vereinbarter Einkaufspreis / Vergütung';

$tax_contract_text = $is_model_b
    ? '<strong style="color:#212F54;">Geschäftsmodell B (Vermittlung)</strong><br>
       Sie (der Eigentümer / Gastgeber) sind der direkte Vertragspartner des Gastes für die Beherbergungsleistung. Stay4Fair.com handelt bei dieser Buchung ausschließlich als Vermittler. Die in dieser Abrechnung ausgewiesene Service- bzw. Vermittlungsgebühr von Stay4Fair enthält – soweit ausgewiesen – die gesetzliche Umsatzsteuer von 19 %. Die ordnungsgemäße Versteuerung der Beherbergungsleistung sowie gegebenenfalls die Abführung kommunaler Beherbergungsabgaben obliegt Ihnen als Gastgeber.'
    : '<strong style="color:#212F54;">Geschäftsmodell A (Eigengeschäft / Merchant of Record)</strong><br>
       Stay4Fair.com tritt bei dieser Buchung als direkter Vertragspartner des Gastes und Merchant of Record auf. Stay4Fair erwirbt die Beherbergungsleistung von Ihnen zu dem individuell vereinbarten Einkaufspreis und veräußert diese im eigenen Namen an den Gast weiter. Die Abführung der gesetzlichen Umsatzsteuer auf den Gastpreis sowie gegebenenfalls der kommunalen Beherbergungsabgabe obliegt Stay4Fair.com. <em>Hinweis: Unabhängig hiervon bleibt die steuerliche Behandlung der Ihnen von Stay4Fair gezahlten Vergütung in Ihrem eigenen steuerlichen Verantwortungsbereich.</em>';

$settlement_text = $is_model_b
    ? 'Die Abrechnung und Auszahlung an Sie erfolgt in der Regel innerhalb von 3–7 Werktagen nach dem maßgeblichen Abrechnungszeitpunkt gemäß den vereinbarten Bedingungen. Für die korrekte steuerliche Behandlung Ihrer Einnahmen, die Einhaltung gesetzlicher Meldepflichten sowie etwaige lokale Abgaben sind ausschließlich Sie verantwortlich. Stay4Fair übernimmt hierfür keine Haftung und erbringt keine steuerliche oder rechtliche Beratung.'
    : 'Die Abrechnung und Zahlung des vereinbarten Einkaufspreises bzw. der Vergütung erfolgt in der Regel innerhalb von 3–7 Werktagen nach dem maßgeblichen Abrechnungszeitpunkt gemäß den vereinbarten Bedingungen. Für die korrekte steuerliche Behandlung der von Stay4Fair an Sie gezahlten Vergütung sowie für die Einhaltung Ihrer eigenen gesetzlichen Melde- und Erklärungspflichten sind ausschließlich Sie verantwortlich. Stay4Fair übernimmt hierfür keine Haftung und erbringt keine steuerliche oder rechtliche Beratung.';

?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">

<style>
body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 12px;
    color: #212F54;
    line-height: 1.45;
}

table {
    border-collapse: collapse;
    width: 100%;
}

.header td {
    vertical-align: top;
}

.logo img {
    height: 36px;
}

.contact {
    text-align: right;
    font-size: 10.5px;
    color: #555;
}

h1 {
    font-size: 18px;
    margin: 12px 0 6px 0;
}

.subline {
    font-size: 10.5px;
    color: #555;
    margin-bottom: 14px;
}

.box {
    border: 1px solid #D3D7E0;
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 10px;
}

.label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #7A8193;
    margin-bottom: 4px;
}

.value {
    font-size: 12.5px;
    font-weight: 700;
}

.note {
    font-size: 10.5px;
    color: #555;
}
</style>
</head>

<body>

<table class="header">
    <tr>
        <td class="logo">
            <?php if ($logo !== '') : ?>
                <img src="<?php echo esc_attr($logo); ?>" alt="Stay4Fair">
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

<h1><?php echo $e($document_title . ($d['booking_id'] ?? '')); ?></h1>

<div class="subline">
    Business Model: <strong><?php echo $e($d['business_model']); ?></strong>
    · Dokumenttyp: <?php echo $e($d['document_type']); ?>
</div>

<div class="box">
    <div class="label">Apartment</div>
    <div class="value">
        <?php echo $e($d['apt_title']); ?> (ID <?php echo $e($d['apt_id']); ?>)
        <?php if (!empty($d['wohnungs_id'])) : ?>
            · Wohnungs-ID: <?php echo $e($d['wohnungs_id']); ?>
        <?php endif; ?>
    </div>
    <div class="note" style="margin-top:6px">
        Adresse: <?php echo $e($d['apt_address']); ?><br>
        Vermieter / Ansprechpartner: <strong><?php echo $e($owner_name); ?></strong>
    </div>
</div>

<div class="box">
    <div class="label">Zeitraum</div>
    <div class="value">
        <?php echo $e($d['check_in']); ?> – <?php echo $e($d['check_out']); ?>
    </div>
    <div class="note" style="margin-top:6px">
        Nächte: <?php echo $e($d['nights']); ?> · Gäste: <?php echo $e($d['guests']); ?>
    </div>
</div>

<div class="box">
    <div class="label">Gast / Rechnungskontakt</div>
    <div class="note">
        <?php echo $e($d['guest_name']); ?><br>
        <?php if (!empty($d['guest_company'])) : ?>
            Firma: <?php echo $e($d['guest_company']); ?><br>
        <?php endif; ?>
        <?php echo $e($d['guest_email']); ?> · <?php echo $e($d['guest_phone']); ?><br>
        Adresse:<br>
        <?php echo wp_kses_post($guest_address ?: '—'); ?>
    </div>
</div>

<?php if ($is_model_b) : ?>
<div class="box">
    <div class="label">Brutto-Buchungspreis (Gast)</div>
    <div class="value">
        <?php echo $e($d['guest_gross_total'] ?? '0,00'); ?> €
    </div>
</div>
<?php endif; ?>

<div class="box">
    <div class="label"><?php echo $e($compensation_label); ?></div>
    <div class="value"><?php echo $e($d['payout']); ?> €</div>
</div>

<?php if ($is_model_b && !empty($d['pricing'])) : ?>
<div class="box">
    <div class="label">Provision &amp; Vermittlungsgebühr</div>
    <div class="note">
        Provision: <?php echo $e(($d['pricing']['commission_rate'] ?? 0) * 100); ?> %<br>
        Provision (netto): <?php echo $e(number_format((float)($d['pricing']['commission_net_total'] ?? 0), 2, ',', '.')); ?> €<br>
        MwSt auf Provision (19%): <?php echo $e(number_format((float)($d['pricing']['commission_vat_total'] ?? 0), 2, ',', '.')); ?> €<br>
        <strong>Provision (brutto): <?php echo $e(number_format((float)($d['pricing']['commission_gross_total'] ?? 0), 2, ',', '.')); ?> €</strong>
    </div>
</div>
<?php endif; ?>

<div class="box">
    <div class="label">Vertrags-, Steuer- &amp; Stornierungsinformationen</div>
    <div class="note">
        <?php echo $tax_contract_text; ?>
        <br><br>

        <strong style="color:#212F54;">Abrechnung / steuerliche Hinweise:</strong><br>
        <?php echo $settlement_text; ?>
        <br><br>

        <strong style="color:#dc2626;">Wichtiger Hinweis zu Stornierungen &amp; Ablehnungen:</strong><br>
        Verbindlich bestätigte Buchungen sind durch den Gastgeber bzw. Leistungserbringer ordnungsgemäß zu erfüllen. Eine ungerechtfertigte Ablehnung, Nichtbereitstellung der Unterkunft oder eine nachträgliche Stornierung einer bereits bestätigten Buchung kann zu erheblichen Ausfallkosten, Umbuchungsgebühren, vertraglichen Sanktionen sowie Schadensersatzansprüchen führen.
    </div>
</div>

</body>
</html>