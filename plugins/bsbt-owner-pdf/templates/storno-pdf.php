<?php
/**
 * Template Name: Storno PDF Template (Stornorechnung)
 * Version: 1.0.3 - Corporate Style, Correct Names, Clean Prices
 */

if (!defined('ABSPATH')) {
    exit;
}

// Защита: если язык не передан, по умолчанию немецкий
$lang = isset($lang) ? $lang : 'de';

$stornoNumber = 'STR-' . $booking_id . '-' . date('Y');
$originalDate = get_post_field('post_date', $booking_id);
$cancellationDate = date('d.m.Y');

// Чистое форматирование цены (Без сырого HTML от wc_price)
$formattedTotal = number_format($original_total, 2, ',', '.') . ' €';
$displayTotal = $is_free ? '-' . $formattedTotal : $formattedTotal;

// ==========================================
// ЛОКАЛИЗАЦИЯ ТЕКСТОВ / LOCALIZATION
// ==========================================
if ($lang === 'en') {
    $docTitle       = 'CANCELLATION CONFIRMATION';
    $guestLabel     = 'Guest / Stay:';
    $refLabel       = 'Reference:';
    $origBookingStr = 'Original Booking No:';
    $origDateStr    = 'Booking Date:';
    $introText      = 'We hereby confirm the cancellation of the following booking:';
    $descHeader     = 'Description';
    $amountHeader   = 'Amount';
    $totalHeader    = 'Total (incl. VAT):';
    $description    = $is_free ? 'Cancellation of the booking (100% Refund)' : 'Cancellation fee according to Terms (100% Retained)';
    $noteFree       = 'The amount of ' . $formattedTotal . ' will be refunded to your original payment method.';
    $notePenalty    = 'No refund will be issued (100% cancellation fee retained).';
} else {
    $docTitle       = 'STORNORECHNUNG';
    $guestLabel     = 'Gast / Aufenthalt:';
    $refLabel       = 'Bezug auf:';
    $origBookingStr = 'Original Buchung Nr:';
    $origDateStr    = 'Buchungsdatum:';
    $introText      = 'Hiermit bestätigen wir die Stornierung der folgenden Buchung:';
    $descHeader     = 'Beschreibung';
    $amountHeader   = 'Betrag';
    $totalHeader    = 'Gesamtbetrag (inkl. MwSt):';
    $description    = $is_free ? 'Stornierung der Buchung (100% Rückerstattung)' : 'Stornogebühr laut AGB (100% Einbehalt)';
    $noteFree       = 'Der Betrag von ' . $formattedTotal . ' wird dem Gast auf das ursprüngliche Zahlungsmittel zurückerstattet.';
    $notePenalty    = 'Es erfolgt keine Rückerstattung an den Gast (100% Stornogebühr einbehalten).';
}

$guestFirstName = get_post_meta($booking_id, 'mphb_first_name', true);
$guestLastName  = get_post_meta($booking_id, 'mphb_last_name', true);
$checkIn        = get_post_meta($booking_id, 'mphb_check_in_date', true);
$checkOut       = get_post_meta($booking_id, 'mphb_check_out_date', true);

$checkInStr  = $checkIn ? date('d.m.Y', strtotime($checkIn)) : '';
$checkOutStr = $checkOut ? date('d.m.Y', strtotime($checkOut)) : '';
$datesStr    = $checkInStr . ' – ' . $checkOutStr;

// Используем PNG логотип, так как WEBP ломает генерацию PDF
$logoUrl = 'https://stay4fair.com/wp-content/uploads/2025/12/gorizontal-color-4.png';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr($lang); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html($docTitle . ' #' . $stornoNumber); ?></title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 13px; color: #333; line-height: 1.4; }
        .header { border-bottom: 2px solid #082567; padding-bottom: 15px; margin-bottom: 25px; }
        .logo { max-height: 50px; }
        .title { font-size: 20px; font-weight: bold; color: #082567; margin-bottom: 5px; }
        .details-table { width: 100%; margin-bottom: 30px; }
        .details-table td { padding: 5px 0; vertical-align: top; }
        
        /* Corporate Stay4Fair Table Style */
        .items-table { 
            width: 100%; 
            border-collapse: separate; 
            border-spacing: 0; 
            border: 1px solid #082567; 
            border-radius: 8px; 
            overflow: hidden; 
            margin-bottom: 30px; 
        }
        .items-table th { 
            background: #082567; 
            color: #ffffff; 
            text-align: left; 
            padding: 12px; 
            font-size: 13px;
        }
        .items-table td { 
            padding: 12px; 
            border-top: 1px solid #eee; 
        }
        .total-row td { 
            font-weight: bold; 
            background: #f8fafc; 
            border-top: 2px solid #082567;
            font-size: 14px;
        }
        
        .status-text { 
            font-weight: bold; 
            padding: 10px 15px; 
            border-radius: 6px; 
            display: inline-block; 
            margin-bottom: 40px;
        }
        .status-free { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #2e7d32; }
        .status-penalty { background: #ffebee; color: #c62828; border-left: 4px solid #c62828; }

        .footer-data {
            font-size: 10px; 
            color: #666; 
            text-align: center; 
            border-top: 1px solid #eee; 
            padding-top: 15px; 
            margin-top: 40px;
            line-height: 1.6;
        }
    </style>
</head>
<body>

    <div class="header">
        <table width="100%">
            <tr>
                <td width="50%">
                    <img src="<?php echo esc_url($logoUrl); ?>" class="logo" alt="Stay4Fair">
                </td>
                <td width="50%" style="text-align: right;">
                    <div class="title"><?php echo esc_html($docTitle); ?></div>
                    Nr: <?php echo esc_html($stornoNumber); ?><br>
                    Datum: <?php echo esc_html($cancellationDate); ?>
                </td>
            </tr>
        </table>
    </div>

    <table class="details-table">
        <tr>
            <td width="50%">
                <strong style="color:#082567; font-size: 14px;"><?php echo esc_html($guestLabel); ?></strong><br>
                <?php echo esc_html($guestFirstName . ' ' . $guestLastName); ?><br>
                <?php echo esc_html($datesStr); ?>
            </td>
            <td width="50%" style="text-align: right;">
                <strong style="color:#082567; font-size: 14px;"><?php echo esc_html($refLabel); ?></strong><br>
                <?php echo esc_html($origBookingStr); ?> <?php echo esc_html($booking_id); ?><br>
                <?php echo esc_html($origDateStr); ?> <?php echo esc_html(date_i18n('d.m.Y', strtotime($originalDate))); ?>
            </td>
        </tr>
    </table>

    <p style="margin-bottom: 15px;"><?php echo esc_html($introText); ?></p>

    <table class="items-table">
        <thead>
            <tr>
                <th><?php echo esc_html($descHeader); ?></th>
                <th style="text-align: right;"><?php echo esc_html($amountHeader); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo esc_html($description); ?></td>
                <td style="text-align: right;"><?php echo $displayTotal; ?></td>
            </tr>
            <tr class="total-row">
                <td><?php echo esc_html($totalHeader); ?></td>
                <td style="text-align: right;"><?php echo $displayTotal; ?></td>
            </tr>
        </tbody>
    </table>

    <div class="status-text <?php echo $is_free ? 'status-free' : 'status-penalty'; ?>">
        <?php echo esc_html($is_free ? $noteFree : $notePenalty); ?>
    </div>

    <div class="footer-data">
        <strong style="color:#082567;">STAY4FAIR.COM</strong><br>
        Inh. Oleksandr Bilonozhko<br>
        VAT-ID: DE279469959<br>
        WhatsApp: +49 176 24615269 &middot; E-mail: business@stay4fair.com &middot; stay4fair.com
    </div>

</body>
</html>