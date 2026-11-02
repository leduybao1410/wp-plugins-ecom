<?php
/** Persistent FAQ records, compiled context bundles and seed import. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Epic_Support_Faq_Store {
	const DB_VERSION = '1.1';
	const DB_VERSION_OPTION = 'epic_support_faq_db_version';
	const LOCALES = array( 'vi', 'en', 'ru', 'hi', 'zh', 'ko', 'ja' );
	const GROUPS = array( 'home', 'coffee', 'visit', 'contact', 'roastery', 'wholesale', 'assistant' );
	const STATUSES = array( 'draft', 'published' );
	const MAX_QUESTION = 300;
	const MAX_ANSWER = 2000;
	const TRANSLATE_TARGETS = array( 'en', 'ru', 'hi', 'zh', 'ko', 'ja' );
	const DEFAULT_MAX_BYTES = 60000;
	const DEFAULT_MAX_ITEMS = 60;

	public static function tables() {
		global $wpdb;
		return array(
			'faqs'         => $wpdb->prefix . 'epic_support_faqs',
			'translations' => $wpdb->prefix . 'epic_support_faq_translations',
			'bundles'      => $wpdb->prefix . 'epic_support_faq_bundles',
		);
	}

	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install( false );
			// Fill translations added to the bundled seed since the last version
			// without overwriting content an administrator already edited.
			self::import_seed( false );
		}
	}

	public static function install( $import_seed = true ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables = self::tables();
		$collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$tables['faqs']} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			seed_key varchar(64) NULL,
			faq_group varchar(24) NOT NULL,
			position int NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'draft',
			site_visible tinyint(1) NOT NULL DEFAULT 1,
			bot_visible tinyint(1) NOT NULL DEFAULT 1,
			source varchar(16) NOT NULL DEFAULT 'manual',
			revision int unsigned NOT NULL DEFAULT 1,
			created_by bigint unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			published_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY seed_key (seed_key),
			KEY group_status (faq_group, status, position)
		) $collate;" );
		dbDelta( "CREATE TABLE {$tables['translations']} (
			faq_id bigint unsigned NOT NULL,
			locale varchar(8) NOT NULL,
			question varchar(300) NOT NULL,
			answer text NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (faq_id, locale),
			KEY locale_question (locale, question)
		) $collate;" );
		dbDelta( "CREATE TABLE {$tables['bundles']} (
			lang varchar(8) NOT NULL,
			version char(16) NOT NULL,
			payload longtext NOT NULL,
			item_count int unsigned NOT NULL DEFAULT 0,
			bytes int unsigned NOT NULL DEFAULT 0,
			truncated tinyint(1) NOT NULL DEFAULT 0,
			compiled_at datetime NOT NULL,
			PRIMARY KEY  (lang)
		) $collate;" );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		if ( $import_seed && ! self::faq_count() ) { self::import_seed( false ); }
	}

	private static function faq_count() {
		global $wpdb;
		$tables = self::tables();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['faqs']}" );
	}

	private static function now() { return gmdate( 'Y-m-d H:i:s' ); }

	private static function audit( $action, $id, $fields, $outcome ) {
		if ( ! class_exists( 'Epic_Admin_Dashboard' ) ) { return; }
		global $wpdb;
		$tables = Epic_Admin_Dashboard::tables();
		if ( empty( $tables['audit'] ) ) { return; }
		$session = isset( $GLOBALS['epic_admin_dashboard_session'] ) ? $GLOBALS['epic_admin_dashboard_session'] : array( 'user_id' => get_current_user_id() );
		$wpdb->insert( $tables['audit'], array(
			'user_id'   => (int) $session['user_id'],
			'action'    => sanitize_key( $action ),
			'resource'  => 'faq',
			'record_id' => (string) absint( $id ),
			'fields'    => wp_json_encode( array_values( array_unique( array_map( 'sanitize_key', $fields ) ) ) ),
			'outcome'   => sanitize_key( $outcome ),
			'created_at' => self::now(),
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	}

	public static function max_bytes() {
		$value = absint( get_option( 'epic_support_faq_context_max_bytes', self::DEFAULT_MAX_BYTES ) );
		return max( 4000, min( 200000, $value ?: self::DEFAULT_MAX_BYTES ) );
	}

	public static function max_items() {
		$value = absint( get_option( 'epic_support_faq_context_max_items', self::DEFAULT_MAX_ITEMS ) );
		return max( 10, min( 100, $value ?: self::DEFAULT_MAX_ITEMS ) );
	}

	/** @return array<string, array{question:string, answer:string}> */
	private static function sanitize_translations( $translations ) {
		$out = array();
		if ( ! is_array( $translations ) ) { return $out; }
		foreach ( self::LOCALES as $locale ) {
			if ( ! isset( $translations[ $locale ] ) || ! is_array( $translations[ $locale ] ) ) { continue; }
			$question = sanitize_textarea_field( (string) ( $translations[ $locale ]['question'] ?? '' ) );
			$answer = sanitize_textarea_field( (string) ( $translations[ $locale ]['answer'] ?? '' ) );
			if ( '' === trim( $question ) || '' === trim( $answer ) ) { $out[ $locale ] = array( 'question' => '', 'answer' => '' ); continue; }
			$out[ $locale ] = array(
				'question' => function_exists( 'mb_substr' ) ? mb_substr( $question, 0, self::MAX_QUESTION, 'UTF-8' ) : substr( $question, 0, self::MAX_QUESTION ),
				'answer'   => function_exists( 'mb_substr' ) ? mb_substr( $answer, 0, self::MAX_ANSWER, 'UTF-8' ) : substr( $answer, 0, self::MAX_ANSWER ),
			);
		}
		return $out;
	}

	private static function missing_locales( $translations ) {
		$missing = array();
		foreach ( self::LOCALES as $locale ) {
			if ( empty( $translations[ $locale ]['question'] ) || empty( $translations[ $locale ]['answer'] ) ) { $missing[] = $locale; }
		}
		return $missing;
	}

	private static function merge_missing_translations( $id, $translations ) {
		global $wpdb;
		$tables = self::tables();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT locale, question, answer FROM {$tables['translations']} WHERE faq_id = %d", $id ), ARRAY_A );
		$have = array();
		foreach ( (array) $rows as $row ) {
			if ( '' !== $row['question'] && '' !== $row['answer'] ) { $have[ $row['locale'] ] = true; }
		}
		$fill = array();
		foreach ( $translations as $locale => $row ) {
			if ( empty( $have[ $locale ] ) && $row['question'] && $row['answer'] ) { $fill[ $locale ] = $row; }
		}
		if ( $fill ) { self::write_translations( $id, $fill ); }
	}

	private static function write_translations( $id, $translations ) {		global $wpdb;
		$tables = self::tables();
		$now = self::now();
		foreach ( self::LOCALES as $locale ) {
			if ( ! isset( $translations[ $locale ] ) ) { continue; }
			$row = $translations[ $locale ];
			if ( '' === $row['question'] || '' === $row['answer'] ) {
				$wpdb->delete( $tables['translations'], array( 'faq_id' => $id, 'locale' => $locale ), array( '%d', '%s' ) );
				continue;
			}
			$wpdb->replace( $tables['translations'], array(
				'faq_id'     => (int) $id,
				'locale'     => $locale,
				'question'   => $row['question'],
				'answer'     => $row['answer'],
				'updated_at' => $now,
			), array( '%d', '%s', '%s', '%s', '%s' ) );
		}
	}

	public static function get_faq( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) { return null; }
		$tables = self::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['faqs']} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) { return null; }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT locale, question, answer, updated_at FROM {$tables['translations']} WHERE faq_id = %d", $id ), ARRAY_A );
		$row['translations'] = array();
		foreach ( $rows as $translation ) {
			$row['translations'][ $translation['locale'] ] = array( 'question' => $translation['question'], 'answer' => $translation['answer'], 'updated_at' => $translation['updated_at'] );
		}
		return self::format_faq( $row );
	}

	private static function format_faq( $row ) {
		$translations = isset( $row['translations'] ) && is_array( $row['translations'] ) ? $row['translations'] : array();
		$filled = array();
		$missing = array();
		foreach ( self::LOCALES as $locale ) {
			if ( ! empty( $translations[ $locale ]['question'] ) && ! empty( $translations[ $locale ]['answer'] ) ) { $filled[] = $locale; } else { $missing[] = $locale; }
		}
		$preferred = isset( $translations['vi'] ) ? $translations['vi'] : ( isset( $translations['en'] ) ? $translations['en'] : array( 'question' => '', 'answer' => '' ) );
		return array(
			'id'             => (int) $row['id'],
			'seed_key'       => $row['seed_key'] ?? null,
			'group'          => (string) $row['faq_group'],
			'position'       => (int) $row['position'],
			'status'         => (string) $row['status'],
			'site_visible'   => (bool) $row['site_visible'],
			'bot_visible'    => (bool) $row['bot_visible'],
			'source'         => (string) $row['source'],
			'revision'       => (int) $row['revision'],
			'created_at'     => (string) $row['created_at'],
			'updated_at'     => (string) $row['updated_at'],
			'published_at'   => $row['published_at'] ?: null,
			'question'       => $preferred['question'],
			'answer'         => $preferred['answer'],
			'filled_locales' => $filled,
			'missing_locales'=> $missing,
			'translations'   => $translations,
		);
	}

	public static function list_faqs( $args = array() ) {
		global $wpdb;
		$tables = self::tables();
		$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$group = isset( $args['group'] ) ? sanitize_key( (string) $args['group'] ) : '';
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$lang = isset( $args['lang'] ) && in_array( $args['lang'], self::LOCALES, true ) ? $args['lang'] : 'vi';
		$page = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, absint( $args['per_page'] ?? 25 ) ?: 25 ) );
		$where = array( '1=1' );
		$values = array();
		if ( $status && in_array( $status, self::STATUSES, true ) ) { $where[] = 'f.status = %s'; $values[] = $status; }
		if ( $group && in_array( $group, self::GROUPS, true ) ) { $where[] = 'f.faq_group = %s'; $values[] = $group; }
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = 'EXISTS (SELECT 1 FROM ' . $tables['translations'] . ' ts WHERE ts.faq_id = f.id AND (ts.question LIKE %s OR ts.answer LIKE %s))';
			$values[] = $like;
			$values[] = $like;
		}
		$where_sql = implode( ' AND ', $where );
		$total_sql = "SELECT COUNT(*) FROM {$tables['faqs']} f WHERE $where_sql";
		$total = (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $total_sql, $values ) ) : $wpdb->get_var( $total_sql ) );
		if ( $wpdb->last_error ) { return new WP_Error( 'faq_store_unavailable', 'FAQ records could not be loaded.', array( 'status' => 503 ) ); }
		$list_sql = "SELECT f.* FROM {$tables['faqs']} f WHERE $where_sql ORDER BY FIELD(f.faq_group, 'home', 'coffee', 'visit', 'contact', 'roastery', 'wholesale', 'assistant'), f.position ASC, f.id ASC LIMIT %d OFFSET %d";
		$params = array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $params ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) { return new WP_Error( 'faq_store_unavailable', 'FAQ records could not be loaded.', array( 'status' => 503 ) ); }
		$ids = array_map( static function ( $row ) { return (int) $row['id']; }, $rows );
		$translations = array();
		if ( $ids ) {
			$in = implode( ',', $ids );
			$translation_rows = $wpdb->get_results( $wpdb->prepare( "SELECT faq_id, locale, question, answer, updated_at FROM {$tables['translations']} WHERE faq_id IN ($in) AND locale = %s", $lang ), ARRAY_A );
			foreach ( $translation_rows as $translation ) { $translations[ (int) $translation['faq_id'] ][ $translation['locale'] ] = array( 'question' => $translation['question'], 'answer' => $translation['answer'], 'updated_at' => $translation['updated_at'] ); }
			$all_rows = $wpdb->get_results( "SELECT faq_id, locale, question, answer FROM {$tables['translations']} WHERE faq_id IN ($in)", ARRAY_A );
			$locale_map = array();
			foreach ( $all_rows as $translation ) {
				if ( '' === $translation['question'] || '' === $translation['answer'] ) { continue; }
				$locale_map[ (int) $translation['faq_id'] ][ $translation['locale'] ] = array( 'question' => $translation['question'], 'answer' => $translation['answer'] );
			}
		} else {
			$locale_map = array();
		}
		$items = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$row['translations'] = isset( $translations[ $id ] ) ? $translations[ $id ] : array();
			$item = self::format_faq( $row );
			$available = isset( $locale_map[ $id ] ) ? $locale_map[ $id ] : array();
			$fill = isset( $available[ $lang ] ) ? $available[ $lang ] : ( isset( $available['vi'] ) ? $available['vi'] : ( isset( $available['en'] ) ? $available['en'] : null ) );
			$item['question'] = $fill ? $fill['question'] : '';
			$item['answer'] = $fill ? $fill['answer'] : '';
			$item['filled_locales'] = array_values( array_intersect( self::LOCALES, array_keys( $available ) ) );
			$item['missing_locales'] = array_values( array_diff( self::LOCALES, $item['filled_locales'] ) );
			unset( $item['translations'] );
			$items[] = $item;
		}
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'total_pages' => (int) ceil( $total / $per_page ) );
	}

	public static function save_faq( $data, $id = 0, $revision = 0, $user_id = 0 ) {
		global $wpdb;
		$tables = self::tables();
		$id = absint( $id );
		$group = isset( $data['group'] ) ? sanitize_key( (string) $data['group'] ) : '';
		if ( ! in_array( $group, self::GROUPS, true ) ) { return new WP_Error( 'invalid_faq_group', 'Choose a supported FAQ group.', array( 'status' => 400 ) ); }
		$translations = self::sanitize_translations( $data['translations'] ?? array() );
		$site_visible = array_key_exists( 'site_visible', $data ) ? (bool) $data['site_visible'] : true;
		$bot_visible = array_key_exists( 'bot_visible', $data ) ? (bool) $data['bot_visible'] : true;
		$position = isset( $data['position'] ) ? absint( $data['position'] ) : 0;
		$now = self::now();
		if ( ! $id ) {
			$has_complete = false;
			foreach ( $translations as $translation ) { if ( $translation['question'] && $translation['answer'] ) { $has_complete = true; break; } }
			if ( ! $has_complete ) { return new WP_Error( 'invalid_faq_translations', 'Add at least one complete language before saving.', array( 'status' => 400 ) ); }
			$inserted = $wpdb->insert( $tables['faqs'], array(
				'faq_group'   => $group,
				'position'    => $position,
				'status'      => 'draft',
				'site_visible'=> $site_visible ? 1 : 0,
				'bot_visible' => $bot_visible ? 1 : 0,
				'source'      => 'manual',
				'revision'    => 1,
				'created_by'  => (int) $user_id,
				'created_at'  => $now,
				'updated_at'  => $now,
			), array( '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s' ) );
			if ( false === $inserted ) { return new WP_Error( 'faq_store_unavailable', 'FAQ could not be saved.', array( 'status' => 503 ) ); }
			$id = (int) $wpdb->insert_id;
			self::write_translations( $id, $translations );
			self::audit( 'faq.create', $id, array_keys( $translations ), 'success' );
			return self::get_faq( $id );
		}
		$existing = self::get_faq( $id );
		if ( ! $existing ) { return new WP_Error( 'faq_not_found', 'FAQ was not found.', array( 'status' => 404 ) ); }
		if ( (int) $revision !== (int) $existing['revision'] ) { return new WP_Error( 'faq_revision_conflict', 'This FAQ changed. Reload before saving.', array( 'status' => 409 ) ); }
		if ( 'published' === $existing['status'] ) {
			$merged = array_merge( $existing['translations'], $translations );
			$missing = self::missing_locales( $merged );
			if ( $missing ) { return new WP_Error( 'faq_incomplete_translations', 'Unpublish this FAQ before removing a translation. Missing: ' . implode( ', ', $missing ) . '.', array( 'status' => 400 ) ); }
		}
		$updated = $wpdb->update( $tables['faqs'], array(
			'faq_group'   => $group,
			'position'    => $position,
			'site_visible'=> $site_visible ? 1 : 0,
			'bot_visible' => $bot_visible ? 1 : 0,
			'revision'    => (int) $existing['revision'] + 1,
			'updated_at'  => $now,
		), array( 'id' => $id, 'revision' => (int) $existing['revision'] ), array( '%s', '%d', '%d', '%d', '%d', '%s' ), array( '%d', '%d' ) );
		if ( 1 !== $updated ) { return new WP_Error( 'faq_revision_conflict', 'This FAQ changed. Reload before saving.', array( 'status' => 409 ) ); }
		self::write_translations( $id, $translations );
		if ( 'published' === $existing['status'] ) { self::rebuild_bundles(); }
		self::audit( 'faq.update', $id, array_keys( $translations ), 'success' );
		return self::get_faq( $id );
	}

	public static function publish_faq( $id, $revision ) {
		$faq = self::get_faq( $id );
		if ( ! $faq ) { return new WP_Error( 'faq_not_found', 'FAQ was not found.', array( 'status' => 404 ) ); }
		if ( (int) $revision !== (int) $faq['revision'] ) { return new WP_Error( 'faq_revision_conflict', 'This FAQ changed. Reload before publishing.', array( 'status' => 409 ) ); }
		if ( $faq['missing_locales'] ) { return new WP_Error( 'faq_incomplete_translations', 'Translate every language before publishing. Missing: ' . implode( ', ', $faq['missing_locales'] ) . '.', array( 'status' => 400 ) ); }
		global $wpdb;
		$tables = self::tables();
		$now = self::now();
		$updated = $wpdb->update( $tables['faqs'], array( 'status' => 'published', 'published_at' => $now, 'revision' => (int) $faq['revision'] + 1, 'updated_at' => $now ), array( 'id' => (int) $faq['id'], 'revision' => (int) $faq['revision'] ), array( '%s', '%s', '%d', '%s' ), array( '%d', '%d' ) );
		if ( 1 !== $updated ) { return new WP_Error( 'faq_revision_conflict', 'This FAQ changed. Reload before publishing.', array( 'status' => 409 ) ); }
		$bundles = self::rebuild_bundles();
		self::audit( 'faq.publish', $faq['id'], array( 'status' ), 'success' );
		return array( 'faq' => self::get_faq( $faq['id'] ), 'bundles' => $bundles );
	}

	public static function unpublish_faq( $id, $revision ) {
		$faq = self::get_faq( $id );
		if ( ! $faq ) { return new WP_Error( 'faq_not_found', 'FAQ was not found.', array( 'status' => 404 ) ); }
		if ( (int) $revision !== (int) $faq['revision'] ) { return new WP_Error( 'faq_revision_conflict', 'This FAQ changed. Reload before unpublishing.', array( 'status' => 409 ) ); }
		global $wpdb;
		$tables = self::tables();
		$now = self::now();
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$tables['faqs']} SET status = 'draft', published_at = NULL, revision = revision + 1, updated_at = %s WHERE id = %d AND revision = %d",
			$now, (int) $faq['id'], (int) $faq['revision']
		) );
		if ( 1 !== $updated ) { return new WP_Error( 'faq_revision_conflict', 'This FAQ changed. Reload before unpublishing.', array( 'status' => 409 ) ); }
		$bundles = self::rebuild_bundles();
		self::audit( 'faq.unpublish', $faq['id'], array( 'status' ), 'success' );
		return array( 'faq' => self::get_faq( $faq['id'] ), 'bundles' => $bundles );
	}

	public static function delete_faq( $id, $revision ) {
		$faq = self::get_faq( $id );
		if ( ! $faq ) { return new WP_Error( 'faq_not_found', 'FAQ was not found.', array( 'status' => 404 ) ); }
		if ( (int) $revision !== (int) $faq['revision'] ) { return new WP_Error( 'faq_revision_conflict', 'This FAQ changed. Reload before deleting.', array( 'status' => 409 ) ); }
		global $wpdb;
		$tables = self::tables();
		$wpdb->delete( $tables['translations'], array( 'faq_id' => (int) $faq['id'] ), array( '%d' ) );
		$deleted = $wpdb->delete( $tables['faqs'], array( 'id' => (int) $faq['id'] ), array( '%d' ) );
		if ( 1 !== $deleted ) { return new WP_Error( 'faq_delete_failed', 'FAQ could not be deleted.', array( 'status' => 503 ) ); }
		$bundles = self::rebuild_bundles();
		self::audit( 'faq.delete', $faq['id'], array(), 'success' );
		return array( 'ok' => true, 'bundles' => $bundles );
	}

	public static function reorder( $group, $ids ) {
		if ( ! in_array( $group, self::GROUPS, true ) ) { return new WP_Error( 'invalid_faq_group', 'Choose a supported FAQ group.', array( 'status' => 400 ) ); }
		if ( ! is_array( $ids ) || ! $ids ) { return new WP_Error( 'invalid_faq_order', 'Send the FAQ ids in the new order.', array( 'status' => 400 ) ); }
		global $wpdb;
		$tables = self::tables();
		$position = 1;
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) { continue; }
			$wpdb->update( $tables['faqs'], array( 'position' => $position, 'updated_at' => self::now() ), array( 'id' => $id, 'faq_group' => $group ), array( '%d', '%s' ), array( '%d', '%s' ) );
			$position++;
		}
		$bundles = self::rebuild_bundles();
		self::audit( 'faq.reorder', 0, array( 'position' ), 'success' );
		return array( 'ok' => true, 'bundles' => $bundles );
	}

	/** @return array<string, array{version:string,item_count:int,bytes:int,truncated:bool,compiled_at:string}> */
	public static function rebuild_bundles() {
		$summary = array();
		foreach ( self::LOCALES as $locale ) {
			$bundle = self::compile_locale( $locale );
			$summary[ $locale ] = array( 'version' => $bundle['version'], 'item_count' => $bundle['item_count'], 'bytes' => $bundle['bytes'], 'truncated' => $bundle['truncated'], 'compiled_at' => $bundle['compiled_at'] );
		}
		return $summary;
	}

	private static function compile_locale( $locale ) {
		global $wpdb;
		$tables = self::tables();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT f.id, f.faq_group, f.position, f.site_visible, f.bot_visible, t.question, t.answer
			 FROM {$tables['faqs']} f
			 INNER JOIN {$tables['translations']} t ON t.faq_id = f.id AND t.locale = %s
			 WHERE f.status = 'published' AND t.question <> '' AND t.answer <> ''
			 ORDER BY FIELD(f.faq_group, 'home', 'coffee', 'visit', 'contact', 'roastery', 'wholesale', 'assistant'), f.position ASC, f.id ASC",
			$locale
		), ARRAY_A );
		if ( $wpdb->last_error ) { return self::stored_bundle( $locale, true ); }
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'      => (int) $row['id'],
				'group'   => (string) $row['faq_group'],
				'question'=> (string) $row['question'],
				'answer'  => (string) $row['answer'],
				'site'    => (bool) (int) $row['site_visible'],
				'bot'     => (bool) (int) $row['bot_visible'],
			);
		}
		$max_items = self::max_items();
		$max_bytes = self::max_bytes();
		$truncated = false;
		if ( count( $items ) > $max_items ) { $items = array_slice( $items, 0, $max_items ); $truncated = true; }
		// Drop from the tail (lowest priority within its group) until the payload fits.
		while ( $items && strlen( wp_json_encode( $items ) ) > $max_bytes ) { array_pop( $items ); $truncated = true; }
		$json_items = wp_json_encode( $items );
		$version = substr( hash( 'sha256', $locale . '|' . $json_items ), 0, 16 );
		$generated = self::now();
		$groups = array();
		foreach ( $items as $item ) {
			$group = $item['group'];
			if ( ! isset( $groups[ $group ] ) ) { $groups[ $group ] = array( 'id' => $group, 'count' => 0 ); }
			$groups[ $group ]['count']++;
		}
		$payload = array(
			'version'      => $version,
			'lang'         => $locale,
			'generated_at' => $generated,
			'groups'       => array_values( $groups ),
			'items'        => $items,
			'item_count'   => count( $items ),
			'bytes'        => strlen( $json_items ),
			'truncated'    => $truncated,
			'max_items'    => $max_items,
			'max_bytes'    => $max_bytes,
		);
		$wpdb->replace( $tables['bundles'], array(
			'lang'        => $locale,
			'version'     => $version,
			'payload'     => wp_json_encode( $payload ),
			'item_count'  => count( $items ),
			'bytes'       => strlen( $json_items ),
			'truncated'   => $truncated ? 1 : 0,
			'compiled_at' => $generated,
		), array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' ) );
		update_option( 'epic_support_faq_last_compiled', $generated, false );
		return $payload;
	}

	private static function stored_bundle( $locale, $fallback_empty = false ) {
		global $wpdb;
		$tables = self::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT payload FROM {$tables['bundles']} WHERE lang = %s", $locale ), ARRAY_A );
		if ( $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			if ( is_array( $payload ) ) { return $payload; }
		}
		return array( 'version' => '', 'lang' => $locale, 'generated_at' => null, 'groups' => array(), 'items' => array(), 'item_count' => 0, 'bytes' => 0, 'truncated' => false, 'max_items' => self::max_items(), 'max_bytes' => self::max_bytes() );
	}

	public static function get_bundle( $locale ) {
		global $wpdb;
		$tables = self::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT payload FROM {$tables['bundles']} WHERE lang = %s", $locale ), ARRAY_A );
		if ( $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			if ( is_array( $payload ) && (int) ( $payload['max_bytes'] ?? 0 ) === self::max_bytes() && (int) ( $payload['max_items'] ?? 0 ) === self::max_items() ) { return $payload; }
		}
		return self::compile_locale( $locale );
	}

	public static function import_seed( $overwrite = false ) {
		$file = EPIC_SUPPORT_FAQ_DIR . 'data/seed-faqs.json';
		if ( ! is_readable( $file ) ) { return new WP_Error( 'faq_seed_missing', 'The bundled FAQ seed file is missing.', array( 'status' => 500 ) ); }
		$seed = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $seed ) || empty( $seed['items'] ) || ! is_array( $seed['items'] ) ) { return new WP_Error( 'faq_seed_invalid', 'The bundled FAQ seed file is not valid.', array( 'status' => 500 ) ); }
		global $wpdb;
		$tables = self::tables();
		$now = self::now();
		$created = 0;
		$updated = 0;
		$skipped = 0;
		$published = 0;
		$incomplete = 0;
		foreach ( $seed['items'] as $item ) {
			$group = sanitize_key( (string) ( $item['group'] ?? '' ) );
			$key = sanitize_text_field( (string) ( $item['key'] ?? '' ) );
			if ( ! $key || ! in_array( $group, self::GROUPS, true ) ) { $skipped++; continue; }
			$translations = self::sanitize_translations( $item['translations'] ?? array() );
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['faqs']} WHERE seed_key = %s", $key ) );
			if ( $existing_id ) {
				if ( $overwrite ) { self::write_translations( $existing_id, $translations ); $wpdb->update( $tables['faqs'], array( 'faq_group' => $group, 'position' => absint( $item['position'] ?? 0 ), 'updated_at' => $now ), array( 'id' => $existing_id ), array( '%s', '%d', '%s' ), array( '%d' ) ); $updated++; }
				else { self::merge_missing_translations( $existing_id, $translations ); $skipped++; }
				$id = $existing_id;
			} else {
				$inserted = $wpdb->insert( $tables['faqs'], array(
					'seed_key'   => $key,
					'faq_group'  => $group,
					'position'   => absint( $item['position'] ?? 0 ),
					'status'     => 'draft',
					'site_visible' => 1,
					'bot_visible'  => 1,
					'source'     => 'seed',
					'revision'   => 1,
					'created_by' => (int) get_current_user_id(),
					'created_at' => $now,
					'updated_at' => $now,
				), array( '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s' ) );
				if ( false === $inserted ) { $skipped++; continue; }
				$id = (int) $wpdb->insert_id;
				self::write_translations( $id, $translations );
				$created++;
			}
			$faq = self::get_faq( $id );
			if ( $faq && 'draft' === $faq['status'] && ! $faq['missing_locales'] ) {
				$wpdb->update( $tables['faqs'], array( 'status' => 'published', 'published_at' => $now, 'revision' => (int) $faq['revision'] + 1, 'updated_at' => $now ), array( 'id' => $id ), array( '%s', '%s', '%d', '%s' ), array( '%d' ) );
				$published++;
			} elseif ( $faq && $faq['missing_locales'] ) {
				$incomplete++;
			}
		}
		$bundles = self::rebuild_bundles();
		self::audit( 'faq.import', 0, array( 'seed' ), 'success' );
		return array( 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'published' => $published, 'incomplete' => $incomplete, 'total' => count( $seed['items'] ), 'bundles' => $bundles );
	}

	public static function seed_preview() {
		$file = EPIC_SUPPORT_FAQ_DIR . 'data/seed-faqs.json';
		if ( ! is_readable( $file ) ) { return new WP_Error( 'faq_seed_missing', 'The bundled FAQ seed file is missing.', array( 'status' => 500 ) ); }
		$seed = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $seed ) || empty( $seed['items'] ) || ! is_array( $seed['items'] ) ) { return new WP_Error( 'faq_seed_invalid', 'The bundled FAQ seed file is not valid.', array( 'status' => 500 ) ); }
		global $wpdb;
		$tables = self::tables();
		$installed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['faqs']} WHERE source = 'seed'" );
		$complete = 0;
		$by_group = array();
		foreach ( $seed['items'] as $item ) {
			$translations = self::sanitize_translations( $item['translations'] ?? array() );
			$filled = 0;
			foreach ( self::LOCALES as $locale ) {
				if ( ! empty( $translations[ $locale ]['question'] ) && ! empty( $translations[ $locale ]['answer'] ) ) { $filled++; }
			}
			if ( $filled === count( self::LOCALES ) ) { $complete++; }
			$group = sanitize_key( (string) ( $item['group'] ?? '' ) );
			if ( ! isset( $by_group[ $group ] ) ) { $by_group[ $group ] = array( 'group' => $group, 'total' => 0, 'complete' => 0 ); }
			$by_group[ $group ]['total']++;
			if ( $filled === count( self::LOCALES ) ) { $by_group[ $group ]['complete']++; }
		}
		return array( 'total' => count( $seed['items'] ), 'complete' => $complete, 'incomplete' => count( $seed['items'] ) - $complete, 'installed' => $installed, 'groups' => array_values( $by_group ), 'generated_at' => $seed['generatedAt'] ?? null );
	}

	public static function translation_config() {
		$url = defined( 'EPIC_SUPPORT_FAQ_TRANSLATE_URL' ) && EPIC_SUPPORT_FAQ_TRANSLATE_URL ? (string) EPIC_SUPPORT_FAQ_TRANSLATE_URL : (string) get_option( 'epic_support_faq_translate_url', '' );
		$secret = defined( 'EPIC_SUPPORT_FAQ_TRANSLATE_SECRET' ) && EPIC_SUPPORT_FAQ_TRANSLATE_SECRET ? (string) EPIC_SUPPORT_FAQ_TRANSLATE_SECRET : (string) get_option( 'epic_support_faq_translate_secret', '' );
		// Deployment-time fallback. The file starts with an ABSPATH guard so a
		// direct request never reveals the webhook secret.
		$deploy_file = EPIC_SUPPORT_FAQ_DIR . 'data/translate-config.php';
		if ( ( ! $url || '' === $secret ) && is_readable( $deploy_file ) ) {
			$deploy = include $deploy_file;
			if ( is_array( $deploy ) ) {
				if ( ! $url && ! empty( $deploy['url'] ) ) { $url = (string) $deploy['url']; }
				if ( '' === $secret && ! empty( $deploy['secret'] ) ) { $secret = (string) $deploy['secret']; }
			}
		}
		return array( 'url' => esc_url_raw( $url, array( 'https' ) ), 'secret' => $secret );
	}

	/** Translate the Vietnamese source into the six other locales through the configured n8n webhook. */
	public static function translate( $question, $answer ) {
		$question = sanitize_textarea_field( (string) $question );
		$answer = sanitize_textarea_field( (string) $answer );
		$question = function_exists( 'mb_substr' ) ? mb_substr( $question, 0, self::MAX_QUESTION, 'UTF-8' ) : substr( $question, 0, self::MAX_QUESTION );
		$answer = function_exists( 'mb_substr' ) ? mb_substr( $answer, 0, self::MAX_ANSWER, 'UTF-8' ) : substr( $answer, 0, self::MAX_ANSWER );
		if ( '' === trim( $question ) || '' === trim( $answer ) ) { return new WP_Error( 'invalid_faq_translation', 'Enter the Vietnamese question and answer before translating.', array( 'status' => 400 ) ); }
		$config = self::translation_config();
		if ( ! $config['url'] || '' === $config['secret'] ) { return new WP_Error( 'faq_translation_not_configured', 'The FAQ translation webhook is not configured. Set it under Settings → EPIC Support FAQ.', array( 'status' => 503 ) ); }
		$response = wp_remote_post( $config['url'], array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json', 'X-EPIC-Faq-Key' => $config['secret'] ),
			'body'    => wp_json_encode( array( 'question' => $question, 'answer' => $answer, 'source' => 'vi', 'targets' => self::TRANSLATE_TARGETS ) ),
		) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'faq_translation_unavailable', 'The translation service is unavailable. Try again shortly.', array( 'status' => 502 ) ); }
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'faq_translation_failed', 'The translation service returned an error.', array( 'status' => 502 ) ); }
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$translations = is_array( $body ) && isset( $body['translations'] ) && is_array( $body['translations'] ) ? $body['translations'] : array();
		$out = array();
		foreach ( self::TRANSLATE_TARGETS as $locale ) {
			if ( empty( $translations[ $locale ] ) || ! is_array( $translations[ $locale ] ) ) { continue; }
			$q = sanitize_textarea_field( (string) ( $translations[ $locale ]['question'] ?? '' ) );
			$a = sanitize_textarea_field( (string) ( $translations[ $locale ]['answer'] ?? '' ) );
			if ( '' === trim( $q ) || '' === trim( $a ) ) { continue; }
			$out[ $locale ] = array(
				'question' => function_exists( 'mb_substr' ) ? mb_substr( $q, 0, self::MAX_QUESTION, 'UTF-8' ) : substr( $q, 0, self::MAX_QUESTION ),
				'answer'   => function_exists( 'mb_substr' ) ? mb_substr( $a, 0, self::MAX_ANSWER, 'UTF-8' ) : substr( $a, 0, self::MAX_ANSWER ),
			);
		}
		$missing = array_values( array_diff( self::TRANSLATE_TARGETS, array_keys( $out ) ) );
		if ( $missing ) { return new WP_Error( 'faq_translation_incomplete', 'The translation service did not return: ' . implode( ', ', $missing ) . '.', array( 'status' => 502 ) ); }
		return array( 'source' => 'vi', 'translations' => $out );
	}

	public static function settings() {
		return array(
			'locales'    => self::LOCALES,
			'groups'     => self::GROUPS,
			'max_bytes'  => self::max_bytes(),
			'max_items'  => self::max_items(),
			'last_compiled' => get_option( 'epic_support_faq_last_compiled', null ),
		);
	}
}
