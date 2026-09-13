<?php
/**
 * Analytics Controller — reporting, metrics, charts.
 *
 * @package WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers
 */

namespace WPSpace\AiMarketingExpert\Modules\EmailMarketing\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AnalyticsController {

	/* ── DASHBOARD OVERVIEW ──────────────────────────── */

	public function overview( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$subscribers_table     = $wpdb->prefix . 'aime_subscribers';
		$campaign_emails_table = $wpdb->prefix . 'aime_campaign_emails';
		$metrics_table         = $wpdb->prefix . 'aime_campaign_url_metrics';
		$days                  = absint( $request->get_param( 'days' ) ?: 30 );
		$since                 = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Subscriber growth over time.
		$growth = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS date, COUNT(*) AS count
				 FROM %i
				 WHERE created_at >= %s
				 GROUP BY DATE(created_at) ORDER BY date ASC",
				$subscribers_table,
				$since
			)
		);

		// Email activity.
		$sent_over_time = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS date,
					SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
					SUM(CASE WHEN is_open = 1 THEN 1 ELSE 0 END) AS opened
				 FROM %i
				 WHERE created_at >= %s
				 GROUP BY DATE(created_at) ORDER BY date ASC",
				$campaign_emails_table,
				$since
			)
		);

		// Totals.
		$total_sent        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'sent' AND created_at >= %s", $campaign_emails_table, $since ) );
		$total_opened      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE is_open = 1 AND created_at >= %s", $campaign_emails_table, $since ) );
		// Unique clickers per campaign, summed across campaigns — counting raw
		// click events double-counts recipients who clicked several links.
		$total_clicks      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( SELECT 1 FROM %i WHERE type = 'click' AND created_at >= %s GROUP BY campaign_id, subscriber_id ) t", $metrics_table, $since ) );
		$total_unsubs      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( SELECT 1 FROM %i WHERE type = 'unsubscribe' AND created_at >= %s GROUP BY campaign_id, subscriber_id ) t", $metrics_table, $since ) );
		$total_subscribers = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $subscribers_table ) );

		return new \WP_REST_Response( array(
			'subscriber_growth' => $growth,
			'email_activity'    => $sent_over_time,
			'totals'            => array(
				'subscribers'  => $total_subscribers,
				'sent'         => $total_sent,
				'opened'       => $total_opened,
				'clicks'       => $total_clicks,
				'unsubscribes' => $total_unsubs,
				'open_rate'    => $total_sent > 0 ? round( ( $total_opened / $total_sent ) * 100, 1 ) : 0,
				'click_rate'   => $total_sent > 0 ? round( ( $total_clicks / $total_sent ) * 100, 1 ) : 0,
			),
		) );
	}

	/* ── CAMPAIGN ANALYTICS ──────────────────────────── */

	public function campaign_report( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$campaigns_table       = $wpdb->prefix . 'aime_campaigns';
		$campaign_emails_table = $wpdb->prefix . 'aime_campaign_emails';
		$metrics_table         = $wpdb->prefix . 'aime_campaign_url_metrics';
		$url_stores_table      = $wpdb->prefix . 'aime_url_stores';
		$id                    = absint( $request->get_param( 'id' ) );

		$campaign = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $campaigns_table, $id ) );
		if ( ! $campaign ) {
			return new \WP_REST_Response( array( 'message' => __( 'Campaign not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
					SUM(CASE WHEN is_open = 1 THEN 1 ELSE 0 END) AS opened
				 FROM %i WHERE campaign_id = %d",
				$campaign_emails_table,
				$id
			)
		);

		// Unique clickers, not click events, so click-to-open cannot exceed 100%.
		$total_clicks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT subscriber_id) FROM %i WHERE campaign_id = %d AND type = 'click'",
				$metrics_table,
				$id
			)
		);

		// Top links clicked.
		$top_links = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT us.url, COUNT(m.id) AS clicks
				 FROM %i m
				 INNER JOIN %i us ON us.id = m.url_id
				 WHERE m.campaign_id = %d AND m.type = 'click'
				 GROUP BY us.url
				 ORDER BY clicks DESC LIMIT 10",
				$metrics_table,
				$url_stores_table,
				$id
			)
		);

		// Opens over time.
		$opens_timeline = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS date, COUNT(*) AS count
				 FROM %i
				 WHERE campaign_id = %d AND type = 'open'
				 GROUP BY DATE(created_at) ORDER BY date ASC",
				$metrics_table,
				$id
			)
		);

		// Clicks by country.
		$countries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country, COUNT(*) AS count
				 FROM %i
				 WHERE campaign_id = %d AND type = 'click' AND country IS NOT NULL
				 GROUP BY country ORDER BY count DESC LIMIT 20",
				$metrics_table,
				$id
			)
		);

		// Top locations by opens (Mailchimp style).
		$opened_count           = (int) ( $stats->opened ?? 0 );
		$locations_by_opens_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country, COUNT(DISTINCT subscriber_id) AS opens
				 FROM %i
				 WHERE campaign_id = %d AND type = 'open' AND country IS NOT NULL AND country != ''
				 GROUP BY country
				 ORDER BY opens DESC
				 LIMIT 10",
				$metrics_table,
				$id
			)
		);

		$top_locations_by_opens = array();
		foreach ( (array) $locations_by_opens_raw as $loc ) {
			$opens = (int) $loc->opens;
			$pct   = $opened_count > 0 ? round( ( $opens / $opened_count ) * 100, 1 ) : 0;
			$top_locations_by_opens[] = array(
				'country'    => strtoupper( (string) $loc->country ),
				'opens'      => $opens,
				'percentage' => $pct,
			);
		}

		// Fallback to subscriber profile country for existing historical campaigns.
		if ( empty( $top_locations_by_opens ) && $opened_count > 0 ) {
			$sub_countries = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.country, COUNT(DISTINCT s.id) AS opens
					 FROM %i ce
					 INNER JOIN %i s ON s.id = ce.subscriber_id
					 WHERE ce.campaign_id = %d AND ce.is_open = 1 AND s.country IS NOT NULL AND s.country != ''
					 GROUP BY s.country
					 ORDER BY opens DESC
					 LIMIT 10",
					$campaign_emails_table,
					$wpdb->prefix . 'aime_subscribers',
					$id
				)
			);
			foreach ( (array) $sub_countries as $loc ) {
				$opens = (int) $loc->opens;
				$pct   = $opened_count > 0 ? round( ( $opens / $opened_count ) * 100, 1 ) : 0;
				$top_locations_by_opens[] = array(
					'country'    => strtoupper( (string) $loc->country ),
					'opens'      => $opens,
					'percentage' => $pct,
				);
			}
		}

		$unsubscribes = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT subscriber_id) FROM %i WHERE campaign_id = %d AND type = 'unsubscribe'", $metrics_table, $id )
		);

		$sent = (int) ( $stats->sent ?? 0 );
		$recipient_lists = aime_has_pro() ? $this->get_campaign_recipient_lists( $id ) : array( 'counts' => array() );
		if ( ! aime_has_pro() ) {
			$top_links = array();
		}

		return new \WP_REST_Response( array(
			'campaign'               => $campaign,
			'total'                  => (int) ( $stats->total ?? 0 ),
			'sent'                   => $sent,
			'failed'                 => (int) ( $stats->failed ?? 0 ),
			'opened'                 => (int) ( $stats->opened ?? 0 ),
			'total_clicks'           => $total_clicks,
			'unsubscribes'           => $unsubscribes,
			'open_rate'              => $sent > 0 ? round( ( (int) $stats->opened / $sent ) * 100, 1 ) : 0,
			'click_rate'             => $sent > 0 ? round( ( $total_clicks / $sent ) * 100, 1 ) : 0,
			'top_links'              => $top_links,
			'opens_timeline'         => $opens_timeline,
			'countries'              => $countries,
			'top_locations_by_opens' => $top_locations_by_opens,
			'recipients'             => $recipient_lists,
		) );
	}

	private function get_campaign_recipient_lists( int $campaign_id ): array {
		global $wpdb;
		$campaign_emails_table = $wpdb->prefix . 'aime_campaign_emails';
		$subscribers_table     = $wpdb->prefix . 'aime_subscribers';
		$metrics_table         = $wpdb->prefix . 'aime_campaign_url_metrics';

		$this->sync_bounced_subscribers( $campaign_id );

		$lists = array( 'counts' => array() );
		foreach ( array( 'sent', 'opened', 'clicked', 'unsubscribed', 'complained', 'bounced' ) as $key ) {
			if ( 'sent' === $key ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT ce.id, ce.subscriber_id, ce.email_address, ce.status, ce.is_open, ce.click_counter, ce.note, ce.created_at, ce.updated_at,
						s.email, s.first_name, s.last_name,
						(SELECT COUNT(*) FROM %i om WHERE om.campaign_id = ce.campaign_id AND om.subscriber_id = ce.subscriber_id AND om.type = 'open') AS open_count,
						(SELECT COUNT(*) FROM %i cm WHERE cm.campaign_id = ce.campaign_id AND cm.subscriber_id = ce.subscriber_id AND cm.type = 'click') AS click_count,
						(SELECT COUNT(*) FROM %i um WHERE um.campaign_id = ce.campaign_id AND um.subscriber_id = ce.subscriber_id AND um.type = 'unsubscribe') AS unsubscribe_count
					 FROM %i ce
					 LEFT JOIN %i s ON s.id = ce.subscriber_id
					 WHERE ce.campaign_id = %d AND ce.status = 'sent'
					 ORDER BY ce.updated_at DESC, ce.id DESC",
					$metrics_table,
					$metrics_table,
					$metrics_table,
					$campaign_emails_table,
					$subscribers_table,
					$campaign_id
				) );
			} elseif ( 'opened' === $key ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT ce.id, ce.subscriber_id, ce.email_address, ce.status, ce.is_open, ce.click_counter, ce.note, ce.created_at, ce.updated_at,
						s.email, s.first_name, s.last_name,
						(SELECT COUNT(*) FROM %i om WHERE om.campaign_id = ce.campaign_id AND om.subscriber_id = ce.subscriber_id AND om.type = 'open') AS open_count,
						(SELECT COUNT(*) FROM %i cm WHERE cm.campaign_id = ce.campaign_id AND cm.subscriber_id = ce.subscriber_id AND cm.type = 'click') AS click_count,
						(SELECT COUNT(*) FROM %i um WHERE um.campaign_id = ce.campaign_id AND um.subscriber_id = ce.subscriber_id AND um.type = 'unsubscribe') AS unsubscribe_count
					 FROM %i ce
					 LEFT JOIN %i s ON s.id = ce.subscriber_id
					 WHERE ce.campaign_id = %d AND ce.is_open = 1
					 ORDER BY open_count DESC, ce.updated_at DESC, ce.id DESC",
					$metrics_table,
					$metrics_table,
					$metrics_table,
					$campaign_emails_table,
					$subscribers_table,
					$campaign_id
				) );
			} elseif ( 'clicked' === $key ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT ce.id, ce.subscriber_id, ce.email_address, ce.status, ce.is_open, ce.click_counter, ce.note, ce.created_at, ce.updated_at,
						s.email, s.first_name, s.last_name,
						(SELECT COUNT(*) FROM %i om WHERE om.campaign_id = ce.campaign_id AND om.subscriber_id = ce.subscriber_id AND om.type = 'open') AS open_count,
						(SELECT COUNT(*) FROM %i cm WHERE cm.campaign_id = ce.campaign_id AND cm.subscriber_id = ce.subscriber_id AND cm.type = 'click') AS click_count,
						(SELECT COUNT(*) FROM %i um WHERE um.campaign_id = ce.campaign_id AND um.subscriber_id = ce.subscriber_id AND um.type = 'unsubscribe') AS unsubscribe_count
					 FROM %i ce
					 LEFT JOIN %i s ON s.id = ce.subscriber_id
					 WHERE ce.campaign_id = %d AND ce.click_counter > 0
					 ORDER BY ce.click_counter DESC, click_count DESC, ce.updated_at DESC, ce.id DESC",
					$metrics_table,
					$metrics_table,
					$metrics_table,
					$campaign_emails_table,
					$subscribers_table,
					$campaign_id
				) );
			} elseif ( 'unsubscribed' === $key ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ce.id, ce.subscriber_id, ce.email_address, ce.status, ce.is_open, ce.click_counter, ce.note, ce.created_at, ce.updated_at,
							s.email, s.first_name, s.last_name,
							(SELECT COUNT(*) FROM %i om WHERE om.campaign_id = ce.campaign_id AND om.subscriber_id = ce.subscriber_id AND om.type = 'open') AS open_count,
							(SELECT COUNT(*) FROM %i cm WHERE cm.campaign_id = ce.campaign_id AND cm.subscriber_id = ce.subscriber_id AND cm.type = 'click') AS click_count,
							(SELECT COUNT(*) FROM %i um WHERE um.campaign_id = ce.campaign_id AND um.subscriber_id = ce.subscriber_id AND um.type = 'unsubscribe') AS unsubscribe_count
						 FROM %i ce
						 LEFT JOIN %i s ON s.id = ce.subscriber_id
						 WHERE ce.campaign_id = %d
						 AND EXISTS (SELECT 1 FROM %i um2 WHERE um2.campaign_id = ce.campaign_id AND um2.subscriber_id = ce.subscriber_id AND um2.type = 'unsubscribe')
						 ORDER BY unsubscribe_count DESC, ce.updated_at DESC, ce.id DESC",
						$metrics_table,
						$metrics_table,
						$metrics_table,
						$campaign_emails_table,
						$subscribers_table,
						$campaign_id,
						$metrics_table
					)
				);
			} elseif ( 'complained' === $key ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT ce.id, ce.subscriber_id, ce.email_address, ce.status, ce.is_open, ce.click_counter, ce.note, ce.created_at, ce.updated_at,
						s.email, s.first_name, s.last_name,
						(SELECT COUNT(*) FROM %i om WHERE om.campaign_id = ce.campaign_id AND om.subscriber_id = ce.subscriber_id AND om.type = 'open') AS open_count,
						(SELECT COUNT(*) FROM %i cm WHERE cm.campaign_id = ce.campaign_id AND cm.subscriber_id = ce.subscriber_id AND cm.type = 'click') AS click_count,
						(SELECT COUNT(*) FROM %i um WHERE um.campaign_id = ce.campaign_id AND um.subscriber_id = ce.subscriber_id AND um.type = 'unsubscribe') AS unsubscribe_count
					 FROM %i ce
					 LEFT JOIN %i s ON s.id = ce.subscriber_id
					 WHERE ce.campaign_id = %d AND (
						s.status = 'complained'
						OR EXISTS (
							SELECT 1 FROM %i xm
							WHERE xm.subscriber_id = ce.subscriber_id AND xm.type = 'complaint'
						)
					 )
					 ORDER BY ce.updated_at DESC, ce.id DESC",
					$metrics_table,
					$metrics_table,
					$metrics_table,
					$campaign_emails_table,
					$subscribers_table,
					$campaign_id,
					$metrics_table
				) );
			} else {
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT ce.id, ce.subscriber_id, ce.email_address, ce.status, ce.is_open, ce.click_counter, ce.note, ce.created_at, ce.updated_at,
							s.email, s.first_name, s.last_name,
							(SELECT COUNT(*) FROM %i om WHERE om.campaign_id = ce.campaign_id AND om.subscriber_id = ce.subscriber_id AND om.type = 'open') AS open_count,
							(SELECT COUNT(*) FROM %i cm WHERE cm.campaign_id = ce.campaign_id AND cm.subscriber_id = ce.subscriber_id AND cm.type = 'click') AS click_count,
							(SELECT COUNT(*) FROM %i um WHERE um.campaign_id = ce.campaign_id AND um.subscriber_id = ce.subscriber_id AND um.type = 'unsubscribe') AS unsubscribe_count
						 FROM %i ce
						 LEFT JOIN %i s ON s.id = ce.subscriber_id
						 WHERE ce.campaign_id = %d AND ce.status = 'failed'
						 ORDER BY ce.updated_at DESC, ce.id DESC",
					$metrics_table,
					$metrics_table,
					$metrics_table,
					$campaign_emails_table,
					$subscribers_table,
					$campaign_id
				) );
			}

			$lists[ $key ] = array_map( array( $this, 'format_campaign_recipient' ), $rows ?: array() );
			$lists['counts'][ $key ] = count( $lists[ $key ] );
		}

		return $lists;
	}

	private function sync_bounced_subscribers( int $campaign_id ): void {
		global $wpdb;
		$subscribers_table     = $wpdb->prefix . 'aime_subscribers';
		$campaign_emails_table = $wpdb->prefix . 'aime_campaign_emails';

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i s
				 INNER JOIN %i ce ON ce.subscriber_id = s.id
				 SET s.status = 'bounced', s.updated_at = %s
				 WHERE ce.campaign_id = %d AND ce.status = 'failed' AND s.status NOT IN ('bounced', 'complained')",
				$subscribers_table,
				$campaign_emails_table,
				current_time( 'mysql', true ),
				$campaign_id
			)
		);
	}

	private function format_campaign_recipient( object $row ): array {
		$name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );

		return array(
			'id'                => (int) $row->id,
			'subscriber_id'     => (int) $row->subscriber_id,
			'name'              => $name,
			'email'             => $row->email_address ?: ( $row->email ?? '' ),
			'status'            => $row->status,
			'is_open'           => (bool) $row->is_open,
			'open_count'        => (int) ( $row->open_count ?? 0 ),
			'click_count'       => (int) ( $row->click_count ?? $row->click_counter ?? 0 ),
			'unsubscribe_count' => (int) ( $row->unsubscribe_count ?? 0 ),
			'note'              => $row->note ?? '',
			'created_at'        => $row->created_at,
			'updated_at'        => $row->updated_at,
		);
	}

	/* ── SUBSCRIBER ACTIVITY ─────────────────────────── */

	public function subscriber_activity( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$campaign_emails_table = $wpdb->prefix . 'aime_campaign_emails';
		$campaigns_table       = $wpdb->prefix . 'aime_campaigns';
		$metrics_table         = $wpdb->prefix . 'aime_campaign_url_metrics';
		$url_stores_table      = $wpdb->prefix . 'aime_url_stores';
		$funnel_subscribers    = $wpdb->prefix . 'aime_funnel_subscribers';
		$funnels_table         = $wpdb->prefix . 'aime_funnels';
		$id                    = absint( $request->get_param( 'id' ) );

		// Emails received.
		$emails = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ce.id, ce.campaign_id, ce.email_subject, ce.status, ce.is_open, ce.click_counter, ce.created_at, c.title AS campaign_title
				 FROM %i ce
				 LEFT JOIN %i c ON c.id = ce.campaign_id
				 WHERE ce.subscriber_id = %d
				 ORDER BY ce.created_at DESC LIMIT 50",
				$campaign_emails_table,
				$campaigns_table,
				$id
			)
		);

		// Click/open activity.
		$metrics = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.type, m.created_at, us.url
				 FROM %i m
				 LEFT JOIN %i us ON us.id = m.url_id
				 WHERE m.subscriber_id = %d
				 ORDER BY m.created_at DESC LIMIT 100",
				$metrics_table,
				$url_stores_table,
				$id
			)
		);

		// Automation history.
		$funnels = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fs.*, f.title AS funnel_title
				 FROM %i fs
				 LEFT JOIN %i f ON f.id = fs.funnel_id
				 WHERE fs.subscriber_id = %d
				 ORDER BY fs.created_at DESC",
				$funnel_subscribers,
				$funnels_table,
				$id
			)
		);

		return new \WP_REST_Response( array(
			'emails'     => $emails,
			'metrics'    => $metrics,
			'automations' => $funnels,
		) );
	}

	/* ── ACTIVITY LOG ────────────────────────────────── */

	public function activity_log( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$activity_log_table = $wpdb->prefix . 'aime_activity_log';
		$page               = absint( $request->get_param( 'page' ) ?: 1 );
		$per                = absint( $request->get_param( 'per_page' ) ?: 50 );
		$offset             = ( $page - 1 ) * $per;

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $activity_log_table ) );
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$activity_log_table,
				$per,
				$offset
			)
		);

		// Resolve legacy "campaign #N" descriptions to the campaign title when
		// still available (name first, id fallback).
		$campaign_ids = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item->description ) && preg_match( '/campaign #(\d+)/', $item->description, $m ) ) {
				$campaign_ids[ (int) $m[1] ] = true;
			}
		}
		if ( $campaign_ids ) {
			$campaigns_table = $wpdb->prefix . 'aime_campaigns';
			$ids             = array_map( 'intval', array_keys( $campaign_ids ) );
			$placeholders    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$titles          = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title FROM {$campaigns_table} WHERE id IN ({$placeholders})",
					...$ids
				),
				OBJECT_K
			);
			foreach ( $items as $item ) {
				if ( empty( $item->description ) || ! preg_match( '/campaign #(\d+)/', $item->description, $m ) ) {
					continue;
				}
				$cid = (int) $m[1];
				if ( isset( $titles[ $cid ] ) && '' !== (string) $titles[ $cid ]->title ) {
					$item->description = str_replace( "campaign #{$cid}", $titles[ $cid ]->title, $item->description );
				}
			}
		}

		return new \WP_REST_Response( array(
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'pages' => (int) ceil( $total / $per ),
		) );
	}

	/* ── FUNNEL ANALYTICS ────────────────────────────── */

	public function funnel_report( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$p                        = $wpdb->prefix;
		$funnels_table            = $p . 'aime_funnels';
		$funnel_sequences_table   = $p . 'aime_funnel_sequences';
		$funnel_metrics_table     = $p . 'aime_funnel_metrics';
		$funnel_subscribers_table = $p . 'aime_funnel_subscribers';
		$subscribers_table        = $p . 'aime_subscribers';
		$campaign_emails_table    = $p . 'aime_campaign_emails';
		$id                       = absint( $request->get_param( 'id' ) );

		$funnel = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $funnels_table, $id ) );
		if ( ! $funnel ) {
			return new \WP_REST_Response( array( 'message' => __( 'Automation not found.', 'ai-marketing-expert' ) ), 404 );
		}

		$sequences = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE funnel_id = %d ORDER BY sequence ASC', $funnel_sequences_table, $id )
		);

		// High-level funnel subscriber counts.
		$total_subs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE funnel_id = %d', $funnel_subscribers_table, $id ) );
		$active     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE funnel_id = %d AND status = 'active'", $funnel_subscribers_table, $id ) );
		$waiting    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE funnel_id = %d AND status = 'waiting'", $funnel_subscribers_table, $id ) );
		$completed  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE funnel_id = %d AND status = 'completed'", $funnel_subscribers_table, $id ) );
		$cancelled  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE funnel_id = %d AND status IN ('cancelled', 'failed')", $funnel_subscribers_table, $id ) );

		$completion_rate = $total_subs > 0 ? round( ( $completed / $total_subs ) * 100, 1 ) : 0;

		// Per-step execution analysis, drop-off rates, and email engagement metrics.
		$prev_step_completed = $total_subs;
		foreach ( $sequences as $index => &$seq ) {
			$seq->completed = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE funnel_id = %d AND sequence_id = %d AND status = 'completed'", $funnel_metrics_table, $id, $seq->id )
			);
			$seq->failed = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE funnel_id = %d AND sequence_id = %d AND status = 'failed'", $funnel_metrics_table, $id, $seq->id )
			);

			// Retention rate (% of total audience reached this step).
			$seq->retention_rate = $total_subs > 0 ? round( ( $seq->completed / $total_subs ) * 100, 1 ) : 0;

			// Step-to-step drop-off.
			if ( $prev_step_completed > 0 ) {
				$seq->step_dropoff_pct = round( max( 0, ( 1 - ( $seq->completed / $prev_step_completed ) ) * 100 ), 1 );
			} else {
				$seq->step_dropoff_pct = 0;
			}
			$prev_step_completed = $seq->completed;

			// For email steps, compute engagement (opens, clicks, and rates).
			if ( 'send_email' === $seq->action_name ) {
				$email_stats = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT 
							COUNT(DISTINCT CASE WHEN ce.is_open = 1 THEN fm.subscriber_id END) AS opens,
							COUNT(DISTINCT CASE WHEN ce.click_counter > 0 THEN fm.subscriber_id END) AS clicks
						 FROM %i fm
						 INNER JOIN %i ce 
							ON ce.subscriber_id = fm.subscriber_id 
							AND ce.email_type = 'automation'
							AND ABS(TIMESTAMPDIFF(MINUTE, ce.created_at, fm.created_at)) <= 30
						 WHERE fm.funnel_id = %d AND fm.sequence_id = %d AND fm.status = 'completed'",
						$funnel_metrics_table,
						$campaign_emails_table,
						$id,
						$seq->id
					)
				);

				$seq->opens      = (int) ( $email_stats->opens ?? 0 );
				$seq->clicks     = (int) ( $email_stats->clicks ?? 0 );
				$seq->open_rate  = $seq->completed > 0 ? round( ( $seq->opens / $seq->completed ) * 100, 1 ) : 0;
				$seq->click_rate = $seq->completed > 0 ? round( ( $seq->clicks / $seq->completed ) * 100, 1 ) : 0;
			} else {
				$seq->opens      = null;
				$seq->clicks     = null;
				$seq->open_rate  = null;
				$seq->click_rate = null;
			}
		}

		// Recent enrolled subscribers activity (latest 50).
		$recent_subscribers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fs.id, fs.subscriber_id, fs.status, fs.last_sequence_id, fs.next_sequence_id,
					fs.last_sequence_status, fs.last_executed_time, fs.next_execution_time, fs.created_at,
					s.email, s.first_name, s.last_name,
					seq_last.title AS last_step_title, seq_last.action_name AS last_step_action,
					seq_next.title AS next_step_title, seq_next.action_name AS next_step_action
				 FROM %i fs
				 INNER JOIN %i s ON s.id = fs.subscriber_id
				 LEFT JOIN %i seq_last ON seq_last.id = fs.last_sequence_id
				 LEFT JOIN %i seq_next ON seq_next.id = fs.next_sequence_id
				 WHERE fs.funnel_id = %d
				 ORDER BY fs.id DESC
				 LIMIT 50",
				$funnel_subscribers_table,
				$subscribers_table,
				$funnel_sequences_table,
				$funnel_sequences_table,
				$id
			)
		);

		// Daily completions trend (last 14 days).
		$since_14d = gmdate( 'Y-m-d H:i:s', strtotime( '-14 days' ) );
		$trend     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(created_at) AS date, COUNT(*) AS count
				 FROM %i
				 WHERE funnel_id = %d AND status = %s AND created_at >= %s
				 GROUP BY DATE(created_at)
				 ORDER BY date ASC',
				$funnel_metrics_table,
				$id,
				'completed',
				$since_14d
			)
		);

		return new \WP_REST_Response( array(
			'funnel'             => $funnel,
			'sequences'          => $sequences,
			'total_subscribers'  => $total_subs,
			'active'             => $active,
			'waiting'            => $waiting,
			'completed'          => $completed,
			'cancelled'          => $cancelled,
			'completion_rate'    => $completion_rate,
			'recent_subscribers' => $recent_subscribers ?: array(),
			'trend'              => $trend ?: array(),
		) );
	}
}
