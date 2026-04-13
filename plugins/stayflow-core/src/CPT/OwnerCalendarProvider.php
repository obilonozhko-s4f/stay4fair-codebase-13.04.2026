<?php

declare(strict_types=1);

namespace StayFlow\CPT;

// ==========================================================================
// RU: Защита от прямого доступа
// EN: Prevent direct access
// ==========================================================================
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 2.3.2
 * RU: Провайдер интерактивного календаря.
 * - [FIX]: Мобильная адаптация: Индивидуальный календарь превращается в вертикальный список (List View).
 * - [FIX]: Мобильная адаптация: Глобальный Timeline (Gantt) компактизируется для показа ~7 дней на экране.
 * - [NEW]: Блокировка кнопки и функционала "Preis ändern" для квартир с бизнес-моделью А.
 * EN: Calendar provider with mobile List View and compact Timeline optimizations.
 */
final class OwnerCalendarProvider
{
    // ==========================================================================
    // RU: РЕГИСТРАЦИЯ ШОРТКОДА
    // EN: REGISTER SHORTCODE
    // ==========================================================================
    public function register(): void
    {
        add_shortcode('sf_owner_calendar', [$this, 'renderCalendar']);
    }

    // ==========================================================================
    // RU: ВЫВОД КАЛЕНДАРЯ
    // EN: RENDER CALENDAR
    // ==========================================================================
    public function renderCalendar(): string
    {
        if (!is_user_logged_in()) {
            return '<div class="sf-alert">Bitte loggen Sie sich ein.</div>';
        }

        $userId = get_current_user_id();
        global $wpdb;
        $apt_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'bsbt_owner_id'
            WHERE p.post_type = 'mphb_room_type' 
            AND p.post_status != 'trash'
            AND (p.post_author = %d OR pm.meta_value = %d)
        ", $userId, $userId));

        $apartments = [];
        if (!empty($apt_ids)) {
            $apartments = get_posts(['post_type' => 'mphb_room_type', 'post__in' => $apt_ids, 'posts_per_page' => -1, 'post_status' => ['publish', 'draft', 'pending']]);
        }

        if (empty($apartments)) {
            return '<div class="sf-alert">Sie haben noch keine Unterkünfte.</div>';
        }

        ob_start();
        ?>
        <style>
            /* === SECTION: CSS Styles === */
            .sf-alert { padding: 15px; background: #fef2f2; color: #991b1b; border: 1px solid #f87171; border-radius: 8px; }
            .sf-cal-wrap { max-width: 1140px; margin: 0 auto; font-family: 'Segoe UI', Roboto, sans-serif; position: relative; }
            .sf-field-hint { font-size: 13px; color: #64748b; margin: 0 0 15px 0; line-height: 1.5; }
            
            .sf-cal-controls { display: flex; gap: 15px; align-items: center; justify-content: space-between; background: #fdf8ed; padding: 20px; border-radius: 12px; border: 2px solid #E0B849; margin-bottom: 20px; flex-wrap: wrap; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
            .sf-cal-select { padding: 10px 15px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 14px; outline: none; background: #fff; font-weight: 500; color: #082567; width: 100%; max-width: 350px; cursor: pointer; }
            
            .sf-date-filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
            .sf-date-filters input[type="date"] { padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; color: #082567; font-weight: bold; background: #fff; max-width: 140px; }
            .sf-cal-nav-btn { background: #082567; color: #E0B849; border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; font-weight: bold; transition: 0.2s; font-size: 16px; }
            .sf-cal-nav-btn:hover { background: #E0B849; color: #082567; }
            
            /* === Grid View CSS === */
            .sf-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; margin-bottom: 20px; user-select: none; }
            .sf-cal-day-header { text-align: center; font-weight: bold; color: #082567; padding: 10px 0; border-bottom: 2px solid #e2e8f0; }
            .sf-cal-day { background: #fff; border: 2px solid #e2e8f0; border-radius: 10px; min-height: 100px; display: flex; flex-direction: column; padding: 10px; cursor: pointer; transition: 0.15s; position: relative; overflow: hidden; }
            .sf-cal-day.empty { background: transparent; border: none; cursor: default; }
            .sf-cal-day:hover:not(.empty) { border-color: #cbd5e1; transform: translateY(-3px); box-shadow: 0 6px 12px rgba(0,0,0,0.08); }
            .sf-cal-day.status-free { border-color: #22c55e; background: #f0fdf4; } 
            .sf-cal-day.status-booked { border-color: #3b82f6; background: #eff6ff; } 
            .sf-cal-day.status-blocked { border-color: #ef4444; background: #fef2f2; } 
            .sf-cal-day.selected, .sf-timeline-cell-day.selected { border-color: #E0B849 !important; box-shadow: inset 0 0 0 3px #E0B849, inset 0 0 20px rgba(224, 184, 73, 0.35) !important; background-color: #fef9e7 !important; transform: scale(0.96); z-index: 10;}
            .sf-cal-day-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px; }
            .sf-cal-date-num { font-size: 16px; font-weight: 800; color: #1e293b; display: flex; flex-direction: column; }
            .sf-cal-date-num span { display: none; } /* Скрыто на десктопе, покажем на мобильном */

            /* === Timeline View CSS (Gantt) === */
            .sf-timeline-wrapper { overflow-x: auto; background: #fff; border: 2px solid #e2e8f0; border-radius: 10px; margin-bottom: 20px; user-select: none; padding-bottom: 5px;}
            .sf-timeline-row { display: flex; width: max-content; border-bottom: 1px solid #e2e8f0; }
            .sf-timeline-row:last-child { border-bottom: none; }
            .sf-timeline-header { background: #f8fafc; font-weight: bold; color: #082567; position: sticky; top: 0; z-index: 20; border-bottom: 2px solid #e2e8f0;}
            .sf-timeline-cell-apt { width: 220px; padding: 15px 10px; position: sticky; left: 0; background: inherit; border-right: 2px solid #e2e8f0; font-weight: bold; color: #082567; font-size: 13px; z-index: 15; display: flex; align-items: center; white-space: normal; box-shadow: 2px 0 5px rgba(0,0,0,0.05); }
            .sf-timeline-cell-day { width: 50px; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; border-right: 1px solid #e2e8f0; cursor: pointer; position: relative; font-size: 12px; transition: 0.1s;}
            .sf-timeline-header .sf-timeline-cell-day { padding: 10px 0; background: #f8fafc; font-size: 14px; border-right: 1px solid #cbd5e1; }
            .sf-timeline-cell-day:hover:not(.sf-timeline-header) { background: #e2e8f0; transform: scale(1.05); z-index: 10; border-radius: 4px;}
            .sf-timeline-cell-day.status-free { background: #f0fdf4; }
            .sf-timeline-cell-day.status-booked { background: #eff6ff; color: #3b82f6; font-weight:bold; }
            .sf-timeline-cell-day.status-blocked { background: #fef2f2; }

            .sf-cal-badge { font-size: 10px; font-weight: bold; padding: 3px 6px; border-radius: 6px; text-transform: uppercase; white-space: nowrap; }
            .badge-free { background: #22c55e; color: #fff; }
            .badge-blocked { background: #ef4444; color: #fff; }
            .badge-s4f { background: #E0B849; color: #082567; }
            .badge-airbnb { background: #ff5a5f; color: #fff; }
            .badge-booking { background: #003580; color: #fff; }
            .badge-ical { background: #64748b; color: #fff; }

            .sf-cal-day-info { font-size: 11px; color: #082567; line-height: 1.3; margin-top: auto; word-break: break-word; }
            .sf-cal-day-info strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

            .sf-cal-actions { display: flex; gap: 15px; padding: 20px; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; align-items: center; flex-wrap: wrap; margin-bottom: 30px; }
            .sf-3d-btn { position: relative !important; overflow: hidden !important; border-radius: 10px !important; border: none !important; box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important; transition: all 0.25s ease !important; cursor: pointer !important; display: inline-flex; align-items: center; justify-content: center; padding: 12px 24px; font-weight: 700; color: #fff !important; text-decoration: none; }
            .sf-3d-btn::before { content: "" !important; position: absolute !important; top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important; background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important; transform: scaleY(0.48) !important; filter: blur(5px) !important; opacity: 0.55 !important; z-index: 1 !important; pointer-events: none !important; }
            .sf-3d-btn:hover { transform: translateY(-2px) !important; }

            .btn-red { background-color: #ef4444 !important; }
            .btn-green { background-color: #22c55e !important; }
            .btn-blue { background-color: #3b82f6 !important; }
            .btn-navy { background-color: #082567 !important; color: #E0B849 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.2) 0%, rgba(0,0,0,0.15) 100%) !important; background-blend-mode: overlay; }
            .btn-sync { background-color: #E0B849 !important; color: #082567 !important; margin-left: auto; }
            #sf-cal-loader { display: none; color: #082567; font-weight: bold; background: #E0B849; padding: 5px 15px; border-radius: 20px; font-size: 12px; animation: pulse 1s infinite; }
            @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }

            .sf-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(8, 37, 103, 0.6); backdrop-filter: blur(5px); display: none; justify-content: center; align-items: center; z-index: 9999; opacity: 0; transition: opacity 0.3s; }
            .sf-modal-overlay.active { display: flex; opacity: 1; }
            .sf-modal-content { background: #fff; padding: 30px; border-radius: 20px; width: 90%; max-width: 450px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); transform: translateY(20px); transition: transform 0.3s; border: 2px solid #E0B849; position: relative; }
            .sf-modal-overlay.active .sf-modal-content { transform: translateY(0); }
            .sf-modal-close { position: absolute; top: 15px; right: 15px; background: none; border: none; font-size: 24px; color: #64748b; cursor: pointer; z-index: 10; }
            .sf-modal-title { color: #082567; margin: 0 0 15px 0; font-size: 22px; font-weight: 800; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; }
            .sf-info-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 15px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 5px; }
            .sf-info-row span:first-child { color: #64748b; font-weight: 600; }
            .sf-info-row span:last-child { color: #082567; font-weight: 800; text-align: right; }

            /* ==========================================================================
               RU: Мобильная адаптация (List View + Compact Timeline)
               EN: Mobile Responsiveness (List View + Compact Timeline)
               ========================================================================== */
            @media (max-width: 768px) {
                .sf-date-filters { width: 100%; justify-content: space-between; }
                .sf-date-filters .sf-date-inputs { order: -1; width: 100%; display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
                .sf-date-filters input[type="date"] { max-width: 42%; }
                #sf-prev-month, #sf-next-month { flex: 1; text-align: center; }
                #sf-btn-filter { width: 100%; margin-top: 10px; }
                
                .sf-cal-actions { flex-direction: column; align-items: stretch; gap: 12px; padding: 15px; }
                .sf-cal-actions > div { text-align: center; margin-bottom: 5px; }
                .sf-3d-btn { width: 100%; justify-content: center; box-sizing: border-box; margin-left: 0 !important; }

                /* RU: Превращаем Сетку Индивидуального Календаря в Список (List View) */
                .sf-cal-grid { display: flex; flex-direction: column; gap: 8px; }
                .sf-cal-day-header, .sf-cal-day.empty { display: none; }
                .sf-cal-day { 
                    flex-direction: row; 
                    align-items: center; 
                    justify-content: space-between; 
                    min-height: 60px; 
                    padding: 12px 15px; 
                }
                .sf-cal-day-top { margin-bottom: 0; width: auto; align-items: center; gap: 12px; flex: 1; }
                .sf-cal-date-num { flex-direction: row; align-items: baseline; gap: 5px; font-size: 18px; }
                .sf-cal-date-num span { display: inline; font-size: 13px; font-weight: 600; color: #64748b; text-transform: uppercase; }
                .sf-cal-day-info { margin-top: 0; text-align: right; min-width: 100px; }
                .sf-cal-day-info .text-right-mobile { text-align: right !important; }

                /* RU: Компактизируем Timeline (Все квартиры) для показа большего числа дней */
                .sf-timeline-cell-apt { width: 95px; padding: 10px 5px; font-size: 11px; line-height: 1.2; word-break: break-word; }
                .sf-timeline-cell-day { width: 42px; font-size: 11px; }
                .sf-timeline-header .sf-timeline-cell-day { font-size: 12px; }
                .sf-timeline-header .sf-timeline-cell-apt { font-size: 10px; }
            }
        </style>

        <div class="sf-cal-wrap">
            <h2 style="color:#082567; margin-bottom: 5px;">Verfügbarkeit & Preise</h2>
            <p class="sf-field-hint">Wählen Sie "Alle Apartments" für eine globale Übersicht oder ein einzelnes Apartment. Nutzen Sie die Pfeile (← →) zum Blättern der Monate oder wählen Sie eigene Daten.</p>

            <div class="sf-cal-controls">
                <div style="flex:1; min-width:250px;">
                    <select id="sf-apt-select" class="sf-cal-select">
                        <option value="all">🌍 Alle Apartments (Globale Übersicht)</option>
                        <?php foreach ($apartments as $apt): ?>
                            <option value="<?php echo esc_attr($apt->ID); ?>"><?php echo esc_html($apt->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="sf-date-filters">
                    <button class="sf-cal-nav-btn" id="sf-prev-month">←</button>
                    <div class="sf-date-inputs" style="display:flex; align-items:center; gap:8px;">
                        <input type="date" id="sf-date-from" title="Startdatum">
                        <span style="color:#082567; font-weight:bold; font-size:12px;">bis</span>
                        <input type="date" id="sf-date-to" title="Enddatum">
                    </div>
                    <button class="sf-cal-nav-btn" id="sf-next-month">→</button>
                    <button class="sf-3d-btn sf-3d-btn-navy" id="sf-btn-filter" style="padding: 8px 15px; margin-left:5px;">Zeigen</button>
                </div>
            </div>

            <div id="sf-cal-dynamic-container"></div>

            <div class="sf-cal-actions">
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <strong style="color:#082567;">Aktion für markierte Daten:</strong>
                    <span id="sf-cal-loader">⏳ Lädt...</span>
                </div>
                
                <button class="sf-3d-btn btn-red" id="btn-block">Sperren</button>
                <button class="sf-3d-btn btn-green" id="btn-unblock">Freigeben</button>
                <button class="sf-3d-btn btn-blue" id="btn-price" onclick="openPriceModal()">Preis ändern</button>
                <button class="sf-3d-btn btn-sync" id="btn-sync">↻ iCal Sync</button>
            </div>
        </div>

        <div class="sf-modal-overlay" id="modal-booking">
            <div class="sf-modal-content">
                <button class="sf-modal-close" onclick="closeModals()">×</button>
                <h3 class="sf-modal-title">Buchungsdetails</h3>
                <div class="sf-info-row"><span>Datum:</span> <span id="m-book-date"></span></div>
                <div class="sf-info-row"><span>Buchung ID:</span> <span id="m-book-id"></span></div>
                <div class="sf-info-row"><span>Gast Name:</span> <span id="m-book-guest"></span></div>
                <div class="sf-info-row"><span>Quelle:</span> <span id="m-book-source"></span></div>
                <div style="margin-top: 25px; display: flex; gap: 10px; justify-content: space-between;">
                    <button class="sf-3d-btn btn-navy" onclick="closeModals()" style="flex:1;">Schließen</button>
                    <a href="<?php echo home_url('/owner-bookings/'); ?>" class="sf-3d-btn btn-green" style="flex:1;">Zur Buchung</a>
                </div>
            </div>
        </div>

        <div class="sf-modal-overlay" id="modal-price">
            <div class="sf-modal-content">
                <button class="sf-modal-close" onclick="closeModals()">×</button>
                <h3 class="sf-modal-title">Preis anpassen</h3>
                <p class="sf-field-hint">Legen Sie einen benutzerdefinierten Preis für die ausgewählten Daten fest.</p>
                <div style="margin-bottom: 15px;">
                    <label style="display:block; font-size:12px; color:#64748b; font-weight:bold; margin-bottom:5px;">Neuer Preis pro Nacht (€):</label>
                    <input type="number" id="m-price-input" class="sf-cal-select" style="width: 100%; box-sizing: border-box;" placeholder="z.B. 150">
                </div>
                <div style="display: flex; gap: 10px; justify-content: space-between; margin-top: 20px;">
                    <button class="sf-3d-btn btn-navy" style="flex:1; padding: 10px 15px;" onclick="closeModals()">Abbrechen</button>
                    <button class="sf-3d-btn btn-green" style="flex:1; padding: 10px 15px;" onclick="saveCustomPrice()">Speichern</button>
                </div>
            </div>
        </div>

        <script>
        const editBaseUrl = '<?php echo esc_url(home_url('/edit-apartment/')); ?>';
        
        // RU: Генерируем маппинг "Apartment ID -> Business Model"
        // EN: Generate mapping "Apartment ID -> Business Model"
        window.sfAptModels = {};
        <?php foreach ($apartments as $apt): 
            $model = get_post_meta($apt->ID, '_bsbt_business_model', true) ?: 'model_a';
            echo "window.sfAptModels['{$apt->ID}'] = '{$model}';\n";
        endforeach; ?>

        function closeModals() { document.querySelectorAll('.sf-modal-overlay').forEach(m => m.classList.remove('active')); }
        
        function openBookingModal(date, data) {
            document.getElementById('m-book-date').textContent = date;
            document.getElementById('m-book-id').textContent = '#' + (data.booking_id || 'N/A');
            document.getElementById('m-book-guest').textContent = data.guest_name || 'Unbekannt';
            let color = data.platform_name === 'Airbnb' ? '#ff5a5f' : (data.platform_name === 'Booking.com' ? '#003580' : '#E0B849');
            let textColor = data.platform_name === 'Stay4Fair' ? '#082567' : '#fff';
            document.getElementById('m-book-source').innerHTML = `<span style="background:${color}; color:${textColor}; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:bold;">${data.platform_name}</span>`;
            document.getElementById('modal-booking').classList.add('active');
        }

        function openPriceModal() {
            if(window.sfSelectedCells.size === 0) return alert('Bitte wählen Sie zuerst freie Daten im Kalender aus.');
            
            // RU: Проверка на Модель А перед открытием окна
            // EN: Check for Model A before opening the price modal
            let hasModelA = false;
            window.sfSelectedCells.forEach(key => {
                let parts = key.split('|');
                if(window.sfAptModels[parts[0]] === 'model_a') {
                    hasModelA = true;
                }
            });

            if (hasModelA) {
                alert('Preisanpassungen sind für Modell A (Direkt) nicht verfügbar. Bitte wählen Sie nur Apartments im Modell B.');
                return;
            }

            document.getElementById('modal-price').classList.add('active');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const apiBase = '/wp-json/stayflow/v1/calendar';
            const nonce = '<?php echo wp_create_nonce('wp_rest'); ?>';
            
            let currentAptId = document.getElementById('sf-apt-select').value;
            let calendarData = {}; 
            let isDragging = false;
            window.sfSelectedCells = new Set();

            const containerEl = document.getElementById('sf-cal-dynamic-container');
            const loaderEl = document.getElementById('sf-cal-loader');
            const daysDe = ['So','Mo','Di','Mi','Do','Fr','Sa']; // Пн=1, Вс=0 в JS Date.getDay()

            // Инициализация дат по умолчанию (текущий месяц)
            let d = new Date();
            let y = d.getFullYear();
            let m = String(d.getMonth() + 1).padStart(2, '0');
            let startStr = `${y}-${m}-01`;
            let lastDay = new Date(y, d.getMonth() + 1, 0).getDate();
            let endStr = `${y}-${m}-${String(lastDay).padStart(2, '0')}`;
            
            const inputFrom = document.getElementById('sf-date-from');
            const inputTo = document.getElementById('sf-date-to');
            inputFrom.value = startStr;
            inputTo.value = endStr;

            function showLoader() { loaderEl.style.display = 'inline-block'; }
            function hideLoader() { loaderEl.style.display = 'none'; }

            // RU: Управление отображением кнопки цены при смене дропдауна
            function togglePriceButtonVisibility() {
                const btnPrice = document.getElementById('btn-price');
                if (currentAptId !== 'all' && window.sfAptModels[currentAptId] === 'model_a') {
                    btnPrice.style.display = 'none';
                } else {
                    btnPrice.style.display = 'inline-flex';
                }
            }

            // RU: Логика умного сдвига месяцев (Стрелки)
            function shiftMonth(direction) {
                if (!inputFrom.value || !inputTo.value) return;
                
                let dFrom = new Date(inputFrom.value);
                let dTo = new Date(inputTo.value);
                
                let isFirstDay = dFrom.getDate() === 1;
                let isLastDay = dTo.getDate() === new Date(dTo.getFullYear(), dTo.getMonth() + 1, 0).getDate();

                if (isFirstDay && isLastDay) {
                    let newFrom = new Date(dFrom.getFullYear(), dFrom.getMonth() + direction, 1);
                    let newTo = new Date(newFrom.getFullYear(), newFrom.getMonth() + 1, 0);
                    inputFrom.value = newFrom.getFullYear() + '-' + String(newFrom.getMonth()+1).padStart(2,'0') + '-01';
                    inputTo.value = newTo.getFullYear() + '-' + String(newTo.getMonth()+1).padStart(2,'0') + '-' + String(newTo.getDate()).padStart(2,'0');
                } else {
                    dFrom.setMonth(dFrom.getMonth() + direction);
                    dTo.setMonth(dTo.getMonth() + direction);
                    let pad = (n) => String(n).padStart(2,'0');
                    inputFrom.value = dFrom.getFullYear() + '-' + pad(dFrom.getMonth()+1) + '-' + pad(dFrom.getDate());
                    inputTo.value = dTo.getFullYear() + '-' + pad(dTo.getMonth()+1) + '-' + pad(dTo.getDate());
                }
                fetchCalendar();
            }

            document.getElementById('sf-prev-month').addEventListener('click', () => shiftMonth(-1));
            document.getElementById('sf-next-month').addEventListener('click', () => shiftMonth(1));

            async function fetchCalendar() {
                showLoader(); window.sfSelectedCells.clear();
                let from = inputFrom.value;
                let to = inputTo.value;
                
                if (!from || !to) { alert('Bitte Datum wählen'); hideLoader(); return; }
                if (new Date(from) > new Date(to)) { alert('Startdatum muss vor dem Enddatum liegen'); hideLoader(); return; }

                try {
                    const res = await fetch(`${apiBase}/get?apt_id=${currentAptId}&start_date=${from}&end_date=${to}`, { headers: { 'X-WP-Nonce': nonce } });
                    calendarData = await res.json(); 
                    renderView(from, to);
                } catch (e) { console.error(e); } finally { hideLoader(); }
            }

            function renderView(from, to) {
                containerEl.innerHTML = '';
                if (calendarData.is_all) {
                    renderTimeline(from, to);
                } else {
                    renderGrid(calendarData.dates || {}, from, to);
                }
            }

            function getDaysArray(start, end) {
                let arr = [];
                for(let dt=new Date(start); dt<=new Date(end); dt.setDate(dt.getDate()+1)){
                    let dy = dt.getFullYear(); let dm = String(dt.getMonth() + 1).padStart(2, '0'); let dd = String(dt.getDate()).padStart(2, '0');
                    let dayName = daysDe[dt.getDay()];
                    arr.push({ str: `${dy}-${dm}-${dd}`, dayNum: dt.getDate(), monthNum: dm, dayName: dayName });
                }
                return arr;
            }

            function renderTimeline(startStr, endStr) {
                let datesArr = getDaysArray(startStr, endStr);
                let wrapper = document.createElement('div');
                wrapper.className = 'sf-timeline-wrapper';
                
                let headRow = document.createElement('div');
                headRow.className = 'sf-timeline-row sf-timeline-header';
                let headCorner = document.createElement('div');
                headCorner.className = 'sf-timeline-cell-apt';
                headCorner.innerHTML = '🏢 Unterkunft / Datum <br><span style="font-size:10px; font-weight:normal; margin-top:3px; color:#64748b;">↓ Nach unten scrollen</span>';
                headRow.appendChild(headCorner);
                
                for(let dInfo of datesArr) {
                    let cell = document.createElement('div');
                    cell.className = 'sf-timeline-cell-day';
                    cell.innerHTML = `${dInfo.dayNum}<br><span style="font-size:9px; color:#64748b;">${dInfo.monthNum}</span>`;
                    headRow.appendChild(cell);
                }
                wrapper.appendChild(headRow);

                const apts = calendarData.apartments || {};
                for (let aId in apts) {
                    let apt = apts[aId];
                    let row = document.createElement('div');
                    row.className = 'sf-timeline-row';
                    
                    let titleCell = document.createElement('div');
                    titleCell.className = 'sf-timeline-cell-apt';
                    titleCell.style.background = '#fff';
                    
                    let aptLink = document.createElement('a');
                    aptLink.href = `${editBaseUrl}?apt_id=${aId}`;
                    aptLink.target = '_blank';
                    aptLink.textContent = apt.title;
                    aptLink.style.color = '#082567';
                    aptLink.style.textDecoration = 'none';
                    aptLink.addEventListener('mouseover', () => aptLink.style.textDecoration = 'underline');
                    aptLink.addEventListener('mouseout', () => aptLink.style.textDecoration = 'none');
                    titleCell.appendChild(aptLink);
                    
                    row.appendChild(titleCell);
                    
                    let dates = apt.data.dates || {};
                    for(let dInfo of datesArr) {
                        let dateStr = dInfo.str;
                        let dayData = dates[dateStr] || { status: 'free', price: 0, price_type: 'Basis' };
                        
                        let cell = document.createElement('div');
                        cell.className = `sf-timeline-cell-day status-${dayData.status}`;
                        
                        if (dayData.status === 'booked') {
                            cell.title = `Gebucht: ${dayData.guest_name} (${dayData.platform_name})`;
                            cell.innerHTML = '<span style="font-size:16px;">👤</span>'; 
                        } else if (dayData.status === 'blocked') {
                            cell.title = 'Gesperrt';
                            cell.innerHTML = '<span style="font-size:16px; color:#ef4444;">🔒</span>';
                        } else if (dayData.price > 0) {
                            let pColor = dayData.price_type === 'Individuell' ? '#E0B849' : '#64748b';
                            cell.innerHTML = `<span style="color:${pColor}; font-weight:bold;">${dayData.price}</span>`;
                        }
                        
                        cell.addEventListener('mousedown', (e) => {
                            if(dayData.status === 'booked') { openBookingModal(dateStr, dayData); return; }
                            isDragging = true; toggleSelect(cell, aId, dateStr);
                        });
                        cell.addEventListener('mouseenter', (e) => {
                            if(isDragging && dayData.status !== 'booked') toggleSelect(cell, aId, dateStr, true);
                        });

                        row.appendChild(cell);
                    }
                    wrapper.appendChild(row);
                }
                containerEl.appendChild(wrapper);
            }

            function renderGrid(datesObj, startStr, endStr) {
                let grid = document.createElement('div');
                grid.className = 'sf-cal-grid';
                
                // Рендер шапки дней недели для десктопа (сдвигаем: Пн-Вс)
                const gridDays = ['Mo','Di','Mi','Do','Fr','Sa','So'];
                gridDays.forEach(d => {
                    let dEl = document.createElement('div'); dEl.className = 'sf-cal-day-header'; dEl.textContent = d; grid.appendChild(dEl);
                });

                let startObj = new Date(startStr);
                let firstDay = startObj.getDay();
                let emptyCells = firstDay === 0 ? 6 : firstDay - 1; 

                for(let i=0; i<emptyCells; i++) {
                    let div = document.createElement('div'); div.className = 'sf-cal-day empty'; grid.appendChild(div);
                }

                let datesArr = getDaysArray(startStr, endStr);
                for(let dInfo of datesArr) {
                    let dateStr = dInfo.str;
                    let dayData = datesObj[dateStr] || { status: 'free', price: 0, price_type: 'Basis' };
                    
                    let div = document.createElement('div');
                    div.className = `sf-cal-day status-${dayData.status}`;
                    
                    let topDiv = document.createElement('div');
                    topDiv.className = 'sf-cal-day-top';
                    
                    let num = document.createElement('div'); 
                    num.className = 'sf-cal-date-num'; 
                    num.innerHTML = `${dInfo.dayNum} <span>${dInfo.dayName}</span>`; // span виден только на мобильном
                    
                    let badge = document.createElement('div'); 
                    if (dayData.status === 'free') {
                        badge.className = 'sf-cal-badge badge-free'; badge.textContent = 'Frei';
                    } else if (dayData.status === 'blocked') {
                        badge.className = 'sf-cal-badge badge-blocked'; badge.textContent = 'Gesperrt';
                    } else {
                        if(dayData.platform_name === 'Airbnb') { badge.className = 'sf-cal-badge badge-airbnb'; }
                        else if(dayData.platform_name === 'Booking.com') { badge.className = 'sf-cal-badge badge-booking'; }
                        else if(dayData.source_type === 'Stay4Fair') { badge.className = 'sf-cal-badge badge-s4f'; }
                        else { badge.className = 'sf-cal-badge badge-ical'; }
                        badge.textContent = dayData.platform_name;
                    }
                    
                    topDiv.appendChild(num); topDiv.appendChild(badge);
                    div.appendChild(topDiv);

                    let infoDiv = document.createElement('div');
                    infoDiv.className = 'sf-cal-day-info';

                    if (dayData.status === 'booked') {
                        if (dayData.source_type === 'Stay4Fair') {
                            infoDiv.innerHTML = `<div class="text-right-mobile"><strong>${dayData.guest_name}</strong><span style="opacity:0.7">ID: #${dayData.booking_id}</span></div>`;
                        } else {
                            infoDiv.innerHTML = `<div class="text-right-mobile"><span style="opacity:0.7">Ext. Sync</span></div>`;
                        }
                    } else if (dayData.status === 'free' && dayData.price > 0) {
                        let pColor = dayData.price_type === 'Individuell' ? '#E0B849' : '#64748b';
                        infoDiv.innerHTML = `<div style="text-align:right; margin-top:auto;" class="text-right-mobile">
                                                <strong style="color:#082567; font-size:14px;">${dayData.price}€</strong><br>
                                                <span style="font-size:9px; color:${pColor}; font-weight:bold; text-transform:uppercase;">${dayData.price_type}</span>
                                             </div>`;
                    }
                    div.appendChild(infoDiv);
                    
                    // Выделение кликом для мобильных (mousedown отрабатывает при touch)
                    div.addEventListener('mousedown', (e) => {
                        if(dayData.status === 'booked') { openBookingModal(dateStr, dayData); return; }
                        isDragging = true; toggleSelect(div, currentAptId, dateStr);
                    });
                    div.addEventListener('mouseenter', (e) => {
                        if(isDragging && dayData.status !== 'booked') toggleSelect(div, currentAptId, dateStr, true);
                    });

                    grid.appendChild(div);
                }
                containerEl.appendChild(grid);
            }

            function toggleSelect(el, aptId, dateStr, forceAdd = false) {
                let key = `${aptId}|${dateStr}`;
                if(forceAdd || !window.sfSelectedCells.has(key)) {
                    window.sfSelectedCells.add(key); el.classList.add('selected');
                } else {
                    window.sfSelectedCells.delete(key); el.classList.remove('selected');
                }
            }

            document.addEventListener('mouseup', () => isDragging = false);
            
            document.getElementById('sf-apt-select').addEventListener('change', (e) => { 
                currentAptId = e.target.value; 
                togglePriceButtonVisibility(); 
                fetchCalendar(); 
            });

            document.getElementById('sf-btn-filter').addEventListener('click', fetchCalendar);

            function getGroupedSelections() {
                let groups = {};
                window.sfSelectedCells.forEach(key => {
                    let parts = key.split('|');
                    if(!groups[parts[0]]) groups[parts[0]] = [];
                    groups[parts[0]].push(parts[1]);
                });
                return groups;
            }

            async function toggleBlockStatus(action) {
                if(window.sfSelectedCells.size === 0) return alert('Bitte wählen Sie zuerst Daten aus.');
                let groups = getGroupedSelections();
                showLoader();
                
                try {
                    let promises = [];
                    for (let aId in groups) {
                        let datesArr = groups[aId].sort();
                        let start_date = datesArr[0]; let end_date = datesArr[datesArr.length - 1]; 
                        promises.push(fetch(`${apiBase}/toggle-block`, {
                            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                            body: JSON.stringify({ apt_id: aId, start_date: start_date, end_date: end_date, action: action })
                        }));
                    }
                    await Promise.all(promises);
                    fetchCalendar();
                } catch(e) { console.error(e); hideLoader(); }
            }

            window.saveCustomPrice = async function() {
                let priceVal = document.getElementById('m-price-input').value;
                if (!priceVal || isNaN(priceVal) || priceVal <= 0) return alert('Bitte einen gültigen Preis eingeben.');
                if (window.sfSelectedCells.size === 0) return alert('Keine Daten ausgewählt.');

                let groups = getGroupedSelections();
                closeModals(); showLoader();

                try {
                    let promises = [];
                    for (let aId in groups) {
                        let datesArr = groups[aId].sort();
                        let start_date = datesArr[0]; let end_date = datesArr[datesArr.length - 1]; 
                        promises.push(fetch(`${apiBase}/update-price`, {
                            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                            body: JSON.stringify({ apt_id: aId, start_date: start_date, end_date: end_date, price: priceVal })
                        }));
                    }
                    await Promise.all(promises);
                    fetchCalendar(); 
                } catch(e) { console.error(e); hideLoader(); }
            };

            document.getElementById('btn-block').addEventListener('click', () => toggleBlockStatus('block'));
            document.getElementById('btn-unblock').addEventListener('click', () => toggleBlockStatus('unblock'));
            document.getElementById('btn-sync').addEventListener('click', async () => {
                showLoader();
                try {
                    await fetch(`${apiBase}/force-sync`, { method: 'POST', headers: { 'X-WP-Nonce': nonce }});
                    alert('iCal Sync gestartet!'); fetchCalendar();
                } catch(e) { console.error(e); hideLoader(); }
            });

            togglePriceButtonVisibility(); 
            fetchCalendar();
        });
        </script>
        <?php
        return ob_get_clean();
    }
}