<?php
/**
 * Settings Controller — tags, lists, custom fields, labels, general settings.
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsController {

	/* ====================================================================
	 *  TAGS
	 * =================================================================== */

	public function list_tags( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_tags" ) );
		if ( ! $table_exists ) {
			return new \WP_REST_Response( array() );
		}

		$tags = $wpdb->get_results( "SELECT t.*, COUNT(sp.id) AS subscribers_count FROM {$p}aime_tags t LEFT JOIN {$p}aime_subscriber_pivot sp ON sp.object_id = t.id AND sp.object_type = 'tag' GROUP BY t.id ORDER BY t.title ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return new \WP_REST_Response( $tags ?: array() );
	}

	public function create_tag( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$title = sanitize_text_field( $request->get_param( 'title' ) );
		$slug  = sanitize_title( $title );
		$color = sanitize_text_field( $request->get_param( 'color' ) ?? '' );

		// Accept hex colors in various formats.
		if ( $color && ! preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color ) ) {
			$color = null;
		}

		$result = $wpdb->insert( "{$wpdb->prefix}aime_tags", array(
			'title'       => $title,
			'slug'        => $slug,
			'description' => sanitize_textarea_field( $request->get_param( 'description' ) ?? '' ),
			'color'       => $color ?: null,
			'created_at'  => current_time( 'mysql', true ),
		) );

		if ( false === $result ) {
			return new \WP_REST_Response( array( 'message' => __( 'Failed to create tag.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( array( 'id' => (int) $wpdb->insert_id, 'message' => __( 'Tag created.', 'ai-marketing-expert' ) ), 201 );
	}

	public function update_tag( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );
		$data = array( 'updated_at' => current_time( 'mysql', true ) );

		if ( $request->has_param( 'title' ) ) {
			$data['title'] = sanitize_text_field( $request->get_param( 'title' ) );
			$data['slug']  = sanitize_title( $data['title'] );
		}
		if ( $request->has_param( 'description' ) ) {
			$data['description'] = sanitize_textarea_field( $request->get_param( 'description' ) );
		}
		if ( $request->has_param( 'color' ) ) {
			$color = sanitize_text_field( $request->get_param( 'color' ) );
			if ( $color && ! preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color ) ) {
				$color = null;
			}
			$data['color'] = $color ?: null;
		}

		$result = $wpdb->update( "{$wpdb->prefix}aime_tags", $data, array( 'id' => $id ) );

		if ( false === $result ) {
			return new \WP_REST_Response( array( 'message' => __( 'Failed to update tag.', 'ai-marketing-expert' ) ), 500 );
		}

		return new \WP_REST_Response( array( 'message' => __( 'Tag updated.', 'ai-marketing-expert' ) ) );
	}

	public function delete_tag( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );
		$wpdb->delete( "{$p}aime_tags", array( 'id' => $id ) );
		$wpdb->delete( "{$p}aime_subscriber_pivot", array( 'object_id' => $id, 'object_type' => 'tag' ) );
		return new \WP_REST_Response( array( 'message' => __( 'Tag deleted.', 'ai-marketing-expert' ) ) );
	}

	/* ====================================================================
	 *  LISTS
	 * =================================================================== */

	public function list_lists( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}aime_lists" ) );
		if ( ! $table_exists ) {
			return new \WP_REST_Response( array() );
		}

		$lists = $wpdb->get_results( "SELECT l.*, COUNT(sp.id) AS subscribers_count FROM {$p}aime_lists l LEFT JOIN {$p}aime_subscriber_pivot sp ON sp.object_id = l.id AND sp.object_type = 'list' GROUP BY l.id ORDER BY l.title ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return new \WP_REST_Response( $lists ?: array() );
	}

	public function create_list( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$title = sanitize_text_field( $request->get_param( 'title' ) );
		$slug  = sanitize_title( $title );

		$wpdb->insert( "{$wpdb->prefix}aime_lists", array(
			'title'       => $title,
			'slug'        => $slug,
			'description' => sanitize_textarea_field( $request->get_param( 'description' ) ?? '' ),
			'is_public'   => $request->get_param( 'is_public' ) ? 1 : 0,
			'created_at'  => current_time( 'mysql', true ),
		) );

		return new \WP_REST_Response( array( 'id' => (int) $wpdb->insert_id, 'message' => __( 'List created.', 'ai-marketing-expert' ) ), 201 );
	}

	public function update_list( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );
		$data = array( 'updated_at' => current_time( 'mysql', true ) );

		if ( $request->has_param( 'title' ) ) {
			$data['title'] = sanitize_text_field( $request->get_param( 'title' ) );
			$data['slug']  = sanitize_title( $data['title'] );
		}
		if ( $request->has_param( 'description' ) ) {
			$data['description'] = sanitize_textarea_field( $request->get_param( 'description' ) );
		}
		if ( $request->has_param( 'is_public' ) ) {
			$data['is_public'] = $request->get_param( 'is_public' ) ? 1 : 0;
		}

		$wpdb->update( "{$wpdb->prefix}aime_lists", $data, array( 'id' => $id ) );
		return new \WP_REST_Response( array( 'message' => __( 'List updated.', 'ai-marketing-expert' ) ) );
	}

	public function delete_list( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );
		$wpdb->delete( "{$p}aime_lists", array( 'id' => $id ) );
		$wpdb->delete( "{$p}aime_subscriber_pivot", array( 'object_id' => $id, 'object_type' => 'list' ) );
		return new \WP_REST_Response( array( 'message' => __( 'List deleted.', 'ai-marketing-expert' ) ) );
	}

	/* ====================================================================
	 *  CUSTOM FIELDS
	 * =================================================================== */

	public function list_custom_fields( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$object_type = sanitize_text_field( $request->get_param( 'object_type' ) ?? 'contact' );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aime_custom_fields WHERE object_type = %s ORDER BY position ASC", $object_type )
		);
		return new \WP_REST_Response( $rows );
	}

	public function save_custom_field( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p = $wpdb->prefix;
		$id = absint( $request->get_param( 'id' ) );
		$field_key = sanitize_key( $request->get_param( 'field_key' ) ?? '' );
		$label     = sanitize_text_field( $request->get_param( 'label' ) ?? '' );
		$type      = sanitize_key( $request->get_param( 'field_type' ) ?? 'text' );
		$options   = $request->get_param( 'options' ) ?? array();

		if ( '' === $field_key || '' === $label ) {
			return new \WP_REST_Response( array( 'message' => __( 'Field label and slug are required.', 'ai-marketing-expert' ) ), 400 );
		}

		if ( ! in_array( $type, array( 'text', 'number', 'date', 'select', 'radio', 'checkbox', 'textarea' ), true ) ) {
			$type = 'text';
		}

		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$options = array_values( array_filter( array_map( 'sanitize_text_field', $options ), 'strlen' ) );

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$p}aime_custom_fields WHERE object_type = %s AND field_key = %s AND id <> %d LIMIT 1",
				sanitize_text_field( $request->get_param( 'object_type' ) ?? 'contact' ),
				$field_key,
				$id
			)
		);

		if ( $existing_id ) {
			return new \WP_REST_Response( array( 'message' => __( 'A custom field with this slug already exists.', 'ai-marketing-expert' ) ), 400 );
		}

		$data = array(
			'object_type' => sanitize_text_field( $request->get_param( 'object_type' ) ?? 'contact' ),
			'field_key'   => $field_key,
			'label'       => $label,
			'field_type'  => $type,
			'options'     => wp_json_encode( $options ),
			'is_required' => $request->get_param( 'is_required' ) ? 1 : 0,
			'position'    => absint( $request->get_param( 'position' ) ?? 0 ),
		);

		if ( $id ) {
			$data['updated_at'] = current_time( 'mysql', true );
			$wpdb->update( "{$p}aime_custom_fields", $data, array( 'id' => $id ) );
			return new \WP_REST_Response( array( 'id' => $id, 'message' => __( 'Custom field updated.', 'ai-marketing-expert' ) ) );
		}

		$data['created_at'] = current_time( 'mysql', true );
		$wpdb->insert( "{$p}aime_custom_fields", $data );
		return new \WP_REST_Response( array( 'id' => (int) $wpdb->insert_id, 'message' => __( 'Custom field created.', 'ai-marketing-expert' ) ), 201 );
	}

	public function delete_custom_field( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$id  = absint( $request->get_param( 'id' ) );
		$key = $wpdb->get_var( $wpdb->prepare( "SELECT field_key FROM {$wpdb->prefix}aime_custom_fields WHERE id = %d", $id ) );

		$wpdb->delete( "{$wpdb->prefix}aime_custom_fields", array( 'id' => $id ) );

		// Clean up subscriber_meta for this key.
		if ( $key ) {
			$wpdb->delete( "{$wpdb->prefix}aime_subscriber_meta", array( 'meta_key' => $key ) );
		}

		return new \WP_REST_Response( array( 'message' => __( 'Custom field deleted.', 'ai-marketing-expert' ) ) );
	}

	/* ====================================================================
	 *  GENERAL SETTINGS
	 * =================================================================== */

	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$global_settings = get_option( 'aime_settings', array() );
		$double_optin    = get_option( 'aime_double_optin', $global_settings['double_optin'] ?? false );
		$defaults        = \WPSpace\AiMarketingExpert\Activator::get_email_field_defaults();

		return new \WP_REST_Response( array(
			'from_name'         => get_option( 'aime_from_name', $defaults['aime_from_name'] ),
			'from_email'        => get_option( 'aime_from_email', $defaults['aime_from_email'] ),
			'reply_to'          => get_option( 'aime_reply_to', $defaults['aime_reply_to'] ),
			'unsubscribe_text'  => get_option( 'aime_unsubscribe_text', $defaults['aime_unsubscribe_text'] ),
			'company_name'      => get_option( 'aime_company_name', $defaults['aime_company_name'] ),
			'company_address'   => get_option( 'aime_company_address', $defaults['aime_company_address'] ),
			'email_footer'      => get_option( 'aime_email_footer', $defaults['aime_email_footer'] ),
			'unsubscribe_heading'     => get_option( 'aime_unsubscribe_heading', '' ),
			'unsubscribe_message'     => get_option( 'aime_unsubscribe_message', '' ),
			'resubscribe_button_text' => get_option( 'aime_resubscribe_button_text', '' ),
			'double_optin'      => (bool) $double_optin,
			'emails_per_second' => (int) get_option( 'aime_emails_per_second', 10 ),
		) );
	}

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$fields = array(
			'from_name'         => 'sanitize_text_field',
			'from_email'        => 'sanitize_email',
			'reply_to'          => 'sanitize_email',
			'unsubscribe_text'  => 'sanitize_text_field',
			'company_name'      => 'sanitize_text_field',
			'company_address'   => 'sanitize_textarea_field',
			'email_footer'      => 'wp_kses_post',
			'unsubscribe_heading'     => 'sanitize_text_field',
			'unsubscribe_message'     => 'sanitize_textarea_field',
			'resubscribe_button_text' => 'sanitize_text_field',
			'double_optin'      => 'boolval',
			'emails_per_second' => 'absint',
		);

		// Free tier: enforce at least one footer line (custom footer, company info, or unsubscribe text).
		if ( ! aime_has_pro() ) {
			$footer = wp_kses_post( $request->get_param( 'email_footer' ) ?? '' );
			$company_name = sanitize_text_field( $request->get_param( 'company_name' ) ?? '' );
			$company_address = sanitize_textarea_field( $request->get_param( 'company_address' ) ?? '' );
			$unsubscribe_text = sanitize_text_field( $request->get_param( 'unsubscribe_text' ) ?? '' );

			$has_footer_content = ! empty( $footer ) || ! empty( $company_name ) || ! empty( $company_address ) || ! empty( $unsubscribe_text );

			if ( ! $has_footer_content ) {
				return new \WP_REST_Response( array( 'message' => __( 'Free version requires at least one footer line: custom footer text, company name/address, or unsubscribe text.', 'ai-marketing-expert' ) ), 400 );
			}
		}

		foreach ( $fields as $key => $sanitizer ) {
			if ( $request->has_param( $key ) ) {
				$value = call_user_func( $sanitizer, $request->get_param( $key ) );

				if ( 'emails_per_second' === $key ) {
					$value = max( 1, min( 100, (int) $value ) );
				}

				update_option( 'aime_' . $key, $value, false );

				if ( 'double_optin' === $key ) {
					$global_settings = get_option( 'aime_settings', array() );
					$global_settings['double_optin'] = (bool) $value;
					update_option( 'aime_settings', $global_settings, false );
				}
			}
		}

		aime_clear_settings_cache( array_merge( array( 'aime_settings' ), array_map( function ( $key ) {
			return 'aime_' . $key;
		}, array_keys( $fields ) ) ) );

		return new \WP_REST_Response( array( 'message' => __( 'Settings saved.', 'ai-marketing-expert' ) ) );
	}
}
