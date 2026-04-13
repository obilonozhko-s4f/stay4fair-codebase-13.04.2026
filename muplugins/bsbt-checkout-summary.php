<?php
/**
 * Plugin Name: BSBT – Checkout Summary (FINAL & UNIFIED CODE V12 - GALLERY SLUG FIX + CANCELLATION + 2x2 LAYOUT)
 * Description: Dynamic summary card for Booking Checkout page. Includes Dynamic Model A/B VAT detection via AJAX, exact Snapshot math rounding, and English Price Breakdown. Updated City Tax text.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ===========================================
// ХУКИ ДЛЯ AJAX И ШОРТКОДА
// ===========================================

add_shortcode( 'bsbt_checkout_summary', 'bsbt_checkout_summary_render' );
add_action( 'wp_ajax_bsbt_get_room_gallery_by_slug', 'bsbt_get_room_gallery_by_slug' );
add_action( 'wp_ajax_nopriv_bsbt_get_room_gallery_by_slug', 'bsbt_get_room_gallery_by_slug' );

function bsbt_get_room_gallery_by_slug() {
    if ( empty( $_POST['slug'] ) ) { wp_die(); }
    $slug = sanitize_title( wp_unslash( $_POST['slug'] ) );
    $room_type = get_page_by_path( $slug, OBJECT, 'mphb_room_type' );
    if ( ! $room_type ) { wp_die(); }
    $room_type_id = $room_type->ID;
    
    // --- ПЕРЕДАЕМ МОДЕЛЬ И НАЛОГИ ВМЕСТЕ С ГАЛЕРЕЕЙ С ЗАЩИТОЙ ДРОБЕЙ ---
    $sf_settings = get_option( 'stayflow_core_settings', [] );
    
    $vat_rate_a = isset($sf_settings['platform_vat_rate_a']) ? (float)$sf_settings['platform_vat_rate_a'] : 7.0;
    if ($vat_rate_a > 0.0 && $vat_rate_a <= 1.0) $vat_rate_a *= 100; 
    
    $vat_rate_b = isset($sf_settings['platform_vat_rate']) ? (float)$sf_settings['platform_vat_rate'] : 19.0;
    if ($vat_rate_b > 0.0 && $vat_rate_b <= 1.0) $vat_rate_b *= 100;
    
    $commission = isset($sf_settings['commission_default']) ? (float)$sf_settings['commission_default'] : 15.0;
    if ($commission > 0.0 && $commission <= 1.0) $commission *= 100;
    
    $model = 'A'; 
    $m_meta = get_post_meta( $room_type_id, '_bsbt_business_model', true );
    if ( $m_meta ) {
        $model = strtoupper( str_replace( 'model_', '', $m_meta ) );
    }

    // Скрытый блок с данными для JS
    echo '<div id="bsbt-ajax-model-data" data-model="'.esc_attr($model).'" data-vat-a="'.esc_attr($vat_rate_a).'" data-vat-b="'.esc_attr($vat_rate_b).'" data-comm="'.esc_attr($commission).'" style="display:none;"></div>';

    // Галерея
    $image_ids = array();
    if ( has_post_thumbnail( $room_type_id ) ) {
        $image_ids[] = get_post_thumbnail_id( $room_type_id );
    }
    $gallery_meta = get_post_meta( $room_type_id, 'mphb_gallery', true );
    if ( empty( $gallery_meta ) ) {
        $gallery_meta = get_post_meta( $room_type_id, '_mphb_gallery', true );
    }
    if ( ! empty( $gallery_meta ) ) {
        if ( is_string( $gallery_meta ) ) {
            $extra_ids = array_filter( array_map( 'intval', explode( ',', $gallery_meta ) ) );
        } elseif ( is_array( $gallery_meta ) ) {
            $extra_ids = array_map( 'intval', $gallery_meta );
        } else {
            $extra_ids = array();
        }
        $image_ids = array_unique( array_merge( $image_ids, $extra_ids ) );
    }
    $image_ids = array_slice( $image_ids, 0, 12 );
    if ( empty( $image_ids ) ) { wp_die(); }
    ob_start();
    ?>
    <button type="button"
            class="bsbt-gallery-arrow bsbt-gallery-arrow-prev bsbt-gallery-arrow--disabled"
            aria-label="Previous photos">
        &lt;
    </button>
    <div class="bsbt-summary-photo-strip">
        <?php foreach ( $image_ids as $img_id ) : ?>
            <?php $full_url = wp_get_attachment_image_url( $img_id, 'large' ); ?>
            <div class="bsbt-summary-photo">
                <?php
                echo wp_get_attachment_image(
                    $img_id,
                    'medium',
                    false,
                    array(
                        'class'     => 'bsbt-summary-photo-img',
                        'loading'   => 'lazy',
                        'data-full' => $full_url ? esc_url( $full_url ) : '',
                    )
                );
                ?>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button"
            class="bsbt-gallery-arrow bsbt-gallery-arrow-next"
            aria-label="Next photos">
        &gt;
    </button>
    <?php
    echo ob_get_clean();
    wp_die();
}

// ===========================================
// РЕНДЕР КАРТОЧКИ: ГЛАВНАЯ ФУНКЦИЯ
// ===========================================

function bsbt_checkout_summary_render() {
    global $wpdb;

    $ajax_url        = admin_url( 'admin-ajax.php' );
    $booking_id      = 0;
    $room_type_post  = null;
    $adults          = 0;
    $children        = 0;
    $total_price     = '—';
    $total_price_raw = ''; 
    $check_in_raw    = '';
    $check_out_raw   = '';
    $guests_str      = '—';
    $room_type_id    = 0; 
    
    $is_step_checkout = ! isset( $_GET['step'] ); 
    $is_step_booking  = isset( $_GET['step'] ) && $_GET['step'] === 'booking'; 
    
    // --- 1. ШАГ 1: БЕЗОПАСНО ПОЛУЧАЕМ ДАННЫЕ 
    if ( $is_step_checkout ) {
        if ( isset( $_REQUEST['mphb_check_in_date'] ) ) {
            $check_in_raw = sanitize_text_field( $_REQUEST['mphb_check_in_date'] );
        }
        if ( isset( $_REQUEST['mphb_check_out_date'] ) ) {
            $check_out_raw = sanitize_text_field( $_REQUEST['mphb_check_out_date'] );
        }
        if ( isset( $_REQUEST['mphb_room_details'] ) && is_array( $_REQUEST['mphb_room_details'] ) ) {
            $first_details = reset( $_REQUEST['mphb_room_details'] );
            if ( isset( $first_details['room_type_id'] ) ) {
                $room_type_id = absint( $first_details['room_type_id'] );
            }
            if ( isset( $first_details['adults'] ) ) $adults = (int) $first_details['adults'];
            if ( isset( $first_details['children'] ) ) $children = (int) $first_details['children'];
        }
    }

    // --- 2. ШАГ 2: ПОПЫТКА ВОССТАНОВЛЕНИЯ ДАННЫХ ИЗ БАЗЫ БРОНИ
    if ( $is_step_booking ) {
        if ( isset( $_GET['booking_id'] ) ) {
            $booking_id = absint( $_GET['booking_id'] );
        }
        
        if ( $booking_id ) {
            $check_in_raw  = get_post_meta( $booking_id, 'mphb_check_in_date', true );
            $check_out_raw = get_post_meta( $booking_id, 'mphb_check_out_date', true );
            
            $room_details = get_post_meta( $booking_id, 'mphb_room_details', true );
            if ( ! empty( $room_details ) ) {
                 $first_room_booking = reset( $room_details );
                 $room_type_id       = isset($first_room_booking['room_type_id']) ? absint($first_room_booking['room_type_id']) : 0;
                 $adults             = isset($first_room_booking['adults']) ? (int) $first_room_booking['adults'] : 0;
                 $children           = isset($first_room_booking['children']) ? (int) $first_room_booking['children'] : 0;
            }
        }
    }
    
    // --- 3. РАБОТА С ТИПОМ НОМЕРА И МОДЕЛЬЮ А/В
    if ( $room_type_id ) {
        $room_type_post = get_post( $room_type_id );
    }

    $sf_settings = get_option( 'stayflow_core_settings', [] );
    
    $vat_rate_a = isset($sf_settings['platform_vat_rate_a']) ? (float)$sf_settings['platform_vat_rate_a'] : 7.0;
    if ($vat_rate_a > 0.0 && $vat_rate_a <= 1.0) $vat_rate_a *= 100;
    
    $vat_rate_b = isset($sf_settings['platform_vat_rate']) ? (float)$sf_settings['platform_vat_rate'] : 19.0;
    if ($vat_rate_b > 0.0 && $vat_rate_b <= 1.0) $vat_rate_b *= 100;
    
    $commission = isset($sf_settings['commission_default']) ? (float)$sf_settings['commission_default'] : 15.0;
    if ($commission > 0.0 && $commission <= 1.0) $commission *= 100;
    
    $model = 'A'; 
    if ( $room_type_id ) {
        $m_meta = get_post_meta( $room_type_id, '_bsbt_business_model', true );
        if ( $m_meta ) {
            $model = strtoupper( str_replace( 'model_', '', $m_meta ) );
        }
    }

    $check_in  = $check_in_raw  ? DateTime::createFromFormat( 'Y-m-d', $check_in_raw )  : null;
    $check_out = $check_out_raw ? DateTime::createFromFormat( 'Y-m-d', $check_out_raw ) : null;

    $check_in_str  = $check_in  ? $check_in->format( 'd M Y' ) : '—';
    $check_out_str = $check_out ? $check_out->format( 'd M Y' ) : '—';

    $nights = '—';
    if ( $check_in && $check_out ) {
        $diff   = $check_in->diff( $check_out );
        $nights = $diff->days;
    }
    
    if ( $adults > 0 || $children > 0 ) {
        $parts = array();
        if ( $adults > 0 ) {
            $parts[] = $adults . ' adult' . ( $adults > 1 ? 's' : '' );
        }
        if ( $children > 0 ) {
            $parts[] = $children . ' child' . ( $children > 1 ? 'ren' : '' );
        }
        $guests_str = implode( ', ', $parts );
    }

    $apartment_title = $room_type_post ? esc_html( $room_type_post->post_title ) : '—'; 
    $apartment_id    = $room_type_post ? absint( $room_type_post->ID ) : 0;
    $total_guests    = $adults + $children;

    $policy_type = $room_type_id ? get_post_meta( $room_type_id, '_bsbt_cancel_policy_type', true ) : '';
    if ( empty( $policy_type ) ) {
        $policy_type = 'nonref';
    }

    $policy_label = ( $policy_type === 'standard' )
        ? 'Free cancellation up to 30 days before arrival'
        : 'Non-refundable booking';

    echo '
        <input type="hidden" id="booking_id_field_hidden" name="booking_id_field" value="' . esc_attr( $booking_id ) . '">
        <input type="hidden" id="booking_guests_hidden" name="booking_guests" value="' . esc_attr( $total_guests ) . '">
        <input type="hidden" id="apartment_id_hidden" name="apartment_id" value="' . esc_attr( $apartment_id ) . '">
        <input type="hidden" id="apartment_title_hidden" name="apartment_title" value="' . esc_attr( $apartment_title ) . '">
        <input type="hidden" id="booking_price_field" name="booking_price" value="">
    ';

    ob_start();
    ?>
    <div class="bsbt-checkout-summary"
        data-booking-id="<?php echo esc_attr( $booking_id ?: '' ); ?>"
        data-room-slug="<?php echo esc_attr( $room_type_post ? $room_type_post->post_name : '' ); ?>"
        data-is-step-booking="<?php echo $is_step_booking ? 'true' : 'false'; ?>"
        data-model="<?php echo esc_attr( $model ); ?>"
        data-vat-a="<?php echo esc_attr( $vat_rate_a ); ?>"
        data-vat-b="<?php echo esc_attr( $vat_rate_b ); ?>"
        data-comm="<?php echo esc_attr( $commission ); ?>"> 
        
        <div class="bsbt-checkout-summary-card">
            <div class="bsbt-summary-photo-gallery"></div>
            <h3 class="bsbt-summary-title">Your reservation</h3>
            
            <p class="bsbt-summary-row bsbt-summary-apartment">
                <span class="bsbt-summary-label">Apartment</span><br>
                <a href="<?php echo $room_type_post ? esc_url( get_permalink( $room_type_post->ID ) ) : '#'; ?>"
                    target="_blank" class="bsbt-summary-apartment-link">
                    <strong class="bsbt-summary-apartment-title">
                        <?php echo esc_html( $apartment_title ); ?>
                    </strong>
                </a>
            </p>

            <div class="bsbt-summary-row-group">
                <p class="bsbt-summary-row">
                    <span class="bsbt-summary-label">Check-in</span><br>
                    <strong class="bsbt-summary-checkin"><?php echo esc_html( $check_in_str ); ?></strong>
                </p>
                <p class="bsbt-summary-row">
                    <span class="bsbt-summary-label">Check-out</span><br>
                    <strong class="bsbt-summary-checkout"><?php echo esc_html( $check_out_str ); ?></strong>
                </p>
            </div>

            <div class="bsbt-summary-row-group">
                <p class="bsbt-summary-row">
                    <span class="bsbt-summary-label">Nights</span><br>
                    <strong class="bsbt-summary-nights"><?php echo esc_html( $nights ); ?></strong>
                </p>
                <p class="bsbt-summary-row">
                    <span class="bsbt-summary-label">Guests</span><br>
                    <strong class="bsbt-summary-guests"><?php echo esc_html( $guests_str ); ?></strong>
                </p>
            </div>

            <div class="bsbt-summary-row bsbt-summary-total-row" style="flex-direction:column; align-items:flex-start;">
                <div style="display:flex; justify-content:space-between; width:100%;">
                    <span class="bsbt-summary-label">Total</span>
                    <span class="bsbt-summary-total-amount"><?php echo esc_html( $total_price ); ?></span>
                </div>
                <a href="javascript:void(0);" onclick="bsbtOpenPriceDetails()" style="font-size:12px; color:#082567; text-decoration:underline; align-self:flex-end; margin-top:6px;">Price Breakdown</a>
            </div>

            <p class="bsbt-summary-row bsbt-summary-policy">
                <span class="bsbt-summary-label">Cancellation</span><br>
                <span class="bsbt-summary-policy-text">
                    <?php echo esc_html( $policy_label ); ?>
                </span>
            </p>

            <p class="bsbt-summary-note" id="bsbt-dynamic-legal-text">
                <?php if ( $model === 'B' ) : ?>
                    The total amount includes the Stay4Fair service fee incl. <?php echo esc_html($vat_rate_b); ?>% VAT. City Tax is included in the price for private apartments; however, commercial hotels may charge it separately upon arrival. The contracting party for the accommodation is the respective property owner.
                <?php else : ?>
                    The total amount includes the statutory VAT of <?php echo esc_html($vat_rate_a); ?>% on accommodation services as well as the City Tax. The contracting party is Stay4Fair.com.
                <?php endif; ?>
                <br><br>
                No charge will be made at this step. Your card will be temporarily authorized to secure the booking. 
                Payment is only captured after confirmation.
            </p>
        </div>

        <div id="bsbt-price-popup" class="bsbt-price-popup" style="display:none;">
            <div class="bsbt-price-popup-inner">
                <span class="bsbt-price-popup-close" onclick="bsbtClosePriceDetails()">&times;</span>
                <h4 style="margin-top:0; margin-bottom:15px; color:#082567; font-size:18px;">Price Breakdown</h4>
                <div id="bsbt-price-breakdown-content"></div>
            </div>
        </div>
    </div>

    <script>
    window.bsbtOpenPriceDetails = function() {
        document.getElementById('bsbt-price-popup').style.display = 'flex';
    };
    window.bsbtClosePriceDetails = function() {
        document.getElementById('bsbt-price-popup').style.display = 'none';
    };

    document.addEventListener('DOMContentLoaded', function () {
        var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
        var summaryCard = document.querySelector('.bsbt-checkout-summary');
        var isStepBooking = summaryCard.getAttribute('data-is-step-booking') === 'true';
        var SESSION_KEY = 'bsbt_booking_summary_data';

        var saveSummaryData; 

        function parseMotoPressPrice(rawText) {
            var s = rawText.replace(/[^\d.,]/g, '');
            if (!s) return 0;
            var lastSep = Math.max(s.lastIndexOf(','), s.lastIndexOf('.'));
            if (lastSep === -1) return parseFloat(s);
            
            var hasComma = s.indexOf(',') > -1;
            var hasDot = s.indexOf('.') > -1;
            
            if (hasComma && hasDot) {
                s = s.replace(/[,.]/g, function(match, offset) {
                    return offset === lastSep ? '.' : '';
                });
                return parseFloat(s);
            } else {
                var parts = s.split(s[lastSep]);
                var lastPart = parts[parts.length - 1];
                if (lastPart.length === 2 || lastPart.length === 1) {
                    s = s.replace(/[,.]/g, '.');
                } else {
                    s = s.replace(/[,.]/g, '');
                }
                return parseFloat(s);
            }
        }

        function updatePriceBreakdown(parsedTotal) {
            var total = parseFloat(parsedTotal);
            if (isNaN(total) || total <= 0) return;
            
            var model = summaryCard.getAttribute('data-model') || 'A';
            var vatA = parseFloat(summaryCard.getAttribute('data-vat-a')) || 7.0;
            var vatB = parseFloat(summaryCard.getAttribute('data-vat-b')) || 19.0;
            var comm = parseFloat(summaryCard.getAttribute('data-comm')) || 15.0;
            
            var content = '';
            var formatCurrency = function(val) {
                return '€' + val.toFixed(2);
            };

            if (model === 'A') {
                var vatAmount = Math.round((total - (total / (1 + (vatA / 100)))) * 100) / 100;
                
                content += '<div class="bsbt-bd-row"><span>Accommodation (incl. City Tax):</span><span>' + formatCurrency(total) + '</span></div>';
                content += '<div class="bsbt-bd-row bsbt-bd-sub"><span>includes ' + vatA + '% VAT:</span><span>' + formatCurrency(vatAmount) + '</span></div>';
                content += '<div class="bsbt-bd-row bsbt-bd-total"><span>Total Price:</span><span>' + formatCurrency(total) + '</span></div>';
            } else {
                var commission_gross = Math.round((total * (comm / 100)) * 100) / 100;
                var commission_net = Math.round((commission_gross / (1 + (vatB / 100))) * 100) / 100;
                var commission_vat = Math.round((commission_gross - commission_net) * 100) / 100;
                var hostPayout = Math.round((total - commission_gross) * 100) / 100;
                
                content += '<div class="bsbt-bd-row"><span>Accommodation:</span><span>' + formatCurrency(hostPayout) + '</span></div>';
                content += '<div class="bsbt-bd-row"><span>Stay4Fair Service Fee:</span><span>' + formatCurrency(commission_gross) + '</span></div>';
                content += '<div class="bsbt-bd-row bsbt-bd-sub"><span>includes ' + vatB + '% VAT:</span><span>' + formatCurrency(commission_vat) + '</span></div>';
                content += '<div class="bsbt-bd-row bsbt-bd-total"><span>Total Price:</span><span>' + formatCurrency(total) + '</span></div>';
            }
            
            var bdContainer = document.getElementById('bsbt-price-breakdown-content');
            if (bdContainer) bdContainer.innerHTML = content;
        }

        function updateLegalText(model, vatA, vatB) {
            var noteElement = document.getElementById('bsbt-dynamic-legal-text');
            if (!noteElement) return;
            var baseText = "";
            if (model === 'B') {
                baseText = "The total amount includes the Stay4Fair service fee incl. " + vatB + "% VAT. City Tax is included in the price for private apartments; however, commercial hotels may charge it separately upon arrival. The contracting party for the accommodation is the respective property owner.";
            } else {
                baseText = "The total amount includes the statutory VAT of " + vatA + "% on accommodation services as well as the City Tax. The contracting party is Stay4Fair.com.";
            }
            noteElement.innerHTML = baseText + "<br><br>No charge will be made at this step. Your card will be temporarily authorized to secure the booking. Payment is only captured after confirmation.";
        }

        function bsbtEnsureLightbox() {
            var existing = document.querySelector('.bsbt-photo-lightbox');
            if (existing) { return existing; }
            var overlay = document.createElement('div');
            overlay.className = 'bsbt-photo-lightbox';
            overlay.style.position = 'fixed';
            overlay.style.inset = '0';
            overlay.style.background = 'rgba(0,0,0,0.75)';
            overlay.style.display = 'none';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.zIndex = '9999';
            var inner = document.createElement('div');
            inner.className = 'bsbt-photo-lightbox-inner';
            inner.style.maxWidth = '90%';
            inner.style.maxHeight = '90%';
            var img = document.createElement('img');
            img.className = 'bsbt-photo-lightbox-img';
            img.style.maxWidth = '100%';
            img.style.maxHeight = '100%';
            img.style.display = 'block';
            img.style.borderRadius = '10px';
            img.style.boxShadow = '0 10px 30px rgba(0,0,0,0.6)';
            inner.appendChild(img);
            overlay.appendChild(inner);
            document.body.appendChild(overlay);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    overlay.style.display = 'none';
                    img.src = '';
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay.style.display === 'flex') {
                    overlay.style.display = 'none';
                    img.src = '';
                }
            });
            return overlay;
        }
        
        function initApartmentAndGallery() {
            var slug = summaryCard.getAttribute('data-room-slug');

            if (!slug && isStepBooking) {
                try {
                    var storedData = sessionStorage.getItem(SESSION_KEY);
                    if (storedData) {
                       var data = JSON.parse(storedData);
                       slug = data.roomSlug;
                       if (slug) {
                           summaryCard.setAttribute('data-room-slug', slug); 
                       } else {
                           return; 
                       }
                    }
                } catch(e) { }
            }
            
            if (!slug) {
                var srcLink = document.querySelector('.mphb-room-type-title a');
                if (!srcLink) return;
                var href = srcLink.getAttribute('href') || '';
                var text = (srcLink.textContent || '').trim();
                var sumLink = summaryCard.querySelector('.bsbt-summary-apartment-link');
                var sumTitle = summaryCard.querySelector('.bsbt-summary-apartment-title');
                if (sumLink && href) { sumLink.setAttribute('href', href); }
                if (sumTitle && text) { sumTitle.textContent = text; }
                try {
                    var u = new URL(href, window.location.origin);
                    var parts = u.pathname.split('/').filter(Boolean);
                    if (parts.length) slug = parts[parts.length - 1];
                } catch (e) { slug = ''; }
                if (slug) {
                    summaryCard.setAttribute('data-room-slug', slug); 
                    if (typeof saveSummaryData === 'function' && !isStepBooking) {
                        saveSummaryData();
                    }
                } else {
                     return;
                }
            }

            var gallery = summaryCard.querySelector('.bsbt-summary-photo-gallery');
            if (!gallery) return;
            
            var formData = new FormData();
            formData.append('action', 'bsbt_get_room_gallery_by_slug');
            formData.append('slug', slug);
            
            gallery.innerHTML = '<div style="text-align:center; padding: 20px;">Loading photos...</div>';
            
            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                if (!html || !html.trim() || html.includes('wp_die')) {
                    gallery.innerHTML = '<div style="text-align:center; padding: 20px; color: #777;">Photos not available.</div>';
                    return;
                }
                gallery.innerHTML = html;
                
                var modelData = gallery.querySelector('#bsbt-ajax-model-data');
                if (modelData) {
                    var fModel = modelData.getAttribute('data-model');
                    var fVatA = modelData.getAttribute('data-vat-a');
                    var fVatB = modelData.getAttribute('data-vat-b');
                    var fComm = modelData.getAttribute('data-comm');

                    summaryCard.setAttribute('data-model', fModel);
                    summaryCard.setAttribute('data-vat-a', fVatA);
                    summaryCard.setAttribute('data-vat-b', fVatB);
                    summaryCard.setAttribute('data-comm', fComm);

                    updateLegalText(fModel, fVatA, fVatB);

                    var targetField = document.getElementById('booking_price_field');
                    if (targetField && targetField.value) {
                        updatePriceBreakdown(targetField.value);
                    }
                }

                initGalleryArrows(gallery);
                initGalleryLightbox(gallery);
            })
            .catch(function (error) { 
                gallery.innerHTML = '<div style="text-align:center; padding: 20px; color: red;">Error loading photos.</div>';
            });
            
            function initGalleryArrows(galleryRoot) {
                var strip = galleryRoot.querySelector('.bsbt-summary-photo-strip');
                var prevBtn = galleryRoot.querySelector('.bsbt-gallery-arrow-prev');
                var nextBtn = galleryRoot.querySelector('.bsbt-gallery-arrow-next');
                if (!strip) return;
                function updateArrows() {
                    var maxScroll = strip.scrollWidth - strip.clientWidth - 1;
                    if (maxScroll < 0) maxScroll = 0;
                    var pos = strip.scrollLeft;
                    if (prevBtn) { prevBtn.classList.toggle('bsbt-gallery-arrow--disabled', pos <= 0); }
                    if (nextBtn) { nextBtn.classList.toggle('bsbt-gallery-arrow--disabled', pos >= maxScroll); }
                }
                updateArrows();
                if (prevBtn) {
                    prevBtn.addEventListener('click', function () {
                        if (prevBtn.classList.contains('bsbt-gallery-arrow--disabled')) return;
                        var step = strip.clientWidth;
                        strip.scrollBy({ left: -step, behavior: 'smooth' });
                        setTimeout(updateArrows, 350);
                    });
                }
                if (nextBtn) {
                    nextBtn.addEventListener('click', function () {
                        if (nextBtn.classList.contains('bsbt-gallery-arrow--disabled')) return;
                        var step = strip.clientWidth;
                        strip.scrollBy({ left: step, behavior: 'smooth' });
                        setTimeout(updateArrows, 350);
                    });
                }
                strip.addEventListener('scroll', updateArrows);
            }
            function initGalleryLightbox(galleryRoot) {
                var overlay = bsbtEnsureLightbox();
                var imgLarge = overlay.querySelector('.bsbt-photo-lightbox-img');
                if (!imgLarge) return;
                var thumbs = galleryRoot.querySelectorAll('.bsbt-summary-photo-img');
                thumbs.forEach(function (thumb) {
                    thumb.style.cursor = 'pointer';
                    thumb.addEventListener('click', function () {
                        var full = thumb.getAttribute('data-full') || thumb.getAttribute('src');
                        if (!full) return;
                        imgLarge.src = full;
                        overlay.style.display = 'flex';
                    });
                });
            }
        }
        
        (function sessionAndPriceSync() {
            var targetField = document.getElementById('booking_price_field');
            var visiblePriceElement = summaryCard.querySelector('.bsbt-summary-total-amount');

            saveSummaryData = function() {
                if (!visiblePriceElement || visiblePriceElement.textContent.trim() === '—') return;
                
                var data = {
                    apartmentTitle: summaryCard.querySelector('.bsbt-summary-apartment-title').textContent.trim() || '—',
                    apartmentLink: summaryCard.querySelector('.bsbt-summary-apartment-link').getAttribute('href') || '#',
                    guests: summaryCard.querySelector('.bsbt-summary-guests').textContent.trim() || '—',
                    checkin: summaryCard.querySelector('.bsbt-summary-checkin').textContent.trim() || '—',
                    checkout: summaryCard.querySelector('.bsbt-summary-checkout').textContent.trim() || '—',
                    nights: summaryCard.querySelector('.bsbt-summary-nights').textContent.trim() || '—',
                    totalPriceDisplay: visiblePriceElement.textContent.trim() || '—',
                    totalPriceRaw: targetField.value.trim() || '',
                    roomSlug: summaryCard.getAttribute('data-room-slug') || '',
                    
                    model: summaryCard.getAttribute('data-model') || 'A',
                    vatA: summaryCard.getAttribute('data-vat-a') || 7,
                    vatB: summaryCard.getAttribute('data-vat-b') || 19,
                    comm: summaryCard.getAttribute('data-comm') || 15
                };

                try {
                    sessionStorage.setItem(SESSION_KEY, JSON.stringify(data));
                } catch (e) { }
            }

            function loadSummaryData() {
                try {
                    var storedData = sessionStorage.getItem(SESSION_KEY);
                    if (!storedData) { return; }
                    var data = JSON.parse(storedData);
                    
                    if (data.apartmentTitle && data.apartmentTitle !== '—') {
                        summaryCard.querySelector('.bsbt-summary-apartment-title').textContent = data.apartmentTitle;
                    }
                    if (data.apartmentLink && data.apartmentLink !== '#') {
                        summaryCard.querySelector('.bsbt-summary-apartment-link').setAttribute('href', data.apartmentLink);
                    }
                    
                    summaryCard.querySelector('.bsbt-summary-checkin').textContent = data.checkin;
                    summaryCard.querySelector('.bsbt-summary-checkout').textContent = data.checkout;
                    summaryCard.querySelector('.bsbt-summary-nights').textContent = data.nights;

                    if (data.guests && data.guests !== '—') {
                        summaryCard.querySelector('.bsbt-summary-guests').textContent = data.guests;
                    }
                    
                    if (visiblePriceElement) {
                        visiblePriceElement.textContent = data.totalPriceDisplay;
                    }
                    if (targetField) {
                        targetField.value = data.totalPriceRaw;
                    }
                    
                    if (data.model) summaryCard.setAttribute('data-model', data.model);
                    if (data.vatA) summaryCard.setAttribute('data-vat-a', data.vatA);
                    if (data.vatB) summaryCard.setAttribute('data-vat-b', data.vatB);
                    if (data.comm) summaryCard.setAttribute('data-comm', data.comm);

                    if (data.model) {
                        updateLegalText(data.model, data.vatA, data.vatB);
                    }

                    if (data.totalPriceRaw) {
                        updatePriceBreakdown(data.totalPriceRaw);
                    }
                    
                    if (data.roomSlug) {
                         summaryCard.setAttribute('data-room-slug', data.roomSlug);
                    }
                } catch (e) { }
            }

            if (!isStepBooking) {
                var containerToObserve = document.getElementById('mphb-price-details') || document.querySelector('.mphb-room-price-breakdown-wrapper') || document.body;
                
                var priceSelectors = [
                    '.mphb-price-breakdown-total .mphb-price', 
                    '.mphb-price' 
                ];
                
                function handlePriceFound() {
                    var foundPriceContainer = null;
                    var rawPriceText = '';

                    for (var i = 0; i < priceSelectors.length; i++) {
                        foundPriceContainer = document.querySelector(priceSelectors[i]); 
                        
                        if (foundPriceContainer) {
                            rawPriceText = foundPriceContainer.textContent.trim();
                            
                            if (/\d/.test(rawPriceText)) {
                                
                                var parsedVal = parseMotoPressPrice(rawPriceText);
                                targetField.value = parsedVal;
                                
                                if(visiblePriceElement) {
                                    visiblePriceElement.textContent = rawPriceText; 
                                }

                                updatePriceBreakdown(parsedVal);
                                
                                initApartmentAndGallery(); 
                                saveSummaryData();
                                observer.disconnect(); 
                                
                                var checkoutForm = document.querySelector('.mphb-checkout-form');
                                if(checkoutForm) {
                                       checkoutForm.addEventListener('submit', saveSummaryData);
                                }
                                return true;
                            }
                        }
                    }
                    return false;
                }

                var observer = new MutationObserver(function(mutations, obs) {
                    if (handlePriceFound()) { return; }
                });

                var config = { childList: true, subtree: true, characterData: true, attributes: true };
                observer.observe(containerToObserve, config);
                handlePriceFound();
                
            } else {
                loadSummaryData();
                initApartmentAndGallery(); 
            }

        })();

        (function guestsLiveUpdate() {
            var guestsTarget = summaryCard.querySelector('.bsbt-summary-guests');
            if (!summaryCard.getAttribute('data-booking-id') && guestsTarget && guestsTarget.textContent.trim() === '—') {
                var adultsSelect = document.querySelector('.mphb_sc_checkout-form select[name^="mphb_room_details"][name$="[adults]"]');
                var childrenInput = document.querySelector('.mphb_sc_checkout-form input[name^="mphb_room_details"][name$="[children]"]');
                function updateGuestsSummary() {
                    if (!guestsTarget) return;
                    var adults = 0, children = 0;
                    if (adultsSelect && adultsSelect.value) { adults = parseInt(adultsSelect.value, 10) || 0; }
                    if (childrenInput && childrenInput.value) { children = parseInt(childrenInput.value, 10) || 0; }
                    var parts = [];
                    if (adults > 0) parts.push(adults + ' adult' + (adults > 1 ? 's' : ''));
                    if (children > 0) parts.push(children + ' child' + (children > 1 ? 'ren' : ''));
                    guestsTarget.textContent = parts.length ? parts.join(', ') : '—';
                    
                    var hiddenGuests = document.getElementById('booking_guests_hidden');
                    if (hiddenGuests) { hiddenGuests.value = adults + children; }
                    
                    if (typeof saveSummaryData === 'function' && !isStepBooking) {
                        setTimeout(saveSummaryData, 50); 
                    }
                }
                updateGuestsSummary();
                if (adultsSelect) adultsSelect.addEventListener('change', updateGuestsSummary);
                if (childrenInput) childrenInput.addEventListener('change', updateGuestsSummary);
            }
        })();
    });
    </script>

    <style>
        .bsbt-summary-row-group { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 8px; }
        .bsbt-summary-row-group .bsbt-summary-row { width: 50%; margin-bottom: 0; }

        @media (max-width: 768px) {
            .bsbt-summary-row-group { flex-direction: column; gap: 6px; }
            .bsbt-summary-row-group .bsbt-summary-row { width: 100%; }
        }

        .bsbt-summary-policy-text { font-family: "Manrope", system-ui, sans-serif; font-size: 13px; color: #444; line-height: 1.4; }

        .bsbt-price-popup { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 10000; display: flex; align-items: center; justify-content: center; }
        .bsbt-price-popup-inner { background: #fff; padding: 24px; border-radius: 12px; width: 90%; max-width: 400px; position: relative; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .bsbt-price-popup-close { position: absolute; top: 12px; right: 18px; font-size: 26px; cursor: pointer; color: #94a3b8; transition: color 0.2s; }
        .bsbt-price-popup-close:hover { color: #dc2626; }
        .bsbt-bd-row { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 8px; color: #334155; }
        .bsbt-bd-sub { font-size: 12px; color: #64748b; margin-top: -6px; margin-bottom: 12px; }
        .bsbt-bd-total { font-weight: bold; font-size: 16px; border-top: 1px solid #e2e8f0; padding-top: 12px; margin-top: 12px; color: #082567; }
    </style>
    <?php

    return ob_get_clean();
}