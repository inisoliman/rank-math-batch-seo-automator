<?php
/**
 * Plugin Name: Rank Math Batch SEO Automator
 * Description: Batch updates missing or full Rank Math SEO metadata with safe-mode markers, dry-run previews, audit reports, and CSV export.
 * Version: 1.1.0
 * Author: Ibrahim.N.I.soliman
 * Author URI: mailto:ibrahim.noshy@hotmail.com
 * License: GPL-2.0-or-later
 * Text Domain: rank-math-batch-seo-automator
 *
 * Copyright (c) 2026 Ibrahim.N.I.soliman.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'RankMath_Batch_SEO_Automator' ) ) {
	final class RankMath_Batch_SEO_Automator {
		const VERSION        = '1.1.0';
		const OPTION_JOB     = 'rmbsa_job';
		const NONCE_ACTION   = 'rmbsa_nonce';
		const CRON_HOOK      = 'rmbsa_process';
		const SAFE_DONE_META = '_rmbsa_safe_done';
		const BACKUP_META    = '_rmbsa_backup';
		const REPORT_META    = '_rmbsa_report';
		const CSV_ACTION     = 'rmbsa_export_report';

		/**
		 * Register hooks.
		 */
		public static function init() {
			$plugin = new self();

			add_action( 'admin_menu', array( $plugin, 'add_admin_page' ) );
			add_action( 'wp_ajax_rmbsa_start', array( $plugin, 'ajax_start' ) );
			add_action( 'wp_ajax_rmbsa_status', array( $plugin, 'ajax_status' ) );
			add_action( 'wp_ajax_rmbsa_process_batch', array( $plugin, 'ajax_process_batch' ) );
			add_action( 'wp_ajax_rmbsa_stop', array( $plugin, 'ajax_stop' ) );
			add_action( 'wp_ajax_rmbsa_clear', array( $plugin, 'ajax_clear' ) );
			add_action( 'admin_post_' . self::CSV_ACTION, array( $plugin, 'export_report_csv' ) );
			add_action( self::CRON_HOOK, array( $plugin, 'process_scheduled_batch' ) );
		}

		/**
		 * Clear stale scheduled events when activating.
		 */
		public static function activate() {
			self::clear_schedule();
		}

		/**
		 * Clear scheduled events when deactivating.
		 */
		public static function deactivate() {
			self::clear_schedule();
		}

		/**
		 * Add the admin page under Tools.
		 */
		public function add_admin_page() {
			add_management_page(
				'Rank Math Batch SEO Automator',
				'Rank Math Batch SEO',
				'manage_options',
				'rank-math-batch-seo-automator',
				array( $this, 'render_admin_page' )
			);
		}

		/**
		 * Render the admin page.
		 */
		public function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$job             = $this->get_job();
			$nonce           = wp_create_nonce( self::NONCE_ACTION );
			$rank_math_ready = $this->is_rank_math_active();
			$report_rows     = $this->get_report_rows( 50 );
			$export_url      = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . self::CSV_ACTION ),
				self::CSV_ACTION
			);
			?>
			<div class="wrap rmbsa-rm-auto-seo">
				<h1>Rank Math Batch SEO Automator</h1>

				<?php if ( ! $rank_math_ready ) : ?>
					<div class="notice notice-warning">
						<p>Rank Math does not appear to be active. The tool can still prepare metadata keys, but activate Rank Math before running this on production.</p>
					</div>
				<?php endif; ?>

				<div class="rmbsa-rm-grid">
					<section class="rmbsa-rm-panel">
						<h2>Run Settings</h2>
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row">Mode</th>
									<td>
										<label><input type="radio" name="rmbsa-mode" value="safe" checked> Safe Mode: fill missing fields only</label><br>
										<label><input type="radio" name="rmbsa-mode" value="full"> Full Site Mode: update all managed fields</label>
									</td>
								</tr>
								<tr>
									<th scope="row">Batch size</th>
									<td><input type="number" id="rmbsa-batch-size" min="5" max="200" step="5" value="50"></td>
								</tr>
								<tr>
									<th scope="row">Dry run</th>
									<td><label><input type="checkbox" id="rmbsa-dry-run" checked> Preview changes without writing post meta</label></td>
								</tr>
								<tr>
									<th scope="row">Full Site safety</th>
									<td><label><input type="checkbox" id="rmbsa-skip-safe-done" checked> Skip posts already completed by Safe Mode</label></td>
								</tr>
							</tbody>
						</table>

						<p class="submit">
							<button type="button" class="button button-primary" id="rmbsa-start">Start / Preview</button>
							<button type="button" class="button" id="rmbsa-stop">Stop</button>
							<button type="button" class="button" id="rmbsa-clear">Clear Job</button>
						</p>
					</section>

					<section class="rmbsa-rm-panel">
						<h2>Status</h2>
						<div id="rmbsa-status">Loading...</div>
						<h3>Recent log</h3>
						<ol id="rmbsa-log"></ol>
					</section>
				</div>

				<section class="rmbsa-rm-panel rmbsa-rm-report">
					<h2>Review Report</h2>
					<p>This table shows the latest posts that were actually changed by the plugin. Dry runs are preview-only and are not saved here.</p>
					<p><a class="button" href="<?php echo esc_url( $export_url ); ?>">Export report CSV</a></p>

					<?php if ( empty( $report_rows ) ) : ?>
						<p>No saved changes yet. Run Safe Mode or Full Site Mode with Dry Run disabled, then refresh this page.</p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th>Post</th>
									<th>Mode</th>
									<th>Changed fields</th>
									<th>Details</th>
									<th>Updated</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $report_rows as $row ) : ?>
									<tr>
										<td>
											<strong><?php echo esc_html( $row['title'] ); ?></strong><br>
											<a href="<?php echo esc_url( $row['edit_url'] ); ?>">Edit post #<?php echo esc_html( (string) $row['post_id'] ); ?></a>
										</td>
										<td><?php echo esc_html( $row['mode'] ); ?></td>
										<td><?php echo esc_html( implode( ', ', $row['fields'] ) ); ?></td>
										<td>
											<details>
												<summary>Show old and new values</summary>
												<table class="widefat">
													<thead>
														<tr>
															<th>Field</th>
															<th>Before</th>
															<th>After</th>
														</tr>
													</thead>
													<tbody>
														<?php foreach ( $row['changes'] as $field => $change ) : ?>
															<tr>
																<td><?php echo esc_html( $field ); ?></td>
																<td><?php echo esc_html( $this->format_report_value( $change['before'] ) ); ?></td>
																<td><?php echo esc_html( $this->format_report_value( $change['after'] ) ); ?></td>
															</tr>
														<?php endforeach; ?>
													</tbody>
												</table>
											</details>
										</td>
										<td><?php echo esc_html( $row['updated_at'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</section>
			</div>

			<style>
				.rmbsa-rm-grid {
					display: grid;
					grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
					gap: 20px;
					margin-top: 20px;
				}
				.rmbsa-rm-panel {
					background: #fff;
					border: 1px solid #dcdcde;
					padding: 20px;
				}
				#rmbsa-status code {
					display: inline-block;
					margin: 0 8px 8px 0;
					padding: 4px 8px;
					background: #f0f0f1;
				}
				#rmbsa-log {
					max-height: 320px;
					overflow: auto;
				}
				.rmbsa-rm-report {
					margin-top: 20px;
				}
				.rmbsa-rm-report details {
					max-width: 760px;
				}
				.rmbsa-rm-report details table {
					margin-top: 10px;
				}
				@media (max-width: 900px) {
					.rmbsa-rm-grid {
						grid-template-columns: 1fr;
					}
				}
			</style>

			<script>
				(function() {
					const ajaxUrl = window.ajaxurl;
					const nonce = <?php echo wp_json_encode( $nonce ); ?>;
					const statusEl = document.getElementById('rmbsa-status');
					const logEl = document.getElementById('rmbsa-log');
					let timer = null;

					function selectedMode() {
						const input = document.querySelector('input[name="rmbsa-mode"]:checked');
						return input ? input.value : 'safe';
					}

					function request(action, data) {
						const body = new URLSearchParams();
						body.set('action', 'rmbsa_' + action);
						body.set('nonce', nonce);
						Object.keys(data || {}).forEach(function(key) {
							body.set(key, data[key]);
						});

						return fetch(ajaxUrl, {
							method: 'POST',
							credentials: 'same-origin',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body: body.toString()
						}).then(function(response) {
							return response.json();
						});
					}

					function render(job) {
						const safeSkip = job.skip_safe_done ? 'yes' : 'no';
						const dryRun = job.dry_run ? 'yes' : 'no';
						statusEl.innerHTML = [
							'<code>Status: ' + job.status + '</code>',
							'<code>Mode: ' + job.mode + '</code>',
							'<code>Dry run: ' + dryRun + '</code>',
							'<code>Skip safe done: ' + safeSkip + '</code>',
							'<code>Last ID: ' + job.last_id + '</code>',
							'<code>Processed: ' + job.processed + '</code>',
							'<code>Updated / would update: ' + job.updated + '</code>',
							'<code>Skipped: ' + job.skipped + '</code>',
							'<code>Errors: ' + job.errors + '</code>'
						].join(' ');

						logEl.innerHTML = '';
						(job.log || []).slice().reverse().forEach(function(row) {
							const item = document.createElement('li');
							item.textContent = row;
							logEl.appendChild(item);
						});
					}

					function poll() {
						request('status', {}).then(function(payload) {
							if (!payload.success) {
								return;
							}
							render(payload.data.job);
							if (payload.data.job.status === 'running') {
								request('process_batch', {}).then(function(batchPayload) {
									if (batchPayload.success) {
										render(batchPayload.data.job);
									}
								});
							}
						});
					}

					document.getElementById('rmbsa-start').addEventListener('click', function() {
						request('start', {
							mode: selectedMode(),
							batch_size: document.getElementById('rmbsa-batch-size').value,
							dry_run: document.getElementById('rmbsa-dry-run').checked ? '1' : '0',
							skip_safe_done: document.getElementById('rmbsa-skip-safe-done').checked ? '1' : '0'
						}).then(function(payload) {
							if (payload.success) {
								render(payload.data.job);
								poll();
							} else {
								alert(payload.data && payload.data.message ? payload.data.message : 'Could not start job.');
							}
						});
					});

					document.getElementById('rmbsa-stop').addEventListener('click', function() {
						request('stop', {}).then(function(payload) {
							if (payload.success) {
								render(payload.data.job);
							}
						});
					});

					document.getElementById('rmbsa-clear').addEventListener('click', function() {
						request('clear', {}).then(function(payload) {
							if (payload.success) {
								render(payload.data.job);
							}
						});
					});

					poll();
					timer = window.setInterval(poll, 5000);
					window.addEventListener('beforeunload', function() {
						if (timer) {
							window.clearInterval(timer);
						}
					});
				})();
			</script>
			<?php
		}

		/**
		 * Start a new job.
		 */
		public function ajax_start() {
			$this->require_admin_ajax();

			$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'safe';
			if ( ! in_array( $mode, array( 'safe', 'full' ), true ) ) {
				$mode = 'safe';
			}

			$batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 50;
			$batch_size = max( 5, min( 200, $batch_size ) );

			$job = array(
				'id'             => uniqid( 'rmbsa_', true ),
				'status'         => 'running',
				'mode'           => $mode,
				'dry_run'        => ! empty( $_POST['dry_run'] ),
				'batch_size'     => $batch_size,
				'skip_safe_done' => ! empty( $_POST['skip_safe_done'] ),
				'post_types'     => array( 'post', 'page' ),
				'last_id'        => 0,
				'processed'      => 0,
				'updated'        => 0,
				'skipped'        => 0,
				'errors'         => 0,
				'started'        => current_time( 'mysql' ),
				'finished'       => '',
				'log'            => array(),
			);

			$job = $this->add_log( $job, 'Started ' . $mode . ' job.' );
			update_option( self::OPTION_JOB, $job, false );
			$this->schedule_next_batch();

			wp_send_json_success( array( 'job' => $job ) );
		}

		/**
		 * Return the current job.
		 */
		public function ajax_status() {
			$this->require_admin_ajax();
			wp_send_json_success( array( 'job' => $this->get_job() ) );
		}

		/**
		 * Process one AJAX-driven batch.
		 */
		public function ajax_process_batch() {
			$this->require_admin_ajax();
			$job = $this->process_batch();
			wp_send_json_success( array( 'job' => $job ) );
		}

		/**
		 * Stop the current job.
		 */
		public function ajax_stop() {
			$this->require_admin_ajax();

			$job             = $this->get_job();
			$job['status']   = 'stopped';
			$job['finished'] = current_time( 'mysql' );
			$job             = $this->add_log( $job, 'Stopped by admin.' );

			update_option( self::OPTION_JOB, $job, false );
			self::clear_schedule();

			wp_send_json_success( array( 'job' => $job ) );
		}

		/**
		 * Clear the current job state.
		 */
		public function ajax_clear() {
			$this->require_admin_ajax();
			delete_option( self::OPTION_JOB );
			self::clear_schedule();

			wp_send_json_success( array( 'job' => $this->get_job() ) );
		}

		/**
		 * Export saved review rows as CSV.
		 */
		public function export_report_csv() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to export this report.', 'rank-math-batch-seo-automator' ) );
			}

			check_admin_referer( self::CSV_ACTION );

			$rows     = $this->get_report_rows( 1000 );
			$filename = 'rank-math-batch-seo-automator-report-' . gmdate( 'Y-m-d-His' ) . '.csv';

			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $filename );

			$output = fopen( 'php://output', 'w' );
			fputcsv( $output, array( 'post_id', 'post_title', 'edit_url', 'mode', 'updated_at', 'field', 'before', 'after' ) );

			foreach ( $rows as $row ) {
				foreach ( $row['changes'] as $field => $change ) {
					fputcsv(
						$output,
						array(
							$row['post_id'],
							$row['title'],
							$row['edit_url'],
							$row['mode'],
							$row['updated_at'],
							$field,
							$this->format_report_value( $change['before'] ),
							$this->format_report_value( $change['after'] ),
						)
					);
				}
			}

			fclose( $output );
			exit;
		}

		/**
		 * Cron entry point.
		 */
		public function process_scheduled_batch() {
			$this->process_batch();
		}

		/**
		 * Process one batch and return the updated job.
		 */
		private function process_batch() {
			$job = $this->get_job();
			if ( 'running' !== $job['status'] ) {
				return $job;
			}

			$ids = $this->get_next_post_ids( $job );
			if ( empty( $ids ) ) {
				$job['status']   = 'complete';
				$job['finished'] = current_time( 'mysql' );
				$job             = $this->add_log( $job, 'No more published posts or pages to process.' );
				update_option( self::OPTION_JOB, $job, false );
				self::clear_schedule();
				return $job;
			}

			foreach ( $ids as $post_id ) {
				$post_id        = absint( $post_id );
				$job['last_id'] = max( absint( $job['last_id'] ), $post_id );
				$result         = $this->process_post( $post_id, $job );

				++$job['processed'];
				if ( ! empty( $result['error'] ) ) {
					++$job['errors'];
				} elseif ( ! empty( $result['updated'] ) ) {
					++$job['updated'];
				} else {
					++$job['skipped'];
				}

				if ( ! empty( $result['message'] ) ) {
					$job = $this->add_log( $job, $result['message'] );
				}
			}

			if ( count( $ids ) < absint( $job['batch_size'] ) ) {
				$job['status']   = 'complete';
				$job['finished'] = current_time( 'mysql' );
				$job             = $this->add_log( $job, 'Completed final batch.' );
				self::clear_schedule();
			} else {
				$this->schedule_next_batch();
			}

			update_option( self::OPTION_JOB, $job, false );
			return $job;
		}

		/**
		 * Process one post.
		 */
		private function process_post( $post_id, $job ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				return array(
					'updated' => false,
					'message' => 'Skipped #' . $post_id . ': not a published post.',
				);
			}

			if (
				'full' === $job['mode'] &&
				! empty( $job['skip_safe_done'] ) &&
				get_post_meta( $post_id, self::SAFE_DONE_META, true )
			) {
				return array(
					'updated' => false,
					'message' => 'Skipped #' . $post_id . ': already completed by Safe Mode.',
				);
			}

			$changes = $this->build_changes( $post_id, $post, $job );
			if ( empty( $changes ) ) {
				return array(
					'updated' => false,
					'message' => 'Skipped #' . $post_id . ': no metadata changes needed.',
				);
			}

			if ( ! empty( $job['dry_run'] ) ) {
				return array(
					'updated' => true,
					'message' => 'Would update #' . $post_id . ': ' . implode( ', ', array_keys( $changes ) ) . '.',
				);
			}

			$before_values = $this->get_managed_meta( $post_id );

			$this->backup_post_meta( $post_id );
			foreach ( $changes as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}

			if ( 'safe' === $job['mode'] ) {
				update_post_meta( $post_id, self::SAFE_DONE_META, 1 );
			}

			if ( isset( $changes['rank_math_robots'] ) ) {
				do_action( 'rank_math/sitemap/invalidate_object_type', 'post', $post_id );
			}

			$this->save_report_row( $post_id, $job, $before_values, $changes );
			$this->refresh_rank_math_analytics( $post_id );

			return array(
				'updated' => true,
				'message' => 'Updated #' . $post_id . ': ' . implode( ', ', array_keys( $changes ) ) . '.',
			);
		}

		/**
		 * Build meta changes for one post.
		 */
		private function build_changes( $post_id, $post, $job ) {
			$mode    = $job['mode'];
			$changes = array();

			$focus_keyword = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
			$seo_title     = get_post_meta( $post_id, 'rank_math_title', true );
			$description   = get_post_meta( $post_id, 'rank_math_description', true );
			$robots        = get_post_meta( $post_id, 'rank_math_robots', true );
			$primary_cat   = get_post_meta( $post_id, 'rank_math_primary_category', true );

			$new_keyword = self::keyword_from_title( get_the_title( $post_id ) );
			if ( $new_keyword && ( 'full' === $mode || '' === trim( (string) $focus_keyword ) ) ) {
				$changes['rank_math_focus_keyword'] = $new_keyword;
			}

			if ( 'full' === $mode || '' === trim( (string) $seo_title ) ) {
				$changes['rank_math_title'] = '%title% %sep% %sitename%';
			}

			$new_description = self::description_from_post( $post );
			if ( $new_description && ( 'full' === $mode || '' === trim( (string) $description ) ) ) {
				$changes['rank_math_description'] = $new_description;
			}

			if ( 'full' === $mode || empty( $robots ) ) {
				$changes['rank_math_robots'] = self::ensure_index_robots( $robots );
			}

			if ( empty( $primary_cat ) && 'post' === get_post_type( $post_id ) ) {
				$categories = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
				if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
					$changes['rank_math_primary_category'] = absint( $categories[0] );
				}
			}

			return $changes;
		}

		/**
		 * Query the next ordered post IDs.
		 */
		private function get_next_post_ids( $job ) {
			global $wpdb;

			$post_types   = ! empty( $job['post_types'] ) && is_array( $job['post_types'] ) ? $job['post_types'] : array( 'post', 'page' );
			$post_types   = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
			if ( empty( $post_types ) ) {
				$post_types = array( 'post', 'page' );
			}

			$last_id      = absint( $job['last_id'] );
			$limit        = max( 1, absint( $job['batch_size'] ) );
			$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
			$params       = array_merge( array( 'publish' ), $post_types, array( $last_id, $limit ) );

			$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN ({$placeholders}) AND ID > %d ORDER BY ID ASC LIMIT %d";

			return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
		}

		/**
		 * Save a one-time backup of fields managed by this plugin.
		 */
		private function backup_post_meta( $post_id ) {
			if ( get_post_meta( $post_id, self::BACKUP_META, true ) ) {
				return;
			}

			$backup = array(
				'created'                    => current_time( 'mysql' ),
				'rank_math_focus_keyword'    => get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
				'rank_math_title'            => get_post_meta( $post_id, 'rank_math_title', true ),
				'rank_math_description'      => get_post_meta( $post_id, 'rank_math_description', true ),
				'rank_math_robots'           => get_post_meta( $post_id, 'rank_math_robots', true ),
				'rank_math_primary_category' => get_post_meta( $post_id, 'rank_math_primary_category', true ),
			);

			add_post_meta( $post_id, self::BACKUP_META, $backup, true );
		}

		/**
		 * Return all Rank Math fields managed by this plugin.
		 */
		private function get_managed_meta( $post_id ) {
			return array(
				'rank_math_focus_keyword'    => get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
				'rank_math_title'            => get_post_meta( $post_id, 'rank_math_title', true ),
				'rank_math_description'      => get_post_meta( $post_id, 'rank_math_description', true ),
				'rank_math_robots'           => get_post_meta( $post_id, 'rank_math_robots', true ),
				'rank_math_primary_category' => get_post_meta( $post_id, 'rank_math_primary_category', true ),
			);
		}

		/**
		 * Store a review row for an actual write.
		 */
		private function save_report_row( $post_id, $job, $before_values, $changes ) {
			$details = array();
			foreach ( $changes as $field => $after_value ) {
				$details[ $field ] = array(
					'before' => array_key_exists( $field, $before_values ) ? $before_values[ $field ] : '',
					'after'  => $after_value,
				);
			}

			add_post_meta(
				$post_id,
				self::REPORT_META,
				array(
					'job_id'     => $job['id'],
					'mode'       => $job['mode'],
					'updated_at' => current_time( 'mysql' ),
					'fields'     => array_keys( $changes ),
					'changes'    => $details,
				)
			);
		}

		/**
		 * Load recent review rows.
		 */
		private function get_report_rows( $limit ) {
			global $wpdb;

			$limit = max( 1, min( 1000, absint( $limit ) ) );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id DESC LIMIT %d",
					self::REPORT_META,
					$limit
				)
			);

			$report_rows = array();
			foreach ( $rows as $row ) {
				$report = maybe_unserialize( $row->meta_value );
				if ( ! is_array( $report ) || empty( $report['changes'] ) ) {
					continue;
				}

				$post_id       = absint( $row->post_id );
				$report_rows[] = array(
					'post_id'    => $post_id,
					'title'      => get_the_title( $post_id ),
					'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
					'mode'       => isset( $report['mode'] ) ? sanitize_key( $report['mode'] ) : '',
					'updated_at' => isset( $report['updated_at'] ) ? sanitize_text_field( $report['updated_at'] ) : '',
					'fields'     => isset( $report['fields'] ) && is_array( $report['fields'] ) ? $report['fields'] : array_keys( $report['changes'] ),
					'changes'    => $report['changes'],
				);
			}

			return $report_rows;
		}

		/**
		 * Format a report value for table and CSV output.
		 */
		private function format_report_value( $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				return wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
			}

			return (string) $value;
		}

		/**
		 * Clean a title into a focus keyword.
		 */
		public static function keyword_from_title( $title ) {
			$title = self::clean_text( $title );
			if ( function_exists( 'mb_substr' ) ) {
				return trim( mb_substr( $title, 0, 80 ) );
			}

			return trim( substr( $title, 0, 80 ) );
		}

		/**
		 * Build a meta description from excerpt, then content.
		 */
		public static function description_from_post( $post ) {
			$text = '';
			if ( ! empty( $post->post_excerpt ) ) {
				$text = $post->post_excerpt;
			} elseif ( ! empty( $post->post_content ) ) {
				$text = $post->post_content;
			}

			$text = self::clean_text( $text );
			if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
				return mb_strlen( $text ) > 160 ? trim( mb_substr( $text, 0, 157 ) ) . '...' : $text;
			}

			return strlen( $text ) > 160 ? trim( substr( $text, 0, 157 ) ) . '...' : $text;
		}

		/**
		 * Ensure robots contains index and not noindex.
		 */
		public static function ensure_index_robots( $robots ) {
			$robots = is_array( $robots ) ? $robots : array_filter( (array) $robots );
			$robots = array_values( array_filter( array_map( 'sanitize_key', $robots ) ) );
			$robots = array_diff( $robots, array( 'noindex' ) );

			if ( ! in_array( 'index', $robots, true ) ) {
				$robots[] = 'index';
			}

			return array_values( array_unique( $robots ) );
		}

		/**
		 * Clean text for SEO fields.
		 */
		private static function clean_text( $text ) {
			$text = (string) $text;
			if ( function_exists( 'strip_shortcodes' ) ) {
				$text = strip_shortcodes( $text );
			}

			$text = wp_strip_all_tags( $text );
			$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
			$text = preg_replace( '/\s+/u', ' ', $text );

			return trim( $text );
		}

		/**
		 * Refresh Rank Math analytics object data when available.
		 */
		private function refresh_rank_math_analytics( $post_id ) {
			if ( ! class_exists( '\RankMath\Analytics\Watcher' ) ) {
				return;
			}

			try {
				\RankMath\Analytics\Watcher::get()->update_post_info( $post_id );
			} catch ( \Throwable $error ) {
				return;
			}
		}

		/**
		 * AJAX permission gate.
		 */
		private function require_admin_ajax() {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'You do not have permission to run this tool.' ), 403 );
			}
		}

		/**
		 * Return the stored job or defaults.
		 */
		private function get_job() {
			$job = get_option( self::OPTION_JOB, array() );
			if ( ! is_array( $job ) ) {
				$job = array();
			}

			return array_merge(
				array(
					'id'             => '',
					'status'         => 'idle',
					'mode'           => 'safe',
					'dry_run'        => true,
					'batch_size'     => 50,
					'skip_safe_done' => true,
					'post_types'     => array( 'post', 'page' ),
					'last_id'        => 0,
					'processed'      => 0,
					'updated'        => 0,
					'skipped'        => 0,
					'errors'         => 0,
					'started'        => '',
					'finished'       => '',
					'log'            => array(),
				),
				$job
			);
		}

		/**
		 * Append a bounded log message.
		 */
		private function add_log( $job, $message ) {
			if ( empty( $job['log'] ) || ! is_array( $job['log'] ) ) {
				$job['log'] = array();
			}

			$job['log'][] = current_time( 'mysql' ) . ' - ' . $message;
			$job['log']   = array_slice( $job['log'], -50 );

			return $job;
		}

		/**
		 * Schedule a follow-up batch.
		 */
		private function schedule_next_batch() {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time() + 15, self::CRON_HOOK );
			}
		}

		/**
		 * Clear all scheduled batches.
		 */
		private static function clear_schedule() {
			while ( $timestamp = wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_unschedule_event( $timestamp, self::CRON_HOOK );
			}
		}

		/**
		 * Check Rank Math availability.
		 */
		private function is_rank_math_active() {
			return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) || class_exists( '\RankMath\Helper' );
		}
	}
}

register_activation_hook( __FILE__, array( 'RankMath_Batch_SEO_Automator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RankMath_Batch_SEO_Automator', 'deactivate' ) );
RankMath_Batch_SEO_Automator::init();

