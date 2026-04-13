<?php
/**
 * Plugin Name: Stay4Fair Schema
 * Description: Apartment + Dynamic Event Schema + Organization + WebSite + WebPage + BreadcrumbList for Stay4Fair
 * Version: 2.3.0
 * Author: Stay4Fair
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Базовые настройки бренда.
 */
function s4f_schema_site_data() {
	return [
		'name'      => 'Stay4Fair',
		'url'       => 'https://stay4fair.com',
		'logo'      => 'https://stay4fair.com/wp-content/uploads/2025/10/short-logo-color.svg',
		'image'     => 'https://stay4fair.com/wp-content/uploads/2026/04/Banner-for-socialmedia.png',
		'sameAs'    => [],
		'telephone' => '',
		'email'     => '',
	];
}

/**
 * Текущий URL страницы.
 */
function s4f_schema_current_url() {
	$queried_id = get_queried_object_id();
	if ($queried_id) {
		return get_permalink($queried_id);
	}

	return home_url(add_query_arg([], $GLOBALS['wp']->request ?? ''));
}

/**
 * @id организации.
 */
function s4f_schema_org_id() {
	$site = s4f_schema_site_data();
	return trailingslashit($site['url']) . '#organization';
}

/**
 * @id сайта.
 */
function s4f_schema_website_id() {
	$site = s4f_schema_site_data();
	return trailingslashit($site['url']) . '#website';
}

/**
 * @id текущей WebPage.
 */
function s4f_schema_webpage_id($url = '') {
	$url = $url ?: s4f_schema_current_url();
	return trailingslashit($url) . '#webpage';
}

/**
 * Получаем описание для schema:
 * 1) excerpt
 * 2) fallback на контент
 */
function s4f_schema_get_description($post_id) {
	$post = get_post($post_id);

	if (!$post) {
		return '';
	}

	if (!empty($post->post_excerpt)) {
		return wp_strip_all_tags($post->post_excerpt);
	}

	if (!empty($post->post_content)) {
		$text = wp_strip_all_tags($post->post_content);
		$text = preg_replace('/\s+/', ' ', $text);
		return wp_trim_words($text, 35, '...');
	}

	return '';
}

/**
 * Пробуем получить цену из meta.
 */
function s4f_schema_get_price($post_id) {
	$keys = [
		'price',
		'_price',
		'base_price',
		'_base_price',
		'mphb_price',
		'_mphb_price',
		'owner_price_per_night',
	];

	foreach ($keys as $key) {
		$val = get_post_meta($post_id, $key, true);

		if ($val !== '' && $val !== null && is_numeric($val)) {
			return (float) $val;
		}
	}

	return null;
}

/**
 * GEO (фикс Hannover).
 */
function s4f_geo() {
	return [
		'@type'     => 'GeoCoordinates',
		'latitude'  => '52.3759',
		'longitude' => '9.7320',
	];
}

/**
 * Карта выставок.
 */
function s4f_event_map() {
	return [
		'domotex' => [
			'name'      => 'DOMOTEX',
			'organizer' => 'Deutsche Messe AG',
		],
		'hannover-messe' => [
			'name'      => 'HANNOVER MESSE',
			'organizer' => 'Deutsche Messe AG',
		],
		'interschutz' => [
			'name'      => 'INTERSCHUTZ',
			'organizer' => 'Deutsche Messe AG',
		],
		'iaa-transportation' => [
			'name'      => 'IAA Transportation',
			'organizer' => 'VDA',
		],
		'euroblech' => [
			'name'      => 'EuroBLECH',
			'organizer' => 'RX',
		],
		'eurotier' => [
			'name'      => 'EuroTier',
			'organizer' => 'DLG e.V.',
		],
	];
}

/**
 * Даты выставок по шаблону.
 */
function s4f_event_dates($event_name) {
	$year = date('Y');

	$dates = [
		'DOMOTEX'            => ['01-19', '01-22'],
		'HANNOVER MESSE'     => ['04-20', '04-24'],
		'INTERSCHUTZ'        => ['06-01', '06-06'],
		'IAA Transportation' => ['09-15', '09-20'],
		'EuroBLECH'          => ['10-20', '10-23'],
		'EuroTier'           => ['11-10', '11-13'],
	];

	if (!isset($dates[$event_name])) {
		return false;
	}

	return [
		'start' => $year . '-' . $dates[$event_name][0] . 'T09:00:00+02:00',
		'end'   => $year . '-' . $dates[$event_name][1] . 'T18:00:00+02:00',
	];
}

/**
 * Текущий slug.
 */
function s4f_get_slug() {
	$obj = get_queried_object();
	return ($obj && !empty($obj->post_name)) ? $obj->post_name : '';
}

/**
 * Определяем, является ли страница выставкой.
 */
function s4f_detect_event() {
	if (!is_page()) {
		return false;
	}

	$slug = s4f_get_slug();
	if (!$slug) {
		return false;
	}

	foreach (s4f_event_map() as $key => $event) {
		if (strpos($slug, $key) !== false) {
			return $event;
		}
	}

	return false;
}

/**
 * Fallback-картинка.
 */
function s4f_schema_get_fallback_image() {
	$site = s4f_schema_site_data();
	return $site['image'];
}

/**
 * Картинка текущего объекта с fallback.
 */
function s4f_schema_get_image($post_id) {
	$image = get_the_post_thumbnail_url($post_id, 'full');

	if (!$image) {
		$image = s4f_schema_get_fallback_image();
	}

	return $image;
}

/**
 * Печать JSON-LD.
 */
function s4f_print_json_ld($data, $class_name = '') {
	if (empty($data) || !is_array($data)) {
		return;
	}

	$class_attr = $class_name ? ' class="' . esc_attr($class_name) . '"' : '';

	echo "\n<script type=\"application/ld+json\"" . $class_attr . ">\n";
	echo wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
	echo "\n</script>\n";
}

/**
 * -------------------------
 * ORGANIZATION SCHEMA
 * -------------------------
 */
function s4f_output_organization_schema() {
	if (is_admin()) {
		return;
	}

	$site = s4f_schema_site_data();

	$data = [
		'@context' => 'https://schema.org',
		'@type'    => 'Organization',
		'@id'      => s4f_schema_org_id(),
		'name'     => $site['name'],
		'url'      => $site['url'],
		'logo'     => [
			'@type' => 'ImageObject',
			'url'   => $site['logo'],
		],
	];

	if (!empty($site['sameAs'])) {
		$data['sameAs'] = array_values($site['sameAs']);
	}

	if (!empty($site['telephone'])) {
		$data['telephone'] = $site['telephone'];
	}

	if (!empty($site['email'])) {
		$data['email'] = $site['email'];
	}

	s4f_print_json_ld($data, 's4f-organization-schema');
}

/**
 * -------------------------
 * WEBSITE SCHEMA + SEARCHACTION
 * -------------------------
 */
function s4f_output_website_schema() {
	if (is_admin()) {
		return;
	}

	$site = s4f_schema_site_data();

	$data = [
		'@context'   => 'https://schema.org',
		'@type'      => 'WebSite',
		'@id'        => s4f_schema_website_id(),
		'url'        => $site['url'],
		'name'       => $site['name'],
		'publisher'  => [
			'@id' => s4f_schema_org_id(),
		],
		'inLanguage' => 'en',
		'potentialAction' => [
			'@type'       => 'SearchAction',
			'target'      => trailingslashit($site['url']) . '?s={search_term_string}',
			'query-input' => 'required name=search_term_string',
		],
	];

	s4f_print_json_ld($data, 's4f-website-schema');
}

/**
 * -------------------------
 * WEBPAGE / COLLECTIONPAGE
 * -------------------------
 */
function s4f_output_webpage_schema() {
	if (is_admin()) {
		return;
	}

	$url   = s4f_schema_current_url();
	$title = '';
	$desc  = '';
	$image = '';
	$type  = 'WebPage';

	if (is_front_page()) {
		$type  = 'WebPage';
		$title = get_bloginfo('name');
		$desc  = get_bloginfo('description');
		$image = s4f_schema_get_fallback_image();
	} elseif (is_page()) {
		$id    = get_queried_object_id();
		$title = get_the_title($id);
		$desc  = s4f_schema_get_description($id) ?: $title;
		$image = s4f_schema_get_image($id);

		if (s4f_detect_event()) {
			$type = 'CollectionPage';
		}
	} elseif (is_singular('mphb_room_type')) {
		$id    = get_queried_object_id();
		$type  = 'WebPage';
		$title = get_the_title($id);
		$desc  = s4f_schema_get_description($id) ?: $title;
		$image = s4f_schema_get_image($id);
	} elseif (is_single()) {
		$id    = get_queried_object_id();
		$title = get_the_title($id);
		$desc  = s4f_schema_get_description($id) ?: $title;
		$image = s4f_schema_get_image($id);
	} else {
		return;
	}

	$data = [
		'@context'    => 'https://schema.org',
		'@type'       => $type,
		'@id'         => s4f_schema_webpage_id($url),
		'url'         => $url,
		'name'        => $title,
		'description' => $desc,
		'isPartOf'    => [
			'@id' => s4f_schema_website_id(),
		],
		'about'       => [
			'@id' => s4f_schema_org_id(),
		],
		'inLanguage'  => 'en',
	];

	if (!empty($image)) {
		$data['primaryImageOfPage'] = [
			'@type' => 'ImageObject',
			'url'   => $image,
		];
	}

	s4f_print_json_ld($data, 's4f-webpage-schema');
}

/**
 * -------------------------
 * BREADCRUMB HELPERS
 * -------------------------
 */
function s4f_breadcrumb_item($position, $name, $item = '') {
	$data = [
		'@type'    => 'ListItem',
		'position' => (int) $position,
		'name'     => wp_strip_all_tags($name),
	];

	if (!empty($item)) {
		$data['item'] = $item;
	}

	return $data;
}

/**
 * Хлебные крошки для текущей страницы.
 */
function s4f_get_breadcrumb_items() {
	$site_url = home_url('/');
	$items    = [];
	$position = 1;

	$items[] = s4f_breadcrumb_item($position++, 'Home', $site_url);

	if (is_singular('mphb_room_type')) {
		$trade_fairs_page = get_page_by_path('trade-fairs');
		if ($trade_fairs_page) {
			$items[] = s4f_breadcrumb_item(
				$position++,
				get_the_title($trade_fairs_page->ID),
				get_permalink($trade_fairs_page->ID)
			);
		}

		$items[] = s4f_breadcrumb_item(
			$position++,
			get_the_title(get_queried_object_id()),
			get_permalink(get_queried_object_id())
		);

		return $items;
	}

	if (s4f_detect_event()) {
		$trade_fairs_page = get_page_by_path('trade-fairs');
		if ($trade_fairs_page) {
			$items[] = s4f_breadcrumb_item(
				$position++,
				get_the_title($trade_fairs_page->ID),
				get_permalink($trade_fairs_page->ID)
			);
		}

		$items[] = s4f_breadcrumb_item(
			$position++,
			get_the_title(get_queried_object_id()),
			get_permalink(get_queried_object_id())
		);

		return $items;
	}

	if (is_page()) {
		$current_id = get_queried_object_id();
		$parents    = array_reverse(get_post_ancestors($current_id));

		foreach ($parents as $parent_id) {
			$items[] = s4f_breadcrumb_item(
				$position++,
				get_the_title($parent_id),
				get_permalink($parent_id)
			);
		}

		$items[] = s4f_breadcrumb_item(
			$position++,
			get_the_title($current_id),
			get_permalink($current_id)
		);

		return $items;
	}

	if (is_single()) {
		$items[] = s4f_breadcrumb_item(
			$position++,
			get_the_title(get_queried_object_id()),
			get_permalink(get_queried_object_id())
		);

		return $items;
	}

	return $items;
}

/**
 * -------------------------
 * BREADCRUMB SCHEMA
 * -------------------------
 */
function s4f_output_breadcrumb_schema() {
	if (is_admin()) {
		return;
	}

	if (!(is_page() || is_single() || is_singular('mphb_room_type'))) {
		return;
	}

	$items = s4f_get_breadcrumb_items();
	if (count($items) < 2) {
		return;
	}

	$data = [
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $items,
	];

	s4f_print_json_ld($data, 's4f-breadcrumb-schema');
}

/**
 * -------------------------
 * APARTMENT SCHEMA
 * -------------------------
 */
function s4f_output_apartment_schema() {
	if (!is_singular('mphb_room_type')) {
		return;
	}

	$id  = get_queried_object_id();
	$url = get_permalink($id);

	if (!$id) {
		return;
	}

	$data = [
		'@context'          => 'https://schema.org',
		'@type'             => 'Apartment',
		'@id'               => trailingslashit($url) . '#apartment',
		'name'              => get_the_title($id),
		'description'       => s4f_schema_get_description($id) ?: get_the_title($id),
		'url'               => $url,
		'mainEntityOfPage'  => [
			'@id' => s4f_schema_webpage_id($url),
		],
		'isPartOf'          => [
			'@id' => s4f_schema_website_id(),
		],
		'address'           => [
			'@type'           => 'PostalAddress',
			'addressLocality' => 'Hannover',
			'addressCountry'  => 'DE',
		],
		'geo'               => s4f_geo(),
	];

	$image = s4f_schema_get_image($id);
	if (!empty($image)) {
		$data['image'] = [$image];
	}

	$price = s4f_schema_get_price($id);
	if ($price) {
		$data['offers'] = [
			'@type'         => 'Offer',
			'priceCurrency' => 'EUR',
			'price'         => (float) $price,
			'availability'  => 'https://schema.org/InStock',
			'url'           => $url,
		];
	}

	s4f_print_json_ld($data, 's4f-apartment-schema');
}

/**
 * -------------------------
 * EVENT SCHEMA
 * -------------------------
 */
function s4f_output_event_schema() {
	$event = s4f_detect_event();
	if (!$event) {
		return;
	}

	$id  = get_queried_object_id();
	$url = get_permalink($id);

	if (!$id) {
		return;
	}

	$dates = s4f_event_dates($event['name']);
	if (!$dates) {
		return;
	}

	$image = s4f_schema_get_image($id);

	$data = [
		'@context'            => 'https://schema.org',
		'@type'               => 'Event',
		'@id'                 => trailingslashit($url) . '#event',
		'name'                => get_the_title($id),
		'startDate'           => $dates['start'],
		'endDate'             => $dates['end'],
		'eventStatus'         => 'https://schema.org/EventScheduled',
		'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
		'url'                 => $url,
		'description'         => s4f_schema_get_description($id) ?: get_the_title($id),
		'image'               => [$image],
		'mainEntityOfPage'    => [
			'@id' => s4f_schema_webpage_id($url),
		],
		'organizer'           => [
			'@id'   => s4f_schema_org_id(),
			'@type' => 'Organization',
			'name'  => $event['organizer'],
		],
		'location'            => [
			'@type'   => 'Place',
			'name'    => 'Hannover Exhibition Center',
			'address' => [
				'@type'           => 'PostalAddress',
				'streetAddress'   => 'Messegelände',
				'postalCode'      => '30521',
				'addressLocality' => 'Hannover',
				'addressCountry'  => 'DE',
			],
		],
	];

	s4f_print_json_ld($data, 's4f-event-schema');
}

/**
 * -------------------------
 * HOOKS
 * -------------------------
 */
add_action('wp_head', 's4f_output_organization_schema', 20);
add_action('wp_head', 's4f_output_website_schema', 21);
add_action('wp_head', 's4f_output_webpage_schema', 22);
add_action('wp_head', 's4f_output_breadcrumb_schema', 23);
add_action('wp_head', 's4f_output_apartment_schema', 30);
add_action('wp_head', 's4f_output_event_schema', 31);