<?php
/**
 * Funnel Enroll action — attach a contact to lists/tags and/or enroll them
 * into an email funnel (automation sequence).
 *
 * @package WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

namespace WPSpace\AiMarketingExpert\Modules\WorkflowAutomation\Actions;

use WPSpace\AiMarketingExpert\Modules\EmailMarketing\Services\FunnelProcessor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FunnelEnrollAction extends BaseAction {

	public static function run( array $config, array $context ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$funnel_id = (int) ( $config['funnel_id'] ?? 0 );
		$email     = sanitize_email( (string) ( $config['subscriber_email'] ?? '' ) );

		// Blank email falls back to the trigger event's subscriber/lead email.
		if ( '' === $email && ! empty( $context['event']['email'] ) ) {
			$email = sanitize_email( (string) $context['event']['email'] );
		}

		$list_ids = self::valid_pivot_ids( $config['list_ids'] ?? array(), "{$p}aime_lists" );
		$tag_ids  = self::valid_pivot_ids( $config['tag_ids'] ?? array(), "{$p}aime_tags" );

		if ( ! is_email( $email ) ) {
			return self::fail( __( 'A valid subscriber email is required.', 'ai-marketing-expert' ) );
		}

		// Validate funnel exists when one was chosen.
		$funnel = null;
		if ( $funnel_id ) {
			$funnel = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, title, status FROM {$p}aime_funnels WHERE id = %d",
				$funnel_id
			) );
			if ( ! $funnel ) {
				/* translators: %d: funnel ID. */
				return self::fail( sprintf( __( 'Funnel #%d not found.', 'ai-marketing-expert' ), $funnel_id ) );
			}
		}

		// Resolve subscriber by email; optionally create the contact.
		$subscriber_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}aime_subscribers WHERE email = %s LIMIT 1",
			$email
		) );
		$created = false;
		if ( ! $subscriber_id ) {
			// Absent key = schema default (true): template-seeded steps and
			// older configs behave exactly like a freshly ticked checkbox.
			$create_missing = ! isset( $config['create_if_missing'] ) || ! empty( $config['create_if_missing'] );
			if ( ! $create_missing ) {
				/* translators: %s: subscriber email address. */
				return self::fail( sprintf( __( 'No subscriber found for %s.', 'ai-marketing-expert' ), $email ) );
			}
			$inserted = $wpdb->insert(
				"{$p}aime_subscribers",
				array(
					'email'      => $email,
					'status'     => 'subscribed',
					'source'     => 'workflow',
					'hash'       => md5( $email . wp_generate_uuid4() ),
					'created_at' => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
			if ( ! $inserted ) {
				return self::fail( __( 'Could not create the subscriber contact.', 'ai-marketing-expert' ) );
			}
			$subscriber_id = (int) $wpdb->insert_id;
			$created       = true;
		}

		// Attach lists/tags (merge — existing assignments are never removed).
		$attached = array();
		if ( $list_ids ) {
			$attached[] = self::attach_pivot( $subscriber_id, $list_ids, 'list' );
		}
		if ( $tag_ids ) {
			$attached[] = self::attach_pivot( $subscriber_id, $tag_ids, 'tag' );
		}

		// Enroll into the funnel sequence when one was selected.
		if ( $funnel ) {
			// FunnelProcessor::trigger() returns void and guards its own status/dupe checks.
			( new FunnelProcessor() )->trigger( $funnel_id, $subscriber_id );
		}

		// Build a preview line that reflects exactly what happened.
		$parts = array();
		if ( $funnel ) {
			$parts[] = sprintf(
				/* translators: %s: funnel title */
				__( 'enrolled into funnel "%s"', 'ai-marketing-expert' ),
				$funnel->title
			);
		}
		foreach ( $attached as $line ) {
			$parts[] = $line;
		}
		$prefix = $created
			? __( 'Created contact', 'ai-marketing-expert' )
			: __( 'Updated contact', 'ai-marketing-expert' );

		$preview = empty( $parts )
			? sprintf( '%1$s %2$s.', $prefix, $email )
			: sprintf( '%1$s %2$s: %3$s.', $prefix, $email, implode( ', ', $parts ) );

		return self::ok( $preview, array(
			'funnel_id'     => $funnel_id,
			'subscriber_id' => $subscriber_id,
			'created'       => $created,
			'link'          => self::module_link( 'email' ),
		) );
	}

	/**
	 * Keep only ids that exist in the given pivot-target table.
	 *
	 * @param mixed  $raw   Raw config value (array or scalar).
	 * @param string $table Fully-qualified table name.
	 * @return int[]
	 */
	private static function valid_pivot_ids( $raw, string $table ): array {
		global $wpdb;
		$ids = is_array( $raw )
			? array_map( 'intval', $raw )
			: array_filter( array_map( 'intval', explode( ',', (string) $raw ) ) );
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$query        = "SELECT id FROM {$table} WHERE id IN ({$placeholders})";
		$found        = $wpdb->get_col( $wpdb->prepare( $query, ...$ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $found );
	}

	/**
	 * Attach pivot rows without removing anything already assigned, firing the
	 * same aime_subscriber_{type}_added events the admin UI fires.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int[]  $ids           List or tag IDs (validated).
	 * @param string $type          'list' or 'tag'.
	 * @return string Human-readable summary of what was newly attached.
	 */
	private static function attach_pivot( int $subscriber_id, array $ids, string $type ): string {
		global $wpdb;
		$p           = $wpdb->prefix;
		$pivot_table = "{$p}aime_subscriber_pivot";

		$existing = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT object_id FROM {$pivot_table} WHERE subscriber_id = %d AND object_type = %s",
			$subscriber_id,
			$type
		) ) );

		$new_ids = array_values( array_diff( $ids, $existing ) );
		foreach ( $new_ids as $oid ) {
			$wpdb->insert(
				$pivot_table,
				array(
					'subscriber_id' => $subscriber_id,
					'object_id'     => $oid,
					'object_type'   => $type,
					'status'        => 'active',
					'created_at'    => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
			do_action( "aime_subscriber_{$type}_added", $subscriber_id, $oid, $type );
		}

		$count = count( $ids );
		return sprintf(
			/* translators: 1: number, 2: plural label ("lists"/"tags") */
			__( '%1$d %2$s', 'ai-marketing-expert' ),
			$count,
			( 'list' === $type ? _n( 'list', 'lists', $count, 'ai-marketing-expert' ) : _n( 'tag', 'tags', $count, 'ai-marketing-expert' ) )
		);
	}
}
