<?php
declare(strict_types=1);

/**
 * Plugin Name: BSBT – Owner Dashboard (Total 3D Aligned)
 * Version: 18.3.0
 * RU: Дашборд владельца. Исправлен баг ложной пульсации "Neue Anfrage!" для iCal бронирований.
 * EN: Owner dashboard. Fixed false "Neue Anfrage!" pulse for iCal imported bookings.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BSBT_Aligned_3D_Dashboard {

    public function __construct() {
        add_shortcode( 'bsbt_owner_dashboard', [ $this, 'render' ] );
    }

    public function render() {

        if ( ! is_user_logged_in() ) {
            $current_url = home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ) );
            wp_safe_redirect( site_url('/owner-login/?redirect_to=' . urlencode( $current_url ) ) );
            exit;
        }

        $user = wp_get_current_user();
        $navy = '#082567'; 
        $gold = '#E0B849'; 
        
        $apts = get_posts([
            'post_type'      => 'mphb_room_type',
            'author'         => $user->ID,
            'posts_per_page' => 1,
            'post_status'    => 'any'
        ]);
        $apartments_count = count($apts);

        $profile_action_required = false;
        if (class_exists('\StayFlow\CPT\OwnerProfileProvider')) {
            $profile_action_required = \StayFlow\CPT\OwnerProfileProvider::isActionRequired($user->ID);
        }

        $has_new_bookings = false;
        if (class_exists('WP_Query')) {
            try {
                $recent_bookings = new WP_Query([
                    'post_type'      => 'mphb_booking',
                    'post_status'    => 'any',
                    'posts_per_page' => 20, 
                    'fields'         => 'ids',
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ]);
                
                foreach ($recent_bookings->posts as $bid) {
                    $owner_id = (int) get_post_meta($bid, 'bsbt_owner_id', true);
                    $is_imported = false; // Флаг для iCal броней
                    
                    if (function_exists('MPHB')) {
                        $b = MPHB()->getBookingRepository()->findById($bid);
                        if ($b) {
                            if (!$owner_id && !empty($b->getReservedRooms())) {
                                $owner_id = (int) get_post_meta($b->getReservedRooms()[0]->getRoomTypeId(), 'bsbt_owner_id', true);
                            }
                            // Проверяем, импортирована ли бронь
                            $is_imported = $b->isImported();
                        }
                    }
                    
                    if ($owner_id === $user->ID) {
                        // RU: Если бронь пришла из iCal (Airbnb, Booking), мы её игнорируем,
                        // так как она не требует ручного подтверждения владельцем.
                        if ($is_imported) {
                            continue;
                        }
                        
                        $decision = get_post_meta($bid, '_bsbt_owner_decision', true);
                        if (empty($decision)) {
                            // Нашли новую ручную заявку, которую надо подтвердить
                            $has_new_bookings = true;
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }
        
        ob_start(); ?>

        <style>
            .bsbt-3d-btn {
                position: relative !important; overflow: hidden !important; border-radius: 10px !important; border: none !important;
                box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important;
                transition: all 0.25s ease !important; cursor: pointer !important; display: inline-block; padding: 12px 24px;
                background-color: <?php echo $gold; ?> !important; color: <?php echo $navy; ?> !important;
                background-image: linear-gradient(180deg, rgba(255,255,255,0.35) 0%, rgba(255,255,255,0.1) 55%, rgba(0,0,0,0.18) 100%) !important;
                background-blend-mode: overlay; font-weight: 700; text-decoration: none; text-align: center;
            }
            .bsbt-3d-btn::before {
                content: "" !important; position: absolute !important; top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important;
                background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important;
                transform: scaleY(0.48) !important; filter: blur(5px) !important; opacity: 0.55 !important; z-index: 1 !important;
            }
            .bsbt-3d-btn:hover { background-color: <?php echo $navy; ?> !important; color: <?php echo $gold; ?> !important; transform: translateY(-2px) !important; }

            .bsbt-viewport { padding-top: 18vh; padding-bottom: 40px; background: #ffffff; width: 100%; box-sizing: border-box; overflow-x: hidden; }
            #bsbt-container { font-family: 'Segoe UI', Roboto, sans-serif; max-width: 1150px; margin: 0 auto; padding: 0 25px; box-sizing: border-box; }

            .bsbt-header-row { display: flex !important; justify-content: space-between !important; align-items: center !important; margin-bottom: 15px !important; }
            .bsbt-title { font-size: 32px; font-weight: 800; margin: 0; color: <?php echo $navy; ?>; }

            .bsbt-lang-box { border: 1px solid #f1f5f9; padding: 5px 15px; border-radius: 20px; font-size: 11px; color: #94a3b8; font-weight: 600; background: #fff; }
            .bsbt-header-right { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
            .bsbt-partner-tag { background: <?php echo $navy; ?>; color: #fff; padding: 7px 15px; border-radius: 8px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }

            .bsbt-grid { display: grid !important; grid-template-columns: repeat(3, 1fr) !important; gap: 20px !important; margin: 30px 0 !important; }

            .bsbt-glass-card { background: #ffffff !important; border: 1px solid #f1f5f9 !important; border-radius: 24px !important; padding: 30px 15px !important; text-align: center !important; text-decoration: none !important; transition: 0.4s !important; display: flex !important; flex-direction: column !important; align-items: center !important; box-shadow: 0 4px 20px rgba(0,0,0,0.03) !important; }
            .bsbt-glass-card:hover { transform: translateY(-8px) !important; border-color: <?php echo $gold; ?> !important; box-shadow: 0 20px 40px rgba(8, 37, 103, 0.1) !important; }

            .sf-empty-tile-pulse { box-shadow: 0 0 15px rgba(224, 184, 73, 0.8) !important; animation: pulse-gold 2s infinite; border: 2px solid #E0B849 !important; }
            .sf-danger-tile-pulse { box-shadow: 0 0 15px rgba(239, 68, 68, 0.5) !important; animation: pulse-red 2s infinite; border: 2px solid #ef4444 !important; }
            .sf-success-tile-pulse { box-shadow: 0 0 15px rgba(37, 211, 102, 0.5) !important; animation: pulse-green 2s infinite; border: 2px solid #25D366 !important; }
            
            @keyframes pulse-gold { 0% { box-shadow: 0 0 0 0 rgba(224, 184, 73, 0.7); } 70% { box-shadow: 0 0 0 15px rgba(224, 184, 73, 0); } 100% { box-shadow: 0 0 0 0 rgba(224, 184, 73, 0); } }
            @keyframes pulse-red { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.5); } 70% { box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }
            @keyframes pulse-green { 0% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.6); } 70% { box-shadow: 0 0 0 15px rgba(37, 211, 102, 0); } 100% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0); } }

            .bsbt-bubble-icon { width: 70px; height: 70px; background: radial-gradient(circle at 30% 30%, #ffffff 0%, #f1f5f9 100%); border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 32px; margin-bottom: 15px; position: relative; }
            .bsbt-red-dot { position: absolute; top: -5px; right: -5px; width: 16px; height: 16px; background-color: #ef4444; border-radius: 50%; border: 3px solid #fff; }

            @media (max-width: 767px) {
                .bsbt-header-row { flex-direction: column !important; align-items: flex-start !important; gap: 15px !important; }
                .bsbt-header-right { align-items: flex-start !important; width: 100%; }
                .bsbt-grid { grid-template-columns: repeat(2, 1fr) !important; gap: 15px !important; }
                .bsbt-glass-card { padding: 25px 10px !important; }
                .bsbt-bubble-icon { width: 60px; height: 60px; font-size: 26px; }
            }
        </style>

        <div class="bsbt-viewport">
            <div id="bsbt-container">
                
                <div class="bsbt-header-row">
                    <h2 class="bsbt-title">Owner Dashboard</h2>
                    <div class="bsbt-lang-box">🌐 DE / EN / RU</div>
                    <div class="bsbt-header-right">
                        <div class="bsbt-partner-tag">Stay4Fair Partner</div>
                        <?php echo do_shortcode('[bsbt_logout_button]'); ?>
                    </div>
                </div>

                <?php if (isset($_GET['apt_created']) && $_GET['apt_created'] == '1'): ?>
                    <div style="background: #fdf8ed; color: #082567; padding: 15px 20px; border-radius: 12px; margin-bottom: 25px; border: 2px solid #E0B849; display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 15px rgba(224, 184, 73, 0.15);">
                        <div style="font-size: 24px;">🎉</div>
                        <div>
                            <strong style="display: block; font-size: 16px;">Wunderbar!</strong>
                            <span style="font-size: 14px; color: #334155;">Ihr Apartment wurde erfolgreich eingereicht und befindet sich nun in der Prüfung.</span>
                        </div>
                    </div>
                <?php endif; ?>

                <p style="font-size: 15px; color: #64748b; margin: 0 0 30px 0;">
                    Willkommen zurück, 
                    <span style="font-weight: 700; color: <?php echo $navy; ?>;">
                        <?php echo esc_html($user->display_name); ?>
                    </span>
                </p>

                <div class="bsbt-grid">
                    <?php
                    $items = [
                        ['Meine Buchungen', '📅', '/owner-bookings/'],
                        ['Apartments', '🏢', '#'], 
                        ['Finanzen', '💳', '/owner-dashboard-finanzen/'],
                        ['Kalender', '🗓️', '/owner-calendar/'],
                        ['Mein Profil', '👤', '/owner-profile/'], 
                        ['Support', '🎧', '/owner-support/'] 
                    ];                    
                    
                    foreach ($items as $item) : 
                        $link = $item[2];
                        $class = 'bsbt-glass-card';
                        $action_text = 'Öffnen';
                        $show_red_dot = false;

                        if ($item[0] === 'Apartments') {
                            if ($apartments_count === 0) {
                                $link = home_url('/add-apartment/');
                                $class = 'bsbt-glass-card sf-empty-tile-pulse';
                                $action_text = 'Erstes hinzufügen';
                            } else {
                                $link = home_url('/owner-apartments/');
                                $class = 'bsbt-glass-card';
                                $action_text = 'Öffnen / Neu';
                            }
                        }

                        if ($item[0] === 'Mein Profil' && $profile_action_required) {
                            $class = 'bsbt-glass-card sf-danger-tile-pulse';
                            $action_text = 'Daten fehlen (DAC7)';
                            $show_red_dot = true;
                        }

                        if ($item[0] === 'Meine Buchungen' && $has_new_bookings) {
                            $class = 'bsbt-glass-card sf-success-tile-pulse';
                            $action_text = 'Neue Anfrage!';
                            $show_red_dot = true;
                        }
                    ?>
                        <a href="<?php echo esc_url($link); ?>" class="<?php echo esc_attr($class); ?>">
                            <div class="bsbt-bubble-icon">
                                <?php echo $item[1]; ?>
                                <?php if ($show_red_dot): ?>
                                    <div class="bsbt-red-dot" title="Aktion erforderlich"></div>
                                <?php endif; ?>
                            </div>
                            <h4 style="margin:0 0 5px 0; font-size: 18px; color: <?php echo $navy; ?>;"><?php echo $item[0]; ?></h4>
                            
                            <?php if ($item[0] === 'Mein Profil' && $profile_action_required): ?>
                                <span style="font-size: 10px; color: #ef4444; font-weight: 700; text-transform: uppercase;"><?php echo $action_text; ?></span>
                            <?php elseif ($item[0] === 'Meine Buchungen' && $has_new_bookings): ?>
                                <span style="font-size: 10px; color: #25D366; font-weight: 700; text-transform: uppercase;"><?php echo $action_text; ?></span>
                            <?php elseif ($item[0] === 'Apartments' && $apartments_count === 0): ?>
                                <span style="font-size: 10px; color: <?php echo $gold; ?>; font-weight: 700; text-transform: uppercase;"><?php echo $action_text; ?></span>
                            <?php else: ?>
                                <span style="font-size: 10px; color: #cbd5e1; font-weight: 700; text-transform: uppercase;"><?php echo $action_text; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>

        <?php
        return ob_get_clean();
    }
}

new BSBT_Aligned_3D_Dashboard();