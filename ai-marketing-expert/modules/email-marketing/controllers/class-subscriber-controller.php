<?php
/**
 * Subscriber Controller — contacts CRUD, bulk ops, notes, activity.
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

use WPSpace\AiMarketingExpert\EmailValidator;
use WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services\B2bScraperService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SubscriberController {

	/* ================================================================
	 *  LIST / SEARCH
	 * ============================================================= */

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$pivot_table       = $wpdb->prefix . 'aime_subscriber_pivot';

		$per_page = absint( $request->get_param( 'per_page' ) ?: 20 );
		if ( ! aime_has_pro() && $per_page > 1000 ) {
			return new \WP_REST_Response( array( 'message' => __( 'Export contacts is available in Pro.', 'ai-marketing-expert' ) ), 403 );
		}
		$page     = absint( $request->get_param( 'page' ) ?: 1 );
		$offset   = ( $page - 1 ) * $per_page;
		$search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
		$status   = sanitize_text_field( $request->get_param( 'status' ) ?? '' );

		$sort_col   = sanitize_key( $request->get_param( 'sort_by' ) ?? '' );
		$sort_dir   = strtoupper( $request->get_param( 'sort_order' ) ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
		$allowed    = array( 'id', 'email', 'first_name', 'last_name', 'status', 'created_at', 'updated_at' );
		$sort_field = ( $sort_col && in_array( $sort_col, $allowed, true ) ) ? $sort_col : 'id';
		$sort_by    = "s.{$sort_field} {$sort_dir}";

		$list_id  = absint( $request->get_param( 'list_id' ) );
		$tag_id   = absint( $request->get_param( 'tag_id' ) );

		$where  = array( '1=1' );
		$joins  = '';
		$params = array();

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( $status ) {
			$where[]  = 's.status = %s';
			$params[] = $status;
		}
		if ( $list_id ) {
			$joins   .= $wpdb->prepare( ' INNER JOIN %i spL ON spL.subscriber_id = s.id AND spL.object_type = \'list\' AND spL.object_id = %d', $pivot_table, $list_id );
		}
		if ( $tag_id ) {
			$joins   .= $wpdb->prepare( ' INNER JOIN %i spT ON spT.subscriber_id = s.id AND spT.object_type = \'tag\' AND spT.object_id = %d', $pivot_table, $tag_id );
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = $wpdb->prepare( 'SELECT COUNT(DISTINCT s.id) FROM %i s', $subscribers_table ) . $joins . ' WHERE ' . $where_sql;
		$data_sql  = $wpdb->prepare( 'SELECT DISTINCT s.* FROM %i s', $subscribers_table ) . $joins . ' WHERE ' . $where_sql . ' ORDER BY ' . $sort_by . ' LIMIT %d OFFSET %d';

		$params_count = $params;
		$params[]     = $per_page;
		$params[]     = $offset;

		$total = (int) $wpdb->get_var( empty( $params_count ) ? $count_sql : $wpdb->prepare( $count_sql, ...$params_count ) ); // phpcs:ignore
		$items = $wpdb->get_results( empty( $params ) ? $data_sql : $wpdb->prepare( $data_sql, ...$params ) ); // phpcs:ignore

		// Attach tags & lists.
		$items = $this->attach_pivot_data( $items );

		return new \WP_REST_Response( array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		) );
	}

	/* ================================================================
	 *  COUNT — returns total matching contacts for campaign recipient preview.
	 * ============================================================= */

	public function count( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$pivot_table       = $wpdb->prefix . 'aime_subscriber_pivot';

		$list_ids         = array_map( 'absint', (array) $request->get_param( 'list_ids' ) );
		$tag_ids          = array_map( 'absint', (array) $request->get_param( 'tag_ids' ) );
		$segments         = array(
			array(
				'type'  => $request->get_param( 'segment_type' ),
				'days'  => $request->get_param( 'segment_days' ),
				'limit' => $request->get_param( 'segment_limit' ),
			),
		);
		$exclude_list_ids = array_map( 'absint', (array) $request->get_param( 'exclude_list_ids' ) );
		$exclude_tag_ids  = array_map( 'absint', (array) $request->get_param( 'exclude_tag_ids' ) );
		$mode             = sanitize_text_field( $request->get_param( 'mode' ) ?? 'all' );

		if ( 'segments' === $mode ) {
			if ( ! aime_has_pro() ) {
				return new \WP_REST_Response( array( 'message' => __( 'Smart Segments are available in Pro.', 'ai-marketing-expert' ) ), 403 );
			}
			return new \WP_REST_Response( array( 'total' => count( $this->resolve_engagement_segments( $segments, $exclude_list_ids, $exclude_tag_ids ) ) ) );
		}

		$where  = array( "s.status = 'subscribed'" );
		$joins  = '';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( $mode === 'lists' && ! empty( $list_ids ) ) {
			$joins .= $wpdb->prepare(
				' INNER JOIN %i spL ON spL.subscriber_id = s.id AND spL.object_type = %s AND spL.object_id IN (' . $this->get_int_placeholders( $list_ids ) . ')',
				$pivot_table,
				'list',
				...$list_ids
			);
		} elseif ( $mode === 'tags' && ! empty( $tag_ids ) ) {
			$joins .= $wpdb->prepare(
				' INNER JOIN %i spT ON spT.subscriber_id = s.id AND spT.object_type = %s AND spT.object_id IN (' . $this->get_int_placeholders( $tag_ids ) . ')',
				$pivot_table,
				'tag',
				...$tag_ids
			);
		}

		if ( ! empty( $exclude_list_ids ) ) {
			$where[] = $wpdb->prepare(
				' s.id NOT IN (SELECT subscriber_id FROM %i WHERE object_type = %s AND object_id IN (' . $this->get_int_placeholders( $exclude_list_ids ) . '))',
				$pivot_table,
				'list',
				...$exclude_list_ids
			);
		}
		if ( ! empty( $exclude_tag_ids ) ) {
			$where[] = $wpdb->prepare(
				' s.id NOT IN (SELECT subscriber_id FROM %i WHERE object_type = %s AND object_id IN (' . $this->get_int_placeholders( $exclude_tag_ids ) . '))',
				$pivot_table,
				'tag',
				...$exclude_tag_ids
			);
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = $wpdb->prepare( 'SELECT COUNT(DISTINCT s.id) FROM %i s', $subscribers_table ) . $joins . ' WHERE ' . $where_sql;

		$total = (int) $wpdb->get_var( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return new \WP_REST_Response( array( 'total' => $total ) );
	}

	private function resolve_engagement_segments( array $segments, array $exclude_list_ids = array(), array $exclude_tag_ids = array() ): array {
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$metrics_table     = $wpdb->prefix . 'aime_campaign_url_metrics';
		$pivot_table       = $wpdb->prefix . 'aime_subscriber_pivot';
		$ids               = array();

		foreach ( $segments as $segment ) {
			$segment = (array) $segment;
			$type    = sanitize_key( $segment['type'] ?? '' );
			$days    = min( 365, max( 1, absint( $segment['days'] ?? 90 ) ) );
			$limit   = min( 5000, max( 1, absint( $segment['limit'] ?? 500 ) ) );
			$since   = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

			if ( ! in_array( $type, array( 'top_openers', 'top_clickers', 'most_engaged' ), true ) ) {
				continue;
			}

			if ( 'top_openers' === $type ) {
				$ids = array_merge( $ids, array_map( 'absint', $wpdb->get_col( $wpdb->prepare(
					"SELECT s.id
					 FROM %i s
					 INNER JOIN %i m ON m.subscriber_id = s.id
					 WHERE s.status = 'subscribed' AND m.type = 'open' AND m.created_at >= %s
					 GROUP BY s.id
					 ORDER BY SUM(CASE WHEN m.type = 'open' THEN m.counter ELSE 0 END) DESC, MAX(m.created_at) DESC, s.id DESC
					 LIMIT %d",
					$subscribers_table,
					$metrics_table,
					$since,
					$limit
				) ) ) );
			} elseif ( 'top_clickers' === $type ) {
				$ids = array_merge( $ids, array_map( 'absint', $wpdb->get_col( $wpdb->prepare(
					"SELECT s.id
					 FROM %i s
					 INNER JOIN %i m ON m.subscriber_id = s.id
					 WHERE s.status = 'subscribed' AND m.type = 'click' AND m.created_at >= %s
					 GROUP BY s.id
					 ORDER BY SUM(CASE WHEN m.type = 'click' THEN m.counter ELSE 0 END) DESC, MAX(m.created_at) DESC, s.id DESC
					 LIMIT %d",
					$subscribers_table,
					$metrics_table,
					$since,
					$limit
				) ) ) );
			} else {
				$ids = array_merge( $ids, array_map( 'absint', $wpdb->get_col( $wpdb->prepare(
					"SELECT s.id
					 FROM %i s
					 INNER JOIN %i m ON m.subscriber_id = s.id
					 WHERE s.status = 'subscribed' AND m.type IN ('open','click') AND m.created_at >= %s
					 GROUP BY s.id
					 ORDER BY (SUM(CASE WHEN m.type = 'open' THEN m.counter ELSE 0 END) + (SUM(CASE WHEN m.type = 'click' THEN m.counter ELSE 0 END) * 3)) DESC, MAX(m.created_at) DESC, s.id DESC
					 LIMIT %d",
					$subscribers_table,
					$metrics_table,
					$since,
					$limit
				) ) ) );
			}
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$exclude_ids = array();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( ! empty( $exclude_list_ids ) ) {
			$list_query  = $wpdb->prepare(
				'SELECT subscriber_id FROM %i WHERE object_type = %s AND object_id IN (' . $this->get_int_placeholders( $exclude_list_ids ) . ')',
				$pivot_table,
				'list',
				...$exclude_list_ids
			);
			$exclude_ids = array_merge( $exclude_ids, array_map( 'absint', $wpdb->get_col( $list_query ) ) );
		}
		if ( ! empty( $exclude_tag_ids ) ) {
			$tag_query   = $wpdb->prepare(
				'SELECT subscriber_id FROM %i WHERE object_type = %s AND object_id IN (' . $this->get_int_placeholders( $exclude_tag_ids ) . ')',
				$pivot_table,
				'tag',
				...$exclude_tag_ids
			);
			$exclude_ids = array_merge( $exclude_ids, array_map( 'absint', $wpdb->get_col( $tag_query ) ) );
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return array_values( array_diff( $ids, array_unique( $exclude_ids ) ) );
	}

	/* ================================================================
	 *  SINGLE
	 * ============================================================= */

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		$id = absint( $request->get_param( 'id' ) );
		$s  = $this->find( $id );
		if ( ! $s ) {
			return new \WP_REST_Response( array( 'message' => __( 'Contact not found.', 'ai-marketing-expert' ) ), 404 );
		}
		$s = $this->attach_pivot_data( array( $s ) )[0];
		$s->notes    = $this->get_notes( $id );
		$s->activity = $this->get_activity( $id );
		$s->meta     = $this->get_meta( $id );
		return new \WP_REST_Response( $s );
	}

	/* ================================================================
	 *  CREATE
	 * ============================================================= */

	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$data   = $this->sanitize_subscriber( $request );

		// Validate email quality.
		$valid = EmailValidator::validate( $data['email'], 'admin' );
		if ( is_wp_error( $valid ) ) {
			return new \WP_REST_Response( array( 'message' => $valid->get_error_message() ), 400 );
		}

		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', $subscribers_table, $data['email'] ) );

		if ( $exists ) {
			return new \WP_REST_Response( array( 'message' => __( 'Email already exists.', 'ai-marketing-expert' ) , 'id' => (int) $exists ), 409 );
		}

		// Double opt-in: override status to pending.
		$double_optin = $this->is_double_optin_enabled();
		if ( $double_optin ) {
			$data['status'] = 'pending';
		}

		$data['hash']       = md5( $data['email'] . wp_generate_uuid4() );
		$data['created_at'] = current_time( 'mysql', true );

		$wpdb->insert( $subscribers_table, $data );
		$id = (int) $wpdb->insert_id;

		// Attach tags & lists from request.
		$this->sync_pivot( $id, $request->get_param( 'tag_ids' ) ?? array(), 'tag' );
		$this->sync_pivot( $id, $request->get_param( 'list_ids' ) ?? array(), 'list' );

		$this->log_activity( $id, 'created', 'Contact created' );

		// Send double opt-in confirmation email.
		if ( $double_optin ) {
			$this->send_double_optin_email( $id, $data['email'], $data['first_name'] ?? '' );
		}

		/**
		 * Fires after a subscriber is created via the admin.
		 *
		 * @param int   $id   Subscriber ID.
		 * @param array $data Subscriber data.
		 */
		do_action( 'aime_subscriber_created', $id, $data );

		return new \WP_REST_Response( array( 'id' => $id, 'message' => __( 'Contact created.', 'ai-marketing-expert' ) ), 201 );
	}

	/* ================================================================
	 *  UPDATE
	 * ============================================================= */

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$id                = absint( $request->get_param( 'id' ) );

		if ( ! $this->find( $id ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Contact not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$old_status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $subscribers_table, $id ) );
		$data = $this->sanitize_subscriber( $request );
		$data['updated_at'] = current_time( 'mysql', true );

		$wpdb->update( $subscribers_table, $data, array( 'id' => $id ) );
		if ( isset( $data['status'] ) && $old_status !== $data['status'] ) {
			do_action( 'aime_subscriber_status_change', $id, $old_status, $data['status'] );
		}

		if ( $request->has_param( 'tag_ids' ) ) {
			$this->sync_pivot( $id, $request->get_param( 'tag_ids' ), 'tag' );
		}
		if ( $request->has_param( 'list_ids' ) ) {
			$this->sync_pivot( $id, $request->get_param( 'list_ids' ), 'list' );
		}

		// Save custom meta fields.
		$custom = $request->get_param( 'custom_fields' );
		if ( is_array( $custom ) ) {
			$this->save_meta( $id, $custom );
		}

		$this->log_activity( $id, 'updated', 'Contact updated' );

		return new \WP_REST_Response( array( 'message' => __( 'Contact updated.', 'ai-marketing-expert' ) ) );
	}

	/* ================================================================
	 *  DELETE
	 * ============================================================= */

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$pivot_table       = $wpdb->prefix . 'aime_subscriber_pivot';
		$meta_table        = $wpdb->prefix . 'aime_subscriber_meta';
		$notes_table       = $wpdb->prefix . 'aime_subscriber_notes';
		$id                = absint( $request->get_param( 'id' ) );

		$wpdb->delete( $subscribers_table, array( 'id' => $id ) );
		$wpdb->delete( $pivot_table, array( 'subscriber_id' => $id ) );
		$wpdb->delete( $meta_table, array( 'subscriber_id' => $id ) );
		$wpdb->delete( $notes_table, array( 'subscriber_id' => $id ) );

		return new \WP_REST_Response( array( 'message' => __( 'Contact deleted.', 'ai-marketing-expert' ) ) );
	}

	/* ================================================================
	 *  BULK ACTIONS — fixes the "Invalid parameter(s): action" bug!
	 * ============================================================= */

	public function bulk( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$pivot_table       = $wpdb->prefix . 'aime_subscriber_pivot';
		$meta_table        = $wpdb->prefix . 'aime_subscriber_meta';
		$notes_table       = $wpdb->prefix . 'aime_subscriber_notes';
		$ids = (bool) $request->get_param( 'all_matching' )
			? $this->get_matching_subscriber_ids( $request )
			: array_map( 'absint', $request->get_param( 'ids' ) ?? array() );
		$ids = array_values( array_filter( array_unique( $ids ) ) );

		if ( empty( $ids ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'No contacts selected.', 'ai-marketing-expert' ) ), 400 );
		}

		$action = sanitize_text_field( $request->get_param( 'action' ) );
		if ( ! aime_has_pro() && in_array( $action, array( 'complained', 'remove_tags', 'remove_lists' ), true ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'This bulk action is available in Pro.', 'ai-marketing-expert' ) ), 403 );
		}
		$now    = current_time( 'mysql', true );
		$count  = count( $ids );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		switch ( $action ) {
			case 'delete':
				$placeholders = $this->get_int_placeholders( $ids );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . $placeholders . ')', $subscribers_table, ...$ids ) );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE subscriber_id IN (' . $placeholders . ')', $pivot_table, ...$ids ) );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE subscriber_id IN (' . $placeholders . ')', $meta_table, ...$ids ) );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE subscriber_id IN (' . $placeholders . ')', $notes_table, ...$ids ) );
				break;

			case 'subscribe':
			case 'unsubscribe':
			case 'pending':
			case 'bounced':
			case 'complained':
				$placeholders = $this->get_int_placeholders( $ids );
				$status_map = array(
					'subscribe'   => 'subscribed',
					'unsubscribe' => 'unsubscribed',
					'pending'     => 'pending',
					'bounced'     => 'bounced',
					'complained'  => 'complained',
				);
				$status_val = $status_map[ $action ];

				// Capture old statuses first so the status-change hook and the
				// activity log only fire for contacts that actually changed.
				$old_rows = $wpdb->get_results( $wpdb->prepare(
					'SELECT id, status FROM %i WHERE id IN (' . $placeholders . ')',
					$subscribers_table,
					...$ids
				) );

				$wpdb->query( $wpdb->prepare(
					'UPDATE %i SET status = %s, updated_at = %s WHERE id IN (' . $placeholders . ')',
					$subscribers_table,
					$status_val,
					$now,
					...$ids
				) );

				foreach ( $old_rows as $old_row ) {
					$old_status = (string) $old_row->status;
					if ( $old_status === $status_val ) {
						continue;
					}
					$this->log_activity( (int) $old_row->id, $status_val, sprintf( 'Status changed to %s via bulk action', $status_val ) );
					/** Same hook single edits and provider webhooks fire, so automations react to bulk changes too. */
					do_action( 'aime_subscriber_status_change', (int) $old_row->id, $old_status, $status_val );
				}
				break;
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			case 'assign_tags':
				$tag_ids = array_map( 'absint', $request->get_param( 'tag_ids' ) ?? array() );
				foreach ( $ids as $sid ) {
					$this->attach_pivot( $sid, $tag_ids, 'tag' );
				}
				break;

			case 'remove_tags':
				$tag_ids = array_map( 'absint', $request->get_param( 'tag_ids' ) ?? array() );
				foreach ( $ids as $sid ) {
					$this->detach_pivot( $sid, $tag_ids, 'tag' );
				}
				break;

			case 'assign_lists':
				$list_ids = array_map( 'absint', $request->get_param( 'list_ids' ) ?? array() );
				foreach ( $ids as $sid ) {
					$this->attach_pivot( $sid, $list_ids, 'list' );
				}
				break;

			case 'remove_lists':
				$list_ids = array_map( 'absint', $request->get_param( 'list_ids' ) ?? array() );
				foreach ( $ids as $sid ) {
					$this->detach_pivot( $sid, $list_ids, 'list' );
				}
				break;

			default:
				return new \WP_REST_Response( array( 'message' => __( 'Unknown action.', 'ai-marketing-expert' ) ), 400 );
		}

		return new \WP_REST_Response( array(
			'message' => sprintf(
				/* translators: %d: number of contacts */
				__( '%d contact(s) updated.', 'ai-marketing-expert' ),
				$count
			),
			'count'   => $count,
		) );
	}

	private function get_matching_subscriber_ids( \WP_REST_Request $request ): array {
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$pivot_table       = $wpdb->prefix . 'aime_subscriber_pivot';

		$search  = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
		$status  = sanitize_text_field( $request->get_param( 'status' ) ?? '' );
		$list_id = absint( $request->get_param( 'list_id' ) );
		$tag_id  = absint( $request->get_param( 'tag_id' ) );

		$where  = array( '1=1' );
		$joins  = '';
		$params = array();

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( $status ) {
			$where[]  = 's.status = %s';
			$params[] = $status;
		}
		if ( $list_id ) {
			$joins .= $wpdb->prepare( ' INNER JOIN %i spL ON spL.subscriber_id = s.id AND spL.object_type = \'list\' AND spL.object_id = %d', $pivot_table, $list_id );
		}
		if ( $tag_id ) {
			$joins .= $wpdb->prepare( ' INNER JOIN %i spT ON spT.subscriber_id = s.id AND spT.object_type = \'tag\' AND spT.object_id = %d', $pivot_table, $tag_id );
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = $wpdb->prepare( 'SELECT DISTINCT s.id FROM %i s', $subscribers_table ) . $joins . ' WHERE ' . $where_sql;

		return array_map( 'absint', $wpdb->get_col( empty( $params ) ? $sql : $wpdb->prepare( $sql, ...$params ) ) ); // phpcs:ignore
	}

	/* ================================================================
	 *  NOTES
	 * ============================================================= */

	public function add_note( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}aime_subscriber_notes", array(
			'subscriber_id' => absint( $request->get_param( 'id' ) ),
			'created_by'    => get_current_user_id(),
			'type'          => sanitize_text_field( $request->get_param( 'type' ) ?? 'note' ),
			'title'         => sanitize_text_field( $request->get_param( 'title' ) ?? '' ),
			'description'   => wp_kses_post( $request->get_param( 'description' ) ?? '' ),
			'created_at'    => current_time( 'mysql', true ),
		) );
		return new \WP_REST_Response( array( 'id' => (int) $wpdb->insert_id, 'message' => __( 'Note added.', 'ai-marketing-expert' ) ), 201 );
	}

	public function delete_note( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}aime_subscriber_notes", array(
			'id'            => absint( $request->get_param( 'note_id' ) ),
			'subscriber_id' => absint( $request->get_param( 'id' ) ),
		) );
		return new \WP_REST_Response( array( 'message' => __( 'Note deleted.', 'ai-marketing-expert' ) ) );
	}

	/* ================================================================
	 *  IMPORT
	 * ============================================================= */

	public function import( \WP_REST_Request $request ): \WP_REST_Response {
		$source = sanitize_text_field( $request->get_param( 'source' ) ?? 'csv' );

		switch ( $source ) {
			case 'csv':
				return $this->import_csv( $request );
			case 'wp_users':
				return $this->import_wp_users( $request );
			case 'woocommerce':
				return $this->import_woocommerce( $request );
			default:
				return new \WP_REST_Response( array( 'message' => __( 'Unknown import source.', 'ai-marketing-expert' ) ), 400 );
		}
	}

	private function import_csv( \WP_REST_Request $request ): \WP_REST_Response {
		$rows            = $request->get_param( 'contacts' ) ?? array();
		$list_ids        = array_map( 'absint', $request->get_param( 'list_ids' ) ?? array() );
		$tag_ids         = array_map( 'absint', $request->get_param( 'tag_ids' ) ?? array() );
		$update_existing = (bool) $request->get_param( 'update_existing' );
		$new_status      = sanitize_text_field( $request->get_param( 'new_status' ) ?? 'subscribed' );
		$validation      = $this->get_import_validation_options( $request );

		// Fallback: also accept singular list_id for backwards compat.
		if ( empty( $list_ids ) ) {
			$single = absint( $request->get_param( 'list_id' ) );
			if ( $single ) {
				$list_ids = array( $single );
			}
		}

		$allowed_statuses = array( 'subscribed', 'pending', 'unsubscribed', 'bounced', 'complained' );
		if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
			$new_status = 'subscribed';
		}

		// Fields that map directly to DB columns.
		$text_fields = array(
			'first_name', 'last_name', 'phone',
			'address_line_1', 'address_line_2',
			'city', 'state', 'postal_code', 'country',
			'date_of_birth', 'prefix',
		);

		$imported = 0;
		$skipped  = 0;
		$updated  = 0;
		$skip_reasons = array();

		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$existing_normalized = $this->get_existing_normalized_email_map();

		foreach ( $rows as $row ) {
			$email = sanitize_email( $row['email'] ?? '' );
			$email_valid = EmailValidator::validate( $email, 'import', $validation );
			if ( is_wp_error( $email_valid ) ) {
				$this->add_skip_reason( $skip_reasons, $email_valid->get_error_code() );
				$skipped++;
				continue;
			}
			if ( ! is_email( $email ) ) {
				$this->add_skip_reason( $skip_reasons, 'invalid_email' );
				$skipped++;
				continue;
			}

			// Build data array from mapped fields.
			$data = array( 'email' => $email );
			foreach ( $text_fields as $field ) {
				if ( ! empty( $row[ $field ] ) ) {
					$data[ $field ] = sanitize_text_field( $row[ $field ] );
				}
			}

			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', $subscribers_table, $email ) );

			if ( $exists ) {
				if ( $update_existing ) {
					// Update the existing subscriber with mapped fields (skip email).
					$update_data = $data;
					unset( $update_data['email'] );
					$update_data['status'] = $new_status;
					if ( ! empty( $update_data ) ) {
						$wpdb->update( $subscribers_table, $update_data, array( 'id' => (int) $exists ) );
					}
					// Attach (additive) lists & tags.
					if ( ! empty( $list_ids ) ) {
						$this->attach_pivot( (int) $exists, $list_ids, 'list' );
					}
					if ( ! empty( $tag_ids ) ) {
						$this->attach_pivot( (int) $exists, $tag_ids, 'tag' );
					}
					$updated++;
				} else {
					$this->add_skip_reason( $skip_reasons, 'duplicate' );
					$skipped++;
				}
				continue;
			}

			$normalized_email = EmailValidator::normalize_duplicate_email_key( $email );
			if ( ! empty( $validation['skip_spam_patterns'] ) && isset( $existing_normalized[ $normalized_email ] ) ) {
				$this->add_skip_reason( $skip_reasons, 'gmail_dot_variant' );
				$skipped++;
				continue;
			}

			$data['status']     = $new_status;
			$data['source']     = 'csv_import';
			$data['hash']       = md5( $email . wp_generate_uuid4() );
			$data['created_at'] = current_time( 'mysql', true );

			$wpdb->insert( $subscribers_table, $data );
			$sid = (int) $wpdb->insert_id;

			if ( ! empty( $list_ids ) ) {
				$this->attach_pivot( $sid, $list_ids, 'list' );
			}
			if ( ! empty( $tag_ids ) ) {
				$this->attach_pivot( $sid, $tag_ids, 'tag' );
			}
			do_action( 'aime_subscriber_created', $sid, $data );
			$imported++;
			$existing_normalized[ $normalized_email ] = $email;
		}

		return new \WP_REST_Response( array(
			'imported' => $imported,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'skip_reasons' => $skip_reasons,
			'message'  => sprintf(
				/* translators: %1$d: imported count, %2$d: updated count, %3$d: skipped count */
				__( '%1$d imported, %2$d updated, %3$d skipped.', 'ai-marketing-expert' ),
				$imported,
				$updated,
				$skipped
			),
		) );
	}

	private function import_wp_users( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$this->prepare_long_import_request();
		$validation = $this->get_import_validation_options( $request );
		$options    = $this->get_import_assignment_options( $request );

		$users    = get_users( array( 'fields' => array( 'ID', 'user_email', 'user_login', 'display_name' ) ) );
		$this->prime_import_mx_cache( wp_list_pluck( $users, 'user_email' ), $validation );
		$existing_normalized = $this->get_existing_normalized_email_map();
		$imported = 0;
		$updated  = 0;
		$skipped  = 0;
		$skip_reasons = array();

		foreach ( $users as $user ) {
			$email = sanitize_email( $user->user_email );
			$email_valid = EmailValidator::validate( $email, 'import', $validation );
			if ( is_wp_error( $email_valid ) ) {
				$this->add_skip_reason( $skip_reasons, $email_valid->get_error_code() );
				$skipped++;
				continue;
			}
			if ( ! is_email( $email ) ) {
				$this->add_skip_reason( $skip_reasons, 'invalid_email' );
				$skipped++;
				continue;
			}
			if ( ! empty( $validation['skip_spam_patterns'] ) && ( EmailValidator::is_suspicious_import_identity( $user->user_login ?? '' ) || EmailValidator::is_suspicious_import_identity( $user->display_name ?? '' ) ) ) {
				$this->add_skip_reason( $skip_reasons, 'spam_pattern' );
				$skipped++;
				continue;
			}

			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', $subscribers_table, $email ) );
			if ( $exists ) {
				if ( $options['update_existing'] ) {
					$this->update_imported_subscriber( (int) $exists, array(
						'first_name' => sanitize_text_field( explode( ' ', $user->display_name, 2 )[0] ?? '' ),
						'last_name'  => sanitize_text_field( explode( ' ', $user->display_name, 2 )[1] ?? '' ),
						'status'     => $options['new_status'],
					), $options );
					$updated++;
					continue;
				}
				$this->add_skip_reason( $skip_reasons, 'duplicate' );
				$skipped++;
				continue;
			}
			$normalized_email = EmailValidator::normalize_duplicate_email_key( $email );
			if ( ! empty( $validation['skip_spam_patterns'] ) && isset( $existing_normalized[ $normalized_email ] ) ) {
				$this->add_skip_reason( $skip_reasons, 'gmail_dot_variant' );
				$skipped++;
				continue;
			}
			$names = explode( ' ', $user->display_name, 2 );
			$wpdb->insert( $subscribers_table, array(
				'user_id'    => $user->ID,
				'email'      => $email,
				'first_name' => sanitize_text_field( $names[0] ?? '' ),
				'last_name'  => sanitize_text_field( $names[1] ?? '' ),
				'status'     => $options['new_status'],
				'source'     => 'wp_users',
				'hash'       => md5( $email . wp_generate_uuid4() ),
				'created_at' => current_time( 'mysql', true ),
			) );
			$sid = (int) $wpdb->insert_id;
			$this->attach_import_options( $sid, $options );
			do_action( 'aime_subscriber_created', $sid, array( 'email' => $email, 'first_name' => $names[0] ?? '', 'last_name' => $names[1] ?? '', 'source' => 'wp_users' ) );
			$imported++;
			$existing_normalized[ $normalized_email ] = $email;
		}

		return new \WP_REST_Response( array( 'imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'skip_reasons' => $skip_reasons, 'message' => sprintf(
			/* translators: %d: number of imported contacts */
			__( '%d contacts imported from WordPress users.', 'ai-marketing-expert' ),
			$imported
		) ) );
	}

	private function import_woocommerce( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'WooCommerce not active.', 'ai-marketing-expert' ) ), 400 );
		}

		$options             = $this->get_import_assignment_options( $request );
		$validation          = $this->get_import_validation_options( $request );
		$existing_normalized = $this->get_existing_normalized_email_map();
		$customers           = get_users( array( 'role' => 'customer', 'number' => 500 ) );

		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$imported          = 0;
		$updated           = 0;
		$skipped           = 0;
		$skip_reasons      = array();

		foreach ( $customers as $customer ) {
			$email = sanitize_email( $customer->user_email );
			if ( ! $email ) {
				$this->add_skip_reason( $skip_reasons, 'invalid_email' );
				$skipped++;
				continue;
			}

			$email_valid = EmailValidator::validate( $email, 'import', $validation );
			if ( is_wp_error( $email_valid ) ) {
				$this->add_skip_reason( $skip_reasons, $email_valid->get_error_code() );
				$skipped++;
				continue;
			}

			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', $subscribers_table, $email ) );
			if ( $exists ) {
				if ( $options['update_existing'] ) {
					$this->update_imported_subscriber( (int) $exists, array(
						'first_name' => sanitize_text_field( $customer->first_name ?: ( explode( ' ', $customer->display_name, 2 )[0] ?? '' ) ),
						'last_name'  => sanitize_text_field( $customer->last_name ?: ( explode( ' ', $customer->display_name, 2 )[1] ?? '' ) ),
						'status'     => $options['new_status'],
					), $options );
					$updated++;
					continue;
				}
				$this->add_skip_reason( $skip_reasons, 'duplicate' );
				$skipped++;
				continue;
			}
			$normalized_email = EmailValidator::normalize_duplicate_email_key( $email );
			if ( ! empty( $validation['skip_spam_patterns'] ) && isset( $existing_normalized[ $normalized_email ] ) ) {
				$this->add_skip_reason( $skip_reasons, 'gmail_dot_variant' );
				$skipped++;
				continue;
			}
			$wpdb->insert( $subscribers_table, array(
				'user_id'    => $customer->ID,
				'email'      => $email,
				'first_name' => sanitize_text_field( $customer->first_name ?: ( explode( ' ', $customer->display_name, 2 )[0] ?? '' ) ),
				'last_name'  => sanitize_text_field( $customer->last_name ?: ( explode( ' ', $customer->display_name, 2 )[1] ?? '' ) ),
				'status'     => $options['new_status'],
				'source'     => 'woocommerce',
				'hash'       => md5( $email . wp_generate_uuid4() ),
				'created_at' => current_time( 'mysql', true ),
			) );
			$sid = (int) $wpdb->insert_id;
			$this->attach_import_options( $sid, $options );
			do_action( 'aime_subscriber_created', $sid, array( 'email' => $email, 'first_name' => $customer->first_name ?? '', 'last_name' => $customer->last_name ?? '', 'source' => 'woocommerce' ) );
			$imported++;
			$existing_normalized[ $normalized_email ] = $email;
		}

		return new \WP_REST_Response( array( 'imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'skip_reasons' => $skip_reasons, 'message' => sprintf(
			/* translators: %d: number of imported contacts */
			__( '%d contacts imported from WooCommerce.', 'ai-marketing-expert' ),
			$imported
		) ) );
	}

	/* ================================================================
	 *  PRIVATE HELPERS
	 * ============================================================= */

	private function get_import_validation_options( \WP_REST_Request $request ): array {
		$options = $request->get_param( 'validation' );
		$options = is_array( $options ) ? $options : array();

		$validation = array(
			'skip_invalid_format' => array_key_exists( 'skip_invalid_format', $options ) ? (bool) $options['skip_invalid_format'] : true,
			'skip_disposable'     => array_key_exists( 'skip_disposable', $options ) ? (bool) $options['skip_disposable'] : true,
			'skip_test_fake'      => array_key_exists( 'skip_test_fake', $options ) ? (bool) $options['skip_test_fake'] : true,
			'skip_role_based'     => array_key_exists( 'skip_role_based', $options ) ? (bool) $options['skip_role_based'] : false,
			'skip_spam_patterns'  => array_key_exists( 'skip_spam_patterns', $options ) ? (bool) $options['skip_spam_patterns'] : true,
			'check_mx'            => array_key_exists( 'check_mx', $options ) ? (bool) $options['check_mx'] : false,
		);

		if ( ! aime_has_pro() ) {
			$validation['skip_disposable']    = false;
			$validation['skip_role_based']    = false;
			$validation['skip_spam_patterns'] = false;
			$validation['check_mx']           = false;
		}

		return $validation;
	}

	private function get_import_assignment_options( \WP_REST_Request $request ): array {
		$list_ids        = array_map( 'absint', $request->get_param( 'list_ids' ) ?? array() );
		$tag_ids         = array_map( 'absint', $request->get_param( 'tag_ids' ) ?? array() );
		$update_existing = (bool) $request->get_param( 'update_existing' );
		$new_status      = sanitize_text_field( $request->get_param( 'new_status' ) ?? 'subscribed' );

		if ( empty( $list_ids ) ) {
			$single = absint( $request->get_param( 'list_id' ) );
			if ( $single ) {
				$list_ids = array( $single );
			}
		}

		$allowed_statuses = array( 'subscribed', 'pending', 'unsubscribed', 'bounced', 'complained' );
		if ( (bool) $request->get_param( 'double_optin' ) ) {
			$new_status = 'pending';
		} elseif ( ! in_array( $new_status, $allowed_statuses, true ) ) {
			$new_status = 'subscribed';
		}

		return array(
			'list_ids'        => $list_ids,
			'tag_ids'         => $tag_ids,
			'update_existing' => $update_existing,
			'new_status'      => $new_status,
		);
	}

	private function attach_import_options( int $subscriber_id, array $options ): void {
		if ( ! empty( $options['list_ids'] ) ) {
			$this->attach_pivot( $subscriber_id, $options['list_ids'], 'list' );
		}
		if ( ! empty( $options['tag_ids'] ) ) {
			$this->attach_pivot( $subscriber_id, $options['tag_ids'], 'tag' );
		}
	}

	private function update_imported_subscriber( int $subscriber_id, array $data, array $options ): void {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( $wpdb->prefix . 'aime_subscribers', $data, array( 'id' => $subscriber_id ) );
		$this->attach_import_options( $subscriber_id, $options );
	}

	private function add_skip_reason( array &$reasons, string $reason ): void {
		$reasons[ $reason ] = ( $reasons[ $reason ] ?? 0 ) + 1;
	}

	private function get_existing_normalized_email_map(): array {
		global $wpdb;
		$emails = $wpdb->get_col( "SELECT email FROM {$wpdb->prefix}aime_subscribers WHERE email LIKE '%@gmail.com' OR email LIKE '%@googlemail.com'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$map    = array();

		foreach ( $emails as $email ) {
			$map[ EmailValidator::normalize_duplicate_email_key( (string) $email ) ] = strtolower( trim( (string) $email ) );
		}

		return $map;
	}

	private function prepare_long_import_request(): void {
		if ( function_exists( 'wp_is_ini_value_changeable' ) && wp_is_ini_value_changeable( 'max_execution_time' ) ) {
			@ini_set( 'max_execution_time', '0' ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
	}

	private function prime_import_mx_cache( array $emails, array $validation ): void {
		if ( empty( $validation['check_mx'] ) ) {
			return;
		}

		$domains = array();
		foreach ( $emails as $email ) {
			$email = strtolower( trim( (string) $email ) );
			if ( ! is_email( $email ) || strpos( $email, '@' ) === false ) {
				continue;
			}

			$domain = substr( strrchr( $email, '@' ), 1 );
			if ( $domain ) {
				$domains[ $domain ] = true;
			}
		}

		foreach ( array_keys( $domains ) as $domain ) {
			EmailValidator::has_mailable_domain( $domain );
		}
	}

	private function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'aime_subscribers', $id ) );
		return $row ?: null;
	}

	private function sanitize_subscriber( \WP_REST_Request $r ): array {
		$data = array();
		$fields = array(
			'email'         => 'sanitize_email',
			'first_name'    => 'sanitize_text_field',
			'last_name'     => 'sanitize_text_field',
			'status'        => 'sanitize_text_field',
			'contact_type'  => 'sanitize_text_field',
			'source'        => 'sanitize_text_field',
			'phone'         => 'sanitize_text_field',
			'address_line_1' => 'sanitize_text_field',
			'address_line_2' => 'sanitize_text_field',
			'postal_code'   => 'sanitize_text_field',
			'city'          => 'sanitize_text_field',
			'state'         => 'sanitize_text_field',
			'country'       => 'sanitize_text_field',
			'timezone'      => 'sanitize_text_field',
			'date_of_birth' => 'sanitize_text_field',
			'prefix'        => 'sanitize_text_field',
			'avatar'        => 'esc_url_raw',
		);

		foreach ( $fields as $key => $sanitizer ) {
			if ( $r->has_param( $key ) ) {
				$data[ $key ] = call_user_func( $sanitizer, $r->get_param( $key ) );
			}
		}

		// Status must be one of the known values; drop anything else so an
		// arbitrary string can't end up in the status column.
		if ( isset( $data['status'] ) && ! in_array( $data['status'], array( 'subscribed', 'pending', 'unsubscribed', 'bounced', 'complained' ), true ) ) {
			unset( $data['status'] );
		}

		return $data;
	}

	private function attach_pivot_data( array $items ): array {
		if ( empty( $items ) ) {
			return $items;
		}

		global $wpdb;
		$pivot_table = $wpdb->prefix . 'aime_subscriber_pivot';
		$tags_table  = $wpdb->prefix . 'aime_tags';
		$lists_table = $wpdb->prefix . 'aime_lists';
		$ids = wp_list_pluck( $items, 'id' );
		$safe_ids = array_map( 'absint', $ids );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$pivots_sql = $wpdb->prepare(
			'SELECT sp.subscriber_id, sp.object_id, sp.object_type, COALESCE(t.title, l.title) AS title FROM %i sp LEFT JOIN %i t ON sp.object_type = \'tag\' AND t.id = sp.object_id LEFT JOIN %i l ON sp.object_type = \'list\' AND l.id = sp.object_id WHERE sp.subscriber_id IN (' . $this->get_int_placeholders( $safe_ids ) . ')',
			$pivot_table,
			$tags_table,
			$lists_table,
			...$safe_ids
		);

		$pivots = $wpdb->get_results( $pivots_sql );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$map = array();
		foreach ( $pivots as $pv ) {
			$oid   = (int) ( $pv->object_id ?? 0 );
			$title = trim( (string) ( $pv->title ?? '' ) );
			if ( $oid <= 0 || '' === $title ) {
				continue;
			}
			$map[ $pv->subscriber_id ][ $pv->object_type ][] = array(
				'id'    => $oid,
				'title' => $title,
			);
		}

		foreach ( $items as &$item ) {
			$item->tags  = $map[ $item->id ]['tag']  ?? array();
			$item->lists = $map[ $item->id ]['list'] ?? array();
		}

		return $items;
	}

	private function get_int_placeholders( array $ids ): string {
		return implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	}

	private function sync_pivot( int $subscriber_id, array $ids, string $type ): void {
		global $wpdb;
		$pivot_table = $wpdb->prefix . 'aime_subscriber_pivot';
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$old_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT object_id FROM %i WHERE subscriber_id = %d AND object_type = %s', $pivot_table, $subscriber_id, $type ) ) );

		// Remove old.
		$wpdb->delete( $pivot_table, array( 'subscriber_id' => $subscriber_id, 'object_type' => $type ) );

		// Insert new.
		$this->attach_pivot( $subscriber_id, $ids, $type, false );

		foreach ( array_diff( $ids, $old_ids ) as $added_id ) {
			do_action( "aime_subscriber_{$type}_added", $subscriber_id, (int) $added_id, $type );
		}
		foreach ( array_diff( $old_ids, $ids ) as $removed_id ) {
			do_action( "aime_subscriber_{$type}_removed", $subscriber_id, (int) $removed_id, $type );
		}
	}

	private function attach_pivot( int $subscriber_id, array $ids, string $type, bool $fire_events = true ): void {
		global $wpdb;
		$pivot_table = $wpdb->prefix . 'aime_subscriber_pivot';
		$now         = current_time( 'mysql', true );

		foreach ( $ids as $oid ) {
			$oid = absint( $oid );
			if ( ! $oid ) {
				continue;
			}
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO %i (subscriber_id, object_id, object_type, status, created_at)
					 VALUES (%d, %d, %s, 'active', %s)",
					$pivot_table,
					$subscriber_id,
					$oid,
					$type,
					$now
				)
			);
			if ( $fire_events && $wpdb->rows_affected > 0 ) {
				do_action( "aime_subscriber_{$type}_added", $subscriber_id, $oid, $type );
			}
		}
	}

	private function detach_pivot( int $subscriber_id, array $ids, string $type ): void {
		global $wpdb;
		$pivot_table = $wpdb->prefix . 'aime_subscriber_pivot';

		foreach ( $ids as $oid ) {
			$oid = absint( $oid );
			$wpdb->delete( $pivot_table, array(
				'subscriber_id' => $subscriber_id,
				'object_id'     => $oid,
				'object_type'   => $type,
			) );
			if ( $wpdb->rows_affected > 0 ) {
				do_action( "aime_subscriber_{$type}_removed", $subscriber_id, $oid, $type );
			}
		}
	}

	/**
	 * Resolve an array of tag names to IDs (find-or-create).
	 *
	 * @param string[] $names Tag titles.
	 * @return int[] Tag IDs.
	 */
	private function resolve_tag_names( array $names ): array {
		global $wpdb;
		$tags_table = $wpdb->prefix . 'aime_tags';
		$ids        = array();

		foreach ( $names as $name ) {
			$name = trim( $name );
			if ( '' === $name ) {
				continue;
			}

			$slug = sanitize_title( $name );

			// Look up existing tag by slug.
			$existing = $wpdb->get_var(
				$wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s', $tags_table, $slug )
			);

			if ( $existing ) {
				$ids[] = (int) $existing;
				continue;
			}

			// Create new tag.
			$wpdb->insert( $tags_table, array(
				'title'      => $name,
				'slug'       => $slug,
				'created_at' => current_time( 'mysql', true ),
			) );

			$ids[] = (int) $wpdb->insert_id;
		}

		return $ids;
	}

	private function get_notes( int $subscriber_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE subscriber_id = %d ORDER BY created_at DESC', $wpdb->prefix . 'aime_subscriber_notes', $subscriber_id )
		) ?: array();
	}

	private function get_activity( int $subscriber_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE object_type = 'subscriber' AND object_id = %d ORDER BY created_at DESC LIMIT 50", $wpdb->prefix . 'aime_activity_log', $subscriber_id )
		) ?: array();
	}

	private function get_meta( int $subscriber_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE subscriber_id = %d', $wpdb->prefix . 'aime_subscriber_meta', $subscriber_id )
		);
		$meta = array();
		foreach ( $rows as $r ) {
			$meta[ $r->meta_key ] = $r->meta_value;
		}
		return $meta;
	}

	private function save_meta( int $subscriber_id, array $fields ): void {
		global $wpdb;
		$meta_table = $wpdb->prefix . 'aime_subscriber_meta';
		$now        = current_time( 'mysql', true );

		foreach ( $fields as $key => $value ) {
			$key = sanitize_key( $key );
			$existing = $wpdb->get_var(
				$wpdb->prepare( 'SELECT id FROM %i WHERE subscriber_id = %d AND meta_key = %s', $meta_table, $subscriber_id, $key )
			);
			if ( $existing ) {
				$wpdb->update( $meta_table, array( 'meta_value' => sanitize_text_field( $value ), 'updated_at' => $now ), array( 'id' => $existing ) );
			} else {
				$wpdb->insert( $meta_table, array(
					'subscriber_id' => $subscriber_id,
					'meta_key'      => $key,
					'meta_value'    => sanitize_text_field( $value ),
					'created_at'    => $now,
				) );
			}
		}
	}

	private function log_activity( int $subscriber_id, string $action, string $desc ): void {
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}aime_activity_log", array(
			'object_type' => 'subscriber',
			'object_id'   => $subscriber_id,
			'action'      => $action,
			'description' => $desc,
			'activity_by' => get_current_user_id(),
			'created_at'  => current_time( 'mysql', true ),
		) );
	}

	/* ================================================================
	 *  PUBLIC SUBSCRIBE (front-end form / REST)
	 * ============================================================= */

	/**
	 * Handle public subscription from shortcode form or external call.
	 * Rate-limited, honeypot-checked, safe against email enumeration.
	 */
	public function public_subscribe( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';

		$has_form_token = (bool) $request->get_param( 'aime_token' );
		if ( $has_form_token && ! $this->validate_public_subscribe_token( $request ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Invalid subscription request. Please refresh the page and try again.', 'ai-marketing-expert' ) ), 403 );
		}

		// ── Honeypot check ──
		if ( $request->get_param( 'website' ) ) {
			// Bots fill hidden fields – silently succeed.
			return new \WP_REST_Response( array( 'message' => __( 'Thank you for subscribing!', 'ai-marketing-expert' ) ) );
		}

		// ── Rate limit: 5 attempts per IP per minute ──
		$ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
		$key = 'aime_sub_' . md5( $ip );
		$hit = (int) get_transient( $key );
		if ( $hit >= 5 ) {
			return new \WP_REST_Response( array( 'message' => __( 'Too many requests. Please try again later.', 'ai-marketing-expert' ) ), 429 );
		}
		set_transient( $key, $hit + 1, MINUTE_IN_SECONDS );

		if ( ! $has_form_token ) {
			$fallback_key = 'aime_sub_unsigned_' . md5( $ip );
			$fallback_hit = (int) get_transient( $fallback_key );
			if ( $fallback_hit >= 2 ) {
				return new \WP_REST_Response( array( 'message' => __( 'Too many requests. Please try again later.', 'ai-marketing-expert' ) ), 429 );
			}
			set_transient( $fallback_key, $fallback_hit + 1, MINUTE_IN_SECONDS );
		}

		// ── Sanitize ──
		$email      = sanitize_email( $request->get_param( 'email' ) );
		$first_name = $this->sanitize_limited_text( $request->get_param( 'first_name' ) ?? '', 120 );
		$last_name  = $this->sanitize_limited_text( $request->get_param( 'last_name' ) ?? '', 120 );
		$list_id    = absint( $request->get_param( 'list_id' ) );

		if ( ! is_email( $email ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Please enter a valid email address.', 'ai-marketing-expert' ) ), 400 );
		}

		// Validate email quality (disposable, fake domain, MX check).
		$valid = EmailValidator::validate( $email, 'form' );
		if ( is_wp_error( $valid ) ) {
			return new \WP_REST_Response( array( 'message' => $valid->get_error_message() ), 400 );
		}

		// ── Check for existing subscriber ──
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM %i WHERE email = %s', $subscribers_table, $email ) );

		if ( $existing ) {
			// Already subscribed – return success without revealing details.
			if ( 'unsubscribed' === $existing->status ) {
				// Re-subscribe them.
				$double_optin = $this->is_double_optin_enabled();
				$new_status   = $double_optin ? 'pending' : 'subscribed';
				$wpdb->update( $subscribers_table, array( 'status' => $new_status ), array( 'id' => $existing->id ) );

				if ( $list_id ) {
					$this->sync_pivot( (int) $existing->id, array( $list_id ), 'list' );
				}
				$this->log_activity( (int) $existing->id, 'resubscribed', 'Re-subscribed via form' );

				if ( $double_optin ) {
					$this->send_double_optin_email( (int) $existing->id, $email, $first_name );
				}
			}
			return new \WP_REST_Response( array( 'message' => __( 'Thank you for subscribing!', 'ai-marketing-expert' ) ) );
		}

		// ── Create new subscriber ──
		$double_optin = $this->is_double_optin_enabled();
		$status       = $double_optin ? 'pending' : 'subscribed';

		$data = array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'status'     => $status,
			'source'     => 'form',
			'hash'       => md5( $email . wp_generate_uuid4() ),
			'created_at' => current_time( 'mysql', true ),
		);

		$wpdb->insert( $subscribers_table, $data );
		$id = (int) $wpdb->insert_id;

		if ( $list_id ) {
			$this->sync_pivot( $id, array( $list_id ), 'list' );
		}

		$this->log_activity( $id, 'created', 'Subscribed via form' );

		if ( $double_optin ) {
			$this->send_double_optin_email( $id, $email, $first_name );
		}

		/**
		 * Fires after a public subscriber is created.
		 *
		 * @param int    $id     Subscriber ID.
		 * @param array  $data   Subscriber data.
		 * @param int    $list_id Attached list ID (0 if none).
		 */
		do_action( 'aime_public_subscriber_created', $id, $data, $list_id );

		/**
		 * Fires after any subscriber is created (unified hook).
		 */
		do_action( 'aime_subscriber_created', $id, $data );

		return new \WP_REST_Response( array( 'message' => __( 'Thank you for subscribing!', 'ai-marketing-expert' ) ), 201 );
	}

	/**
	 * Send double opt-in confirmation email.
	 */
	private function send_double_optin_email( int $subscriber_id, string $email, string $first_name ): void {
		$hash = \WPSpace\AiMarketingExpert\Modules\EmailMarketing\EmailMarketingModule::create_tracking_hash( 0, $subscriber_id );

		$confirm_url = add_query_arg(
			array(
				'aime_track' => 'confirm',
				'hash'       => $hash,
			),
			home_url()
		);

		$site_name  = get_bloginfo( 'name' );
		$from_name  = get_option( 'aime_from_name', $site_name );
		$from_email = get_option( 'aime_from_email', get_option( 'admin_email' ) );
		$reply_to   = get_option( 'aime_reply_to', '' );
		$greeting   = $first_name ? esc_html( $first_name ) : __( 'there', 'ai-marketing-expert' );

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Confirm your subscription to %s', 'ai-marketing-expert' ),
			$site_name
		);

		$body = '<div style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:560px;margin:0 auto;padding:2rem">'
			. '<h2 style="color:#1e293b">' . sprintf(
				/* translators: %s: site name */
				__( 'Welcome to %s!', 'ai-marketing-expert' ),
				esc_html( $site_name )
			) . '</h2>'
			. '<p>' . sprintf(
				/* translators: %s: subscriber first name or "there" */
				__( 'Hi %s, please confirm your subscription by clicking the button below:', 'ai-marketing-expert' ),
				$greeting
			) . '</p>'
			. '<p style="text-align:center;margin:2rem 0">'
			. '<a href="' . esc_url( $confirm_url ) . '" style="display:inline-block;background:#3858e9;color:#fff;padding:12px 32px;border-radius:6px;text-decoration:none;font-weight:600">'
			. esc_html__( 'Confirm Subscription', 'ai-marketing-expert' )
			. '</a></p>'
			. '<p style="color:#94a3b8;font-size:0.85rem">'
			. esc_html__( 'If you did not request this subscription, you can safely ignore this email.', 'ai-marketing-expert' )
			. '</p></div>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			"From: {$from_name} <{$from_email}>",
		);
		if ( is_email( $reply_to ) ) {
			$headers[] = "Reply-To: {$reply_to}";
		}

		wp_mail( $email, $subject, $body, $headers );
	}

	private function is_double_optin_enabled(): bool {
		$global_settings = get_option( 'aime_settings', array() );
		return (bool) get_option( 'aime_double_optin', $global_settings['double_optin'] ?? false );
	}

	private function validate_public_subscribe_token( \WP_REST_Request $request ): bool {
		$timestamp = absint( $request->get_param( 'aime_ts' ) );
		$token     = sanitize_text_field( $request->get_param( 'aime_token' ) ?? '' );
		$list_id   = absint( $request->get_param( 'list_id' ) );

		if ( ! $timestamp || ! $token ) {
			return false;
		}

		if ( $timestamp < time() - HOUR_IN_SECONDS || $timestamp > time() + MINUTE_IN_SECONDS ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $list_id . '|' . $timestamp, wp_salt( 'nonce' ) );
		return hash_equals( $expected, $token );
	}

	/* ================================================================
	 *  WEBHOOK SUBSCRIBE (API key authenticated)
	 * ============================================================= */

	/**
	 * POST /email/webhook/subscribe — Create a subscriber via external webhook.
	 *
	 * Authenticated via HMAC signature (X-Aime-Timestamp + X-Aime-Signature,
	 * preferred) or static API key (X-API-Key header only) — see
	 * RestApi::validate_api_key().
	 * Supports: email, first_name, last_name, list_id, tag_ids, tag_names, status, custom_fields.
	 *
	 * tag_names accepts an array of tag title strings (e.g. ["Click to Top"]).
	 * Tags are found by slug or auto-created. Merged with any explicit tag_ids.
	 * For existing subscribers tags are ADDITIVE — previous tags are kept.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function webhook_subscribe( \WP_REST_Request $request ): \WP_REST_Response {
		// Validate API key.
		if ( ! \WPSpace\AiMarketingExpert\RestApi::validate_api_key( $request ) ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Invalid or missing API key.', 'ai-marketing-expert' ) ),
				401
			);
		}

		// Rate limit: filterable ceiling for authenticated webhook subscriptions (default 3600/min).
		$max_rate = (int) apply_filters( 'aime_webhook_rate_limit', 3600, $request );
		if ( $max_rate > 0 ) {
			$ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
			$key = 'aime_wh_' . md5( $ip );
			$hit = (int) get_transient( $key );
			if ( $hit >= $max_rate ) {
				return new \WP_REST_Response(
					array( 'message' => __( 'Too many requests. Please try again later.', 'ai-marketing-expert' ) ),
					429
				);
			}
			set_transient( $key, $hit + 1, MINUTE_IN_SECONDS );
		}

		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$meta_table        = $wpdb->prefix . 'aime_subscriber_meta';

		$email      = sanitize_email( $request->get_param( 'email' ) );
		$first_name = $this->sanitize_limited_text( $request->get_param( 'first_name' ) ?? '', 120 );
		$last_name  = $this->sanitize_limited_text( $request->get_param( 'last_name' ) ?? '', 120 );
		$list_id    = absint( $request->get_param( 'list_id' ) );
		$tag_ids    = array_slice( array_map( 'absint', (array) ( $request->get_param( 'tag_ids' ) ?? array() ) ), 0, 50 );
		$tag_names  = array_slice( array_filter( array_map( function ( $tag_name ) {
			return $this->sanitize_limited_text( $tag_name, 120 );
		}, (array) ( $request->get_param( 'tag_names' ) ?? array() ) ) ), 0, 50 );
		$status     = sanitize_text_field( $request->get_param( 'status' ) ?? 'subscribed' );

		// Resolve tag names to IDs (find-or-create).
		if ( ! empty( $tag_names ) ) {
			$tag_ids = array_merge( $tag_ids, $this->resolve_tag_names( $tag_names ) );
			$tag_ids = array_unique( $tag_ids );
		}

		if ( ! is_email( $email ) ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Invalid email address.', 'ai-marketing-expert' ) ),
				400
			);
		}

		// Validate email quality (disposable, fake domain, MX check).
		$email_valid = EmailValidator::validate( $email, 'webhook' );
		if ( is_wp_error( $email_valid ) ) {
			return new \WP_REST_Response(
				array( 'message' => $email_valid->get_error_message() ),
				400
			);
		}

		// Validate status.
		$valid_statuses = array( 'subscribed', 'pending', 'unsubscribed', 'bounced', 'complained' );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			$status = 'subscribed';
		}

		// Check existing.
		$existing = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, status FROM %i WHERE email = %s', $subscribers_table, $email )
		);

		if ( $existing ) {
			// Update existing subscriber: assign list/tags, re-subscribe if unsubscribed.
			$sub_id      = (int) $existing->id;
			$was_resubscribed = false;

			if ( 'unsubscribed' === $existing->status && 'subscribed' === $status ) {
				$wpdb->update( $subscribers_table, array( 'status' => $status ), array( 'id' => $sub_id ) );
				$this->log_activity( $sub_id, 'resubscribed', 'Re-subscribed via webhook' );
				$was_resubscribed = true;
			}

			if ( $list_id ) {
				$this->attach_pivot( $sub_id, array( $list_id ), 'list' );
			}
			if ( ! empty( $tag_ids ) ) {
				$this->attach_pivot( $sub_id, $tag_ids, 'tag' );
			}

			return new \WP_REST_Response( array(
				'id'      => $sub_id,
				'message' => $was_resubscribed
					? __( 'Subscriber re-subscribed.', 'ai-marketing-expert' )
					: __( 'Subscriber already exists. Lists and tags updated.', 'ai-marketing-expert' ),
				'status'  => 'existing',
			) );
		}

		// Create new subscriber.
		$data = array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'status'     => $status,
			'source'     => 'webhook',
			'hash'       => md5( $email . wp_generate_uuid4() ),
			'created_at' => current_time( 'mysql', true ),
		);

		$wpdb->insert( $subscribers_table, $data );
		$id = (int) $wpdb->insert_id;

		if ( $list_id ) {
			$this->sync_pivot( $id, array( $list_id ), 'list' );
		}
		if ( ! empty( $tag_ids ) ) {
			$this->sync_pivot( $id, $tag_ids, 'tag' );
		}

		// Save custom fields if provided.
		$custom_fields = $request->get_param( 'custom_fields' );
		if ( is_array( $custom_fields ) ) {
			foreach ( array_slice( $custom_fields, 0, 50, true ) as $field_key => $field_value ) {
				$wpdb->replace( $meta_table, array(
					'subscriber_id' => $id,
					'meta_key'      => sanitize_key( $field_key ),
					'meta_value'    => $this->sanitize_limited_text( $field_value, 500 ),
				) );
			}
		}

		$this->log_activity( $id, 'created', 'Subscribed via webhook' );

		/** Fires after any subscriber is created (unified hook). */
		do_action( 'aime_subscriber_created', $id, $data );

		return new \WP_REST_Response( array(
			'id'      => $id,
			'message' => __( 'Subscriber created.', 'ai-marketing-expert' ),
			'status'  => 'created',
		), 201 );
	}

	private function sanitize_limited_text( $value, int $max_length ): string {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );
		return substr( $value, 0, $max_length );
	}

	/**
	 * ESP feedback-loop webhook: move complaining recipients to the Complaint list.
	 *
	 * Spam complaints cannot be inferred from SMTP send success, so ESPs (Amazon SES via SNS,
	 * SendGrid, Mailgun, Postmark) POST them here. Mirrors unsubscribe: status → 'complained',
	 * metric row, activity log, status-change hook. Pro-only.
	 */
	public function webhook_complaint( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->handle_status_webhook( $request, 'complained', 'complaint' );
	}

	/**
	 * ESP bounce-notification webhook: move hard-bounced recipients to the Bounced list.
	 */
	public function webhook_bounce( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->handle_status_webhook( $request, 'bounced', 'bounce' );
	}

	/**
	 * Shared handler for complaint/bounce webhooks.
	 *
	 * @param \WP_REST_Request $request     Incoming request (API key + email|emails).
	 * @param string           $new_status  Target subscriber status ('complained'|'bounced').
	 * @param string           $metric_type Metric row type ('complaint'|'bounce').
	 */
	private function handle_status_webhook( \WP_REST_Request $request, string $new_status, string $metric_type ): \WP_REST_Response {
		// Pro gate: automatic complaint/bounce handling is a Pro feature.
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Automatic complaint and bounce handling is available in Pro.', 'ai-marketing-expert' ) ),
				403
			);
		}

		$raw_json = $request->get_body();
		$payload  = json_decode( $raw_json, true );

		// 1. Amazon SES via SNS Subscription Confirmation handshake.
		if ( is_array( $payload ) && isset( $payload['Type'] ) && 'SubscriptionConfirmation' === $payload['Type'] && ! empty( $payload['SubscribeURL'] ) ) {
			wp_remote_get( esc_url_raw( $payload['SubscribeURL'] ), array( 'timeout' => 15 ) );
			return new \WP_REST_Response( array( 'message' => 'Amazon SNS subscription confirmed successfully.' ), 200 );
		}

		// Validate authentication: API key, secret token parameter/header, or validated webhook signature.
		$token           = sanitize_text_field( $request->get_param( 'token' ) ?: ( $request->get_header( 'x-webhook-token' ) ?: '' ) );
		$expected_token  = get_option( 'aime_cron_secret_token' ) ?: get_option( 'aime_webhook_token' );
		$has_valid_token = ( $token && $expected_token && hash_equals( (string) $expected_token, (string) $token ) );
		$is_esp_payload  = is_array( $payload ) && (
			isset( $payload['Type'] ) || // AWS SNS
			isset( $payload[0]['event'] ) || // SendGrid
			isset( $payload['event-data'] ) || // Mailgun
			isset( $payload['RecordType'] ) || isset( $payload['Type'] ) || // Postmark
			isset( $payload['event'] ) // Brevo
		);

		if ( ! \WPSpace\AiMarketingExpert\RestApi::validate_api_key( $request ) && ! $has_valid_token && ! $is_esp_payload ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Invalid or missing API key or authentication token.', 'ai-marketing-expert' ) ),
				401
			);
		}

		// Rate limit: 120 requests per IP per minute.
		$ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
		$key = 'aime_wh_' . md5( $ip );
		$hit = (int) get_transient( $key );
		if ( $hit >= 120 ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Too many requests. Please try again later.', 'ai-marketing-expert' ) ),
				429
			);
		}
		set_transient( $key, $hit + 1, MINUTE_IN_SECONDS );

		$emails = array();

		// Parse Native ESP Payloads:
		// 1. Amazon SES Notification via SNS.
		if ( is_array( $payload ) && isset( $payload['Type'] ) && 'Notification' === $payload['Type'] && ! empty( $payload['Message'] ) ) {
			$msg = json_decode( (string) $payload['Message'], true );
			if ( is_array( $msg ) ) {
				$notif_type = $msg['notificationType'] ?? ( $msg['eventType'] ?? '' );
				if ( 'Bounce' === $notif_type ) {
					$new_status  = 'bounced';
					$metric_type = 'bounce';
					foreach ( (array) ( $msg['bounce']['bouncedRecipients'] ?? array() ) as $r ) {
						if ( ! empty( $r['emailAddress'] ) ) {
							$emails[] = sanitize_email( $r['emailAddress'] );
						}
					}
				} elseif ( 'Complaint' === $notif_type ) {
					$new_status  = 'complained';
					$metric_type = 'complaint';
					foreach ( (array) ( $msg['complaint']['complainedRecipients'] ?? array() ) as $r ) {
						if ( ! empty( $r['emailAddress'] ) ) {
							$emails[] = sanitize_email( $r['emailAddress'] );
						}
					}
				}
			}
		}

		// 2. SendGrid event array.
		if ( is_array( $payload ) && isset( $payload[0]['event'] ) ) {
			foreach ( $payload as $event ) {
				$evt_name = strtolower( (string) ( $event['event'] ?? '' ) );
				$evt_mail = sanitize_email( $event['email'] ?? '' );
				if ( ! $evt_mail ) {
					continue;
				}
				if ( in_array( $evt_name, array( 'bounce', 'dropped', 'blocked' ), true ) ) {
					$emails[]    = $evt_mail;
					$new_status  = 'bounced';
					$metric_type = 'bounce';
				} elseif ( in_array( $evt_name, array( 'spamreport', 'complaint' ), true ) ) {
					$emails[]    = $evt_mail;
					$new_status  = 'complained';
					$metric_type = 'complaint';
				}
			}
		}

		// 3. Mailgun webhook.
		if ( is_array( $payload ) && isset( $payload['event-data'] ) ) {
			$evt      = $payload['event-data'];
			$evt_name = strtolower( (string) ( $evt['event'] ?? '' ) );
			$evt_mail = sanitize_email( $evt['recipient'] ?? '' );
			if ( $evt_mail ) {
				if ( 'failed' === $evt_name && 'permanent' === ( $evt['severity'] ?? '' ) ) {
					$emails[]    = $evt_mail;
					$new_status  = 'bounced';
					$metric_type = 'bounce';
				} elseif ( 'complained' === $evt_name ) {
					$emails[]    = $evt_mail;
					$new_status  = 'complained';
					$metric_type = 'complaint';
				}
			}
		}

		// 4. Postmark webhook.
		if ( is_array( $payload ) && ( isset( $payload['RecordType'] ) || isset( $payload['Type'] ) ) ) {
			$p_type = $payload['RecordType'] ?? $payload['Type'];
			$p_mail = sanitize_email( $payload['Email'] ?? ( $payload['Recipient'] ?? '' ) );
			if ( $p_mail ) {
				if ( stripos( $p_type, 'bounce' ) !== false ) {
					$emails[]    = $p_mail;
					$new_status  = 'bounced';
					$metric_type = 'bounce';
				} elseif ( stripos( $p_type, 'complaint' ) !== false || stripos( $p_type, 'spam' ) !== false ) {
					$emails[]    = $p_mail;
					$new_status  = 'complained';
					$metric_type = 'complaint';
				}
			}
		}

		// 5. Brevo (Sendinblue) webhook.
		if ( is_array( $payload ) && isset( $payload['event'] ) && ! isset( $payload[0] ) ) {
			$b_event = strtolower( (string) $payload['event'] );
			$b_mail  = sanitize_email( $payload['email'] ?? '' );
			if ( $b_mail ) {
				if ( in_array( $b_event, array( 'hard_bounce', 'soft_bounce', 'blocked', 'invalid_email' ), true ) ) {
					$emails[]    = $b_mail;
					$new_status  = 'bounced';
					$metric_type = 'bounce';
				} elseif ( in_array( $b_event, array( 'complaint', 'spam' ), true ) ) {
					$emails[]    = $b_mail;
					$new_status  = 'complained';
					$metric_type = 'complaint';
				}
			}
		}

		// 6. Direct parameters (email or emails[]).
		$single = sanitize_email( $request->get_param( 'email' ) ?? '' );
		if ( $single ) {
			$emails[] = $single;
		}
		foreach ( (array) ( $request->get_param( 'emails' ) ?? array() ) as $candidate ) {
			$candidate = sanitize_email( $candidate );
			if ( $candidate ) {
				$emails[] = $candidate;
			}
		}
		$emails = array_slice( array_unique( array_filter( $emails, 'is_email' ) ), 0, 500 );

		if ( empty( $emails ) ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'No valid email address identified in webhook payload.', 'ai-marketing-expert' ) ),
				400
			);
		}

		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$metrics_table     = $wpdb->prefix . 'aime_campaign_url_metrics';
		$now               = current_time( 'mysql', true );

		$updated   = 0;
		$not_found = 0;

		foreach ( $emails as $email ) {
			$sub = $wpdb->get_row(
				$wpdb->prepare( 'SELECT id, status FROM %i WHERE email = %s', $subscribers_table, $email )
			);
			if ( ! $sub ) {
				$not_found++;
				continue;
			}

			$sub_id     = (int) $sub->id;
			$old_status = (string) $sub->status;

			// Skip if already in the target terminal state.
			if ( $old_status === $new_status ) {
				continue;
			}

			$wpdb->update(
				$subscribers_table,
				array( 'status' => $new_status, 'updated_at' => $now ),
				array( 'id' => $sub_id )
			);

			// Attribute the event to the most recent campaign this address was sent,
			// so the campaign report's Complained/Bounced tab can show it. Without
			// this the metric row lands on campaign_id 0 and is unattributable.
			$campaign_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT campaign_id FROM %i WHERE subscriber_id = %d ORDER BY id DESC LIMIT 1',
					$wpdb->prefix . 'aime_campaign_emails',
					$sub_id
				)
			);

			$wpdb->insert( $metrics_table, array(
				'url_id'        => 0,
				'campaign_id'   => $campaign_id,
				'subscriber_id' => $sub_id,
				'type'          => $metric_type,
				'ip_address'    => $ip,
				'country'       => '',
				'city'          => '',
				'created_at'    => $now,
			) );

			$this->log_activity(
				$sub_id,
				$new_status,
				'complained' === $new_status ? 'Marked as spam complaint via provider webhook' : 'Hard bounce reported via provider webhook'
			);

			/** Fires so automations react to the status change (same hook as manual bulk changes). */
			do_action( 'aime_subscriber_status_change', $sub_id, $old_status, $new_status );

			$updated++;
		}

		return new \WP_REST_Response( array(
			'processed'  => count( $emails ),
			'updated'    => $updated,
			'not_found'  => $not_found,
		) );
	}

	/* ================================================================
	 *  B2B LEAD FINDER / PROSPECTING
	 * ============================================================= */

	/**
	 * Search for prospective B2B leads using AI heuristics & DNS verification.
	 */
	public function search_leads( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'code'    => 'pro_required',
				'message' => __( 'B2B Lead Finder is a Pro feature. Please upgrade to Pro.', 'ai-marketing-expert' ),
			), 403 );
		}

		$industry     = sanitize_text_field( $request->get_param( 'industry' ) ?? '' );
		$role         = sanitize_text_field( $request->get_param( 'role' ) ?? '' );
		$location     = sanitize_text_field( $request->get_param( 'location' ) ?? '' );
		$company_size = sanitize_text_field( $request->get_param( 'company_size' ) ?? '' );
		$keyword      = sanitize_text_field( $request->get_param( 'keyword' ) ?? '' );
		$raw_per_page = $request->get_param( 'per_page' );
		$raw_limit    = $request->get_param( 'limit' );
		$limit        = ( null !== $raw_per_page && '' !== $raw_per_page ) ? absint( $raw_per_page ) : absint( $raw_limit ?: 10 );
		$limit        = min( 50, max( 3, $limit ?: 10 ) );
		$page         = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );

		if ( empty( $industry ) && empty( $role ) && empty( $keyword ) ) {
			return new \WP_REST_Response( array(
				'message' => __( 'Please specify at least an industry, job role, or keyword to search leads.', 'ai-marketing-expert' ),
			), 400 );
		}

		$criteria = array(
			'industry'     => $industry,
			'role'         => $role,
			'location'     => $location,
			'company_size' => $company_size,
			'keyword'      => $keyword,
		);

		$result = B2bScraperService::discover_leads( $criteria, $page, $limit );

		return new \WP_REST_Response( $result );
	}

	/**
	 * Import verified prospective leads into the subscriber CRM.
	 */
	public function import_leads( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'code'    => 'pro_required',
				'message' => __( 'B2B Lead Finder is a Pro feature. Please upgrade to Pro.', 'ai-marketing-expert' ),
			), 403 );
		}

		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$now               = current_time( 'mysql', true );

		$raw_leads = $request->get_param( 'leads' );
		if ( ! is_array( $raw_leads ) || empty( $raw_leads ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'No leads provided to import.', 'ai-marketing-expert' ) ), 400 );
		}

		$list_id   = absint( $request->get_param( 'list_id' ) ?: 0 );
		$tag_names = $request->get_param( 'tag_names' );
		$tag_ids   = ( is_array( $tag_names ) && ! empty( $tag_names ) ) ? $this->resolve_tag_names( $tag_names ) : array();

		$imported = 0;
		$updated  = 0;
		$invalid  = 0;

		foreach ( $raw_leads as $lead ) {
			if ( ! is_array( $lead ) ) {
				continue;
			}
			$email = sanitize_email( $lead['email'] ?? '' );
			if ( ! is_email( $email ) ) {
				$invalid++;
				continue;
			}

			// Validate with EmailValidator.
			$val_check = EmailValidator::validate( $email, 'import' );
			if ( is_wp_error( $val_check ) ) {
				$invalid++;
				continue;
			}

			$first_name = sanitize_text_field( $lead['first_name'] ?? '' );
			$last_name  = sanitize_text_field( $lead['last_name'] ?? '' );
			$company    = sanitize_text_field( $lead['company'] ?? '' );
			$title      = sanitize_text_field( $lead['title'] ?? '' );
			$website    = esc_url_raw( $lead['website'] ?? '' );

			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$subscribers_table} WHERE email = %s", $email ) );
			if ( $existing ) {
				$sub_id      = (int) $existing->id;
				$update_data = array( 'updated_at' => $now );
				if ( $first_name ) {
					$update_data['first_name'] = $first_name;
				}
				if ( $last_name ) {
					$update_data['last_name'] = $last_name;
				}
				$wpdb->update( $subscribers_table, $update_data, array( 'id' => $sub_id ) );
				$updated++;
			} else {
				$insert_data = array(
					'email'      => $email,
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'status'     => 'subscribed',
					'source'     => 'lead_finder',
					'hash'       => md5( $email . wp_generate_uuid4() ),
					'created_at' => $now,
					'updated_at' => $now,
				);
				$ok = $wpdb->insert( $subscribers_table, $insert_data );
				if ( ! $ok ) {
					$invalid++;
					continue;
				}
				$sub_id = (int) $wpdb->insert_id;
				$imported++;
				$this->log_activity( $sub_id, 'created', 'Imported via B2B Lead Finder' );
			}

			// Save custom metadata.
			$meta_fields = array();
			if ( $company ) {
				$meta_fields['company'] = $company;
			}
			if ( $title ) {
				$meta_fields['job_title'] = $title;
			}
			if ( $website ) {
				$meta_fields['website'] = $website;
			}
			if ( ! empty( $meta_fields ) ) {
				$this->save_meta( $sub_id, $meta_fields );
			}

			// Attach to list.
			if ( $list_id > 0 ) {
				$this->sync_pivot( $sub_id, array( $list_id ), 'list' );
			}

			// Attach tags.
			if ( ! empty( $tag_ids ) ) {
				$this->sync_pivot( $sub_id, $tag_ids, 'tag' );
			}
		}

		return new \WP_REST_Response( array(
			'imported' => $imported,
			'updated'  => $updated,
			'invalid'  => $invalid,
			'message'  => sprintf(
				/* translators: 1: imported count, 2: updated count */
				__( '%1$d leads imported, %2$d existing contacts updated.', 'ai-marketing-expert' ),
				$imported,
				$updated
			),
		) );
	}

	/* ================================================================
	 *  B2B LEAD AUTOPILOT PIPELINE
	 * ============================================================= */

	/**
	 * Get current Autopilot configuration and execution statistics.
	 */
	public function get_autopilot_config( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'code'    => 'pro_required',
				'message' => __( 'B2B Lead Autopilot is a Pro feature.', 'ai-marketing-expert' ),
			), 403 );
		}

		$config = get_option( 'aime_lead_autopilot_config', array() );
		$defaults = array(
			'enabled'            => false,
			'mode'               => 'filters', // 'filters' | 'prompt'
			'industry'           => '',
			'role'               => '',
			'location'           => '',
			'company_size'       => '',
			'keyword'            => '',
			'prompt'             => '',
			'daily_target'       => 25,
			'target_list_id'     => 0,
			'tag_names'          => array( 'AI-Autopilot' ),
			'last_run'           => null,
			'last_result'        => null,
			'total_imported'     => 0,
			'total_skipped_dup'  => 0,
			'total_skipped_mx'   => 0,
		);

		$merged = array_merge( $defaults, is_array( $config ) ? $config : array() );
		$merged['page_pointer'] = B2bScraperService::get_autopilot_page_pointer( $merged );

		return new \WP_REST_Response( $merged );
	}

	/**
	 * Save Autopilot configuration.
	 */
	public function save_autopilot_config( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'code'    => 'pro_required',
				'message' => __( 'B2B Lead Autopilot is a Pro feature.', 'ai-marketing-expert' ),
			), 403 );
		}

		$current = get_option( 'aime_lead_autopilot_config', array() );
		$current = is_array( $current ) ? $current : array();

		$enabled      = (bool) $request->get_param( 'enabled' );
		$mode         = sanitize_text_field( $request->get_param( 'mode' ) ?: 'filters' );
		$industry     = sanitize_text_field( $request->get_param( 'industry' ) ?? '' );
		$role         = sanitize_text_field( $request->get_param( 'role' ) ?? '' );
		$location     = sanitize_text_field( $request->get_param( 'location' ) ?? '' );
		$company_size = sanitize_text_field( $request->get_param( 'company_size' ) ?? '' );
		$keyword      = sanitize_text_field( $request->get_param( 'keyword' ) ?? '' );
		$prompt       = sanitize_textarea_field( $request->get_param( 'prompt' ) ?? '' );
		$daily_target = min( 500, max( 5, absint( $request->get_param( 'daily_target' ) ?: 25 ) ) );
		$target_list  = absint( $request->get_param( 'target_list_id' ) ?: 0 );

		$raw_tags = $request->get_param( 'tag_names' );
		if ( is_string( $raw_tags ) ) {
			$tag_names = array_filter( array_map( 'trim', explode( ',', $raw_tags ) ) );
		} elseif ( is_array( $raw_tags ) ) {
			$tag_names = array_filter( array_map( 'sanitize_text_field', $raw_tags ) );
		} else {
			$tag_names = array( 'AI-Autopilot' );
		}

		$new_config = array_merge( $current, array(
			'enabled'        => $enabled,
			'mode'           => in_array( $mode, array( 'filters', 'prompt' ), true ) ? $mode : 'filters',
			'industry'       => $industry,
			'role'           => $role,
			'location'       => $location,
			'company_size'   => $company_size,
			'keyword'        => $keyword,
			'prompt'         => $prompt,
			'daily_target'   => $daily_target,
			'target_list_id' => $target_list,
			'tag_names'      => array_values( $tag_names ),
		) );

		update_option( 'aime_lead_autopilot_config', $new_config );

		return new \WP_REST_Response( array(
			'success' => true,
			'message' => __( 'Autopilot configuration saved successfully.', 'ai-marketing-expert' ),
			'config'  => $new_config,
		) );
	}

	/**
	 * Trigger manual test run of the Autopilot pipeline.
	 */
	public function run_autopilot( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'code'    => 'pro_required',
				'message' => __( 'B2B Lead Autopilot is a Pro feature.', 'ai-marketing-expert' ),
			), 403 );
		}

		$config = get_option( 'aime_lead_autopilot_config', array() );
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		// Support direct execution using parameters passed in the request body
		$param_role     = $request->get_param( 'role' );
		$param_industry = $request->get_param( 'industry' );
		$param_keyword  = $request->get_param( 'keyword' );
		$param_prompt   = $request->get_param( 'prompt' );

		if ( null !== $param_role || null !== $param_industry || null !== $param_keyword || null !== $param_prompt ) {
			$config = array(
				'enabled'        => (bool) ( $request->get_param( 'enabled' ) ?? ( $config['enabled'] ?? true ) ),
				'mode'           => sanitize_text_field( $request->get_param( 'mode' ) ?? ( $config['mode'] ?? 'filters' ) ),
				'industry'       => sanitize_text_field( $param_industry ?? ( $config['industry'] ?? '' ) ),
				'role'           => sanitize_text_field( $param_role ?? ( $config['role'] ?? '' ) ),
				'location'       => sanitize_text_field( $request->get_param( 'location' ) ?? ( $config['location'] ?? '' ) ),
				'company_size'   => sanitize_text_field( $request->get_param( 'company_size' ) ?? ( $config['company_size'] ?? '' ) ),
				'keyword'        => sanitize_text_field( $param_keyword ?? ( $config['keyword'] ?? '' ) ),
				'prompt'         => sanitize_textarea_field( $param_prompt ?? ( $config['prompt'] ?? '' ) ),
				'daily_target'   => min( 250, max( 5, absint( $request->get_param( 'daily_target' ) ?: ( $config['daily_target'] ?? 25 ) ) ) ),
				'target_list_id' => absint( $request->get_param( 'target_list_id' ) ?: ( $config['target_list_id'] ?? 0 ) ),
				'tag_names'      => sanitize_text_field( $request->get_param( 'tag_names' ) ?? ( $config['tag_names'] ?? 'AI-Autopilot' ) ),
			);
			update_option( 'aime_lead_autopilot_config', $config );
		}

		$has_filter_rules = ! empty( $config['industry'] ) || ! empty( $config['role'] ) || ! empty( $config['keyword'] );
		$has_prompt_rules = ( 'prompt' === ( $config['mode'] ?? '' ) ) && ! empty( $config['prompt'] );

		if ( ! $has_filter_rules && ! $has_prompt_rules ) {
			return new \WP_REST_Response( array(
				'message' => __( 'Autopilot is not configured yet. Please select at least an Industry, Job Role, Keyword, or AI Prompt.', 'ai-marketing-expert' ),
			), 400 );
		}

		$result = $this->execute_autopilot_pipeline( $config, true );

		return new \WP_REST_Response( array(
			'success' => true,
			'result'  => $result,
			'message' => sprintf(
				/* translators: 1: imported count, 2: skipped duplicates, 3: skipped MX */
				__( 'Autopilot completed! %1$d verified leads imported, %2$d duplicates skipped, %3$d unverified domains skipped.', 'ai-marketing-expert' ),
				$result['imported'] ?? 0,
				$result['skipped_duplicates'] ?? 0,
				$result['skipped_mx'] ?? 0
			),
		) );
	}

	/**
	 * Reset persistent Autopilot page pointer back to Page 1.
	 */
	public function reset_autopilot_pointer( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! aime_has_pro() ) {
			return new \WP_REST_Response( array(
				'code'    => 'pro_required',
				'message' => __( 'B2B Lead Autopilot is a Pro feature.', 'ai-marketing-expert' ),
			), 403 );
		}

		$config = get_option( 'aime_lead_autopilot_config', array() );
		$config = is_array( $config ) ? $config : array();

		B2bScraperService::reset_autopilot_page_pointer( $config );

		return new \WP_REST_Response( array(
			'success'      => true,
			'message'      => __( 'Autopilot page pointer reset to Page 1.', 'ai-marketing-expert' ),
			'page_pointer' => 1,
		) );
	}

	/**
	 * Invoked daily by WP-Cron scheduler.
	 */
	public function run_scheduled_autopilot(): void {
		$config = get_option( 'aime_lead_autopilot_config', array() );
		if ( ! is_array( $config ) || empty( $config['enabled'] ) ) {
			return;
		}

		$this->execute_autopilot_pipeline( $config, false );
	}

	/**
	 * Core Autopilot Pipeline with persistent pagination, pre-CRM deduplication & DNS MX verification.
	 */
	public function execute_autopilot_pipeline( array $config, bool $force = false ): array {
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'aime_subscribers';
		$now               = current_time( 'mysql', true );

		$daily_target = min( 500, max( 5, absint( $config['daily_target'] ?? 25 ) ) );
		$target_list  = absint( $config['target_list_id'] ?? 0 );
		$tag_names    = (array) ( $config['tag_names'] ?? array( 'AI-Autopilot' ) );
		$tag_ids      = ! empty( $tag_names ) ? $this->resolve_tag_names( $tag_names ) : array();

		$imported_count           = 0;
		$skipped_duplicates_count = 0;
		$skipped_mx_count         = 0;
		$attempt                  = 0;
		$max_attempts             = 5;
		$seen_domains             = array();

		// Fetch current persistent page pointer
		$current_page = B2bScraperService::get_autopilot_page_pointer( $config );

		while ( $imported_count < $daily_target && $attempt < $max_attempts ) {
			$attempt++;
			$needed = $daily_target - $imported_count;
			// Request a sensible batch with buffer for invalid MX or duplicates.
			$batch_limit = min( 25, max( 5, $needed + 5 ) );

			// Call B2bScraperService::discover_leads with current_page and exclusions
			$discovery = B2bScraperService::discover_leads(
				$config,
				$current_page,
				$batch_limit,
				array_keys( $seen_domains )
			);

			$leads = $discovery['items'] ?? array();
			if ( empty( $leads ) ) {
				// Try advancing page pointer even if empty, to avoid getting stuck on a dry page
				$current_page = B2bScraperService::advance_autopilot_page_pointer( $config, $current_page );
				continue;
			}

			foreach ( $leads as $item ) {
				if ( $imported_count >= $daily_target ) {
					break 2;
				}
				if ( ! is_array( $item ) ) {
					continue;
				}

				$email = sanitize_email( $item['email'] ?? '' );
				if ( ! is_email( $email ) ) {
					continue;
				}

				$domain = strtolower( trim( (string) ( $item['domain'] ?? '' ) ) );
				if ( empty( $domain ) && false !== strpos( $email, '@' ) ) {
					$parts  = explode( '@', $email );
					$domain = (string) end( $parts );
				}
				$domain = preg_replace( '#^https?://#i', '', $domain );
				$domain = trim( $domain, '/' );

				if ( isset( $seen_domains[ $domain ] ) ) {
					$skipped_duplicates_count++;
					continue;
				}
				$seen_domains[ $domain ] = true;

				// 1. Live DNS MX Deliverability Verification.
				if ( empty( $item['mx_verified'] ) && ! EmailValidator::has_mailable_domain( $domain ) ) {
					$skipped_mx_count++;
					continue;
				}

				// 2. Multi-tier CRM Deduplication Check (all statuses).
				if ( ! empty( $item['is_in_crm'] ) || B2bScraperService::is_domain_in_crm( $domain ) ) {
					$skipped_duplicates_count++;
					continue;
				}

				$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$subscribers_table} WHERE email = %s", $email ) );
				if ( $existing_id > 0 ) {
					$skipped_duplicates_count++;
					continue;
				}

				// 3. Ingest Fresh Verified Lead.
				$first_name = sanitize_text_field( $item['first_name'] ?? '' );
				$last_name  = sanitize_text_field( $item['last_name'] ?? '' );
				$company    = sanitize_text_field( $item['company'] ?? '' );
				$title      = sanitize_text_field( $item['title'] ?? '' );
				$website    = esc_url_raw( $item['website'] ?? ( $domain ? "https://{$domain}" : '' ) );

				$insert_data = array(
					'email'      => $email,
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'status'     => 'subscribed',
					'source'     => 'lead_autopilot',
					'hash'       => md5( $email . wp_generate_uuid4() ),
					'created_at' => $now,
					'updated_at' => $now,
				);

				$ok = $wpdb->insert( $subscribers_table, $insert_data );
				if ( ! $ok ) {
					continue;
				}

				$sub_id = (int) $wpdb->insert_id;
				$imported_count++;

				// Store custom metadata.
				$meta = array();
				if ( $company ) {
					$meta['company'] = $company;
				}
				if ( $title ) {
					$meta['job_title'] = $title;
				}
				if ( $website ) {
					$meta['website'] = $website;
				}
				if ( ! empty( $item['phone'] ) ) {
					$meta['phone'] = sanitize_text_field( $item['phone'] );
				}
				if ( ! empty( $meta ) ) {
					$this->save_meta( $sub_id, $meta );
				}

				// Attach to target list & fire automation funnel trigger.
				if ( $target_list > 0 ) {
					$this->sync_pivot( $sub_id, array( $target_list ), 'list' );
					do_action( 'aime_subscriber_list_added', $sub_id, $target_list, 'list' );
				}

				// Attach tags & fire tag trigger.
				if ( ! empty( $tag_ids ) ) {
					$this->sync_pivot( $sub_id, $tag_ids, 'tag' );
					do_action( 'aime_subscriber_tag_added', $sub_id, $tag_ids[0], 'tag' );
				}

				$this->log_activity( $sub_id, 'created', 'Imported via B2B Lead Autopilot' );
			}

			// Advance page pointer for next batch or next scheduled day
			$current_page = B2bScraperService::advance_autopilot_page_pointer( $config, $current_page );
		}

		// Update option with latest run metrics.
		$config['last_run']           = $now;
		$config['last_result']        = array(
			'imported'           => $imported_count,
			'skipped_duplicates' => $skipped_duplicates_count,
			'skipped_mx'         => $skipped_mx_count,
			'status'             => 'completed',
			'page_pointer'       => $current_page,
			'timestamp'          => time(),
		);
		$config['total_imported']     = ( $config['total_imported'] ?? 0 ) + $imported_count;
		$config['total_skipped_dup']  = ( $config['total_skipped_dup'] ?? 0 ) + $skipped_duplicates_count;
		$config['total_skipped_mx']   = ( $config['total_skipped_mx'] ?? 0 ) + $skipped_mx_count;

		update_option( 'aime_lead_autopilot_config', $config );

		return array(
			'imported'           => $imported_count,
			'skipped_duplicates' => $skipped_duplicates_count,
			'skipped_mx'         => $skipped_mx_count,
			'target'             => $daily_target,
			'page_pointer'       => $current_page,
			'timestamp'          => $now,
		);
	}
}

