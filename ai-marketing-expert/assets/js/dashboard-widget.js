/**
 * AI Marketing Expert — WP Dashboard Widget Script
 *
 * Smart polling: only refreshes chatbot section when tab is visible.
 * Full refresh via AJAX (no page reload). Dynamic "Updated X ago" counter.
 *
 * @package WPSpace\AiMarketingExpert
 */

/* global aimeWidgetData */
(function () {
	'use strict';

	if (typeof aimeWidgetData === 'undefined') {
		return;
	}

	var config = {
		ajaxUrl: aimeWidgetData.ajaxUrl,
		nonce: aimeWidgetData.nonce,
		hasActive: !!aimeWidgetData.hasActive,
		pollActive: 15000,
		pollIdle: 60000,
	};

	var pollTimer = null;
	var lastRefreshTimestamp = Math.floor(Date.now() / 1000);

	var widget = document.querySelector('.aime-dw');
	if (!widget) {
		return;
	}

	var refreshBtn = document.getElementById('aime-dw-refresh-btn');
	var liveBadge = document.getElementById('aime-dw-live-badge');
	var timestampEl = document.getElementById('aime-dw-timestamp');

	// ── Chatbot real-time polling ─────────────────────

	function fetchChatbotRealtime() {
		var data = new FormData();
		data.append('action', 'aime_chatbot_realtime');
		data.append('nonce', config.nonce);

		fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res.success || !res.data) return;
				updateChatbotUI(res.data);
				config.hasActive = res.data.active_count > 0;
				schedulePoll(config.hasActive ? config.pollActive : config.pollIdle);
			})
			.catch(function () {});
	}

	function updateChatbotUI(data) {
		setText('aime-dw-active-count', data.active_count);
		setText('aime-dw-messages-today', data.messages_today);
		setText('aime-dw-leads-today', data.leads_today);

		if (liveBadge) {
			if (data.active_count > 0) {
				liveBadge.style.display = '';
				var label = data.active_count === 1 ? '1 active' : data.active_count + ' active';
				var dot = liveBadge.querySelector('.aime-dw__live-dot');
				if (dot && dot.nextSibling) {
					dot.nextSibling.textContent = ' ' + label;
				}
			} else {
				liveBadge.style.display = 'none';
			}
		}

		var feedEl = document.getElementById('aime-dw-chatbot-feed');
		if (feedEl) {
			feedEl.innerHTML = data.recent && data.recent.length > 0 ? buildFeedHTML(data.recent) : '';
		}
	}

	function buildFeedHTML(items) {
		var html = '';
		for (var i = 0; i < items.length; i++) {
			var c = items[i];
			var dotClass = c.status === 'active' ? 'aime-dw__feed-dot--green' : 'aime-dw__feed-dot--orange';
			var itemClass = 'aime-dw__feed-item' + (c.status === 'human_takeover' ? ' aime-dw__feed-item--attention' : '');
			var pagePath = '';
			if (c.page_url) {
				try { pagePath = new URL(c.page_url).pathname; } catch (e) { pagePath = c.page_url; }
			}
			html += '<div class="' + itemClass + '">';
			html += '<span class="aime-dw__feed-dot ' + dotClass + '"></span>';
			html += '<span class="aime-dw__feed-name">' + escapeHTML(c.visitor_name) + '</span>';
			if (pagePath) html += '<span class="aime-dw__feed-page">' + escapeHTML(pagePath) + '</span>';
			html += '<span class="aime-dw__feed-meta">' + c.msg_count + ' msg' + (c.msg_count !== 1 ? 's' : '') + '</span>';
			html += '<span class="aime-dw__feed-time">' + escapeHTML(c.time_ago || '') + '</span>';
			html += '</div>';
		}
		return html;
	}

	// ── Full widget refresh (AJAX, no page reload) ────

	function fetchFullRefresh() {
		if (refreshBtn) refreshBtn.classList.add('aime-dw__refresh--spin');

		var data = new FormData();
		data.append('action', 'aime_dashboard_widget_refresh');
		data.append('nonce', config.nonce);

		fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (refreshBtn) refreshBtn.classList.remove('aime-dw__refresh--spin');
				if (!res.success || !res.data) return;
				var d = res.data;

				// Update chatbot
				updateChatbotUI(d.chatbot);
				config.hasActive = d.chatbot.active_count > 0;

				// Update automation
				setText('aime-dw-auto-active', d.auto.active_workflows);
				setText('aime-dw-auto-runs', d.auto.runs_this_month);
				var limitEl = document.getElementById('aime-dw-auto-runs-limit');
				if (limitEl) {
					limitEl.textContent = d.auto.runs_monthly_limit ? '/' + d.auto.runs_monthly_limit : '';
				}
				setText('aime-dw-auto-next', formatNextRun(d.auto.next_run_at));

				// Update email
				setText('aime-dw-email-contacts', numberFormat(d.email.active_contacts));
				setText('aime-dw-email-campaigns', d.email.total_campaigns);
				setText('aime-dw-email-openrate', d.email.open_rate);

				// Update compact rows
				updateCompact('aime-dw-seo', d.seo);
				updateCompact('aime-dw-content', d.content);
				updateCompact('aime-dw-social', d.social);

				// Update timestamp
				lastRefreshTimestamp = d.time;
				updateTimestamp();
			})
			.catch(function () {
				if (refreshBtn) refreshBtn.classList.remove('aime-dw__refresh--spin');
			});
	}

	function updateCompact(prefix, items) {
		for (var i = 0; i < items.length; i++) {
			var el = document.getElementById(prefix + '-' + i);
			if (el) el.textContent = items[i].value;
		}
	}

	function formatNextRun(nextRunAt) {
		if (!nextRunAt) return '--';
		var ts = Math.floor(new Date(nextRunAt + 'Z').getTime() / 1000);
		var diff = ts - Math.floor(Date.now() / 1000);
		if (diff <= 0) return 'Overdue';
		if (diff < 60) return diff + 's';
		if (diff < 3600) return Math.floor(diff / 60) + 'm';
		return Math.floor(diff / 3600) + 'h';
	}

	function numberFormat(n) {
		if (typeof Intl !== 'undefined' && Intl.NumberFormat) {
			return new Intl.NumberFormat().format(n);
		}
		return String(n);
	}

	// ── Dynamic timestamp ─────────────────────────────

	function updateTimestamp() {
		if (!timestampEl) return;
		var now = Math.floor(Date.now() / 1000);
		var diff = now - lastRefreshTimestamp;
		var text;
		if (diff < 10) text = 'Updated just now';
		else if (diff < 60) text = 'Updated just now';
		else if (diff < 3600) text = 'Updated ' + Math.floor(diff / 60) + ' minutes ago';
		else text = 'Updated ' + Math.floor(diff / 3600) + ' hours ago';
		timestampEl.textContent = text;
	}

	setInterval(updateTimestamp, 1000);

	// ── Polling control ───────────────────────────────

	function schedulePoll(interval) {
		clearPoll();
		pollTimer = setTimeout(function () {
			if (!document.hidden) {
				fetchChatbotRealtime();
			} else {
				schedulePoll(interval);
			}
		}, interval);
	}

	function clearPoll() {
		if (pollTimer) {
			clearTimeout(pollTimer);
			pollTimer = null;
		}
	}

	// ── Page Visibility API ───────────────────────────

	if (document.addEventListener) {
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				clearPoll();
			} else {
				fetchChatbotRealtime();
			}
		});
	}

	// ── Event listeners ───────────────────────────────

	if (refreshBtn) {
		refreshBtn.addEventListener('click', function (e) {
			e.preventDefault();
			fetchFullRefresh();
		});
	}

	// ── Helpers ───────────────────────────────────────

	function setText(id, value) {
		var el = document.getElementById(id);
		if (el) el.textContent = value;
	}

	function escapeHTML(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	// ── Init ──────────────────────────────────────────

	if (!document.hidden) {
		var startInterval = config.hasActive ? config.pollActive : config.pollIdle;
		schedulePoll(startInterval);
	}

})();
