<?php
/**
 * Plugin Name: Lunara IMDb Guard
 * Plugin URI: https://lunarafilm.com/
 * Description: Validates review IMDb IDs against title and year, auto-fills clear matches, syncs TMDB poster/backdrop artwork, and provides an editorial audit screen for Lunara.
 * Version: 0.2.0
 * Author: Lunara Film
 * Author URI: https://lunarafilm.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lunara-imdb-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUNARA_IMDB_GUARD_VERSION', '0.2.0' );
define( 'LUNARA_IMDB_GUARD_FILE', __FILE__ );
define( 'LUNARA_IMDB_GUARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUNARA_IMDB_GUARD_DEFAULT_OMDB_API_KEY', '' );

final class Lunara_IMDb_Guard {

	const OPTION_API_KEY      = 'lunara_imdb_guard_omdb_api_key';
	const OPTION_TMDB_API_KEY = 'lunara_imdb_guard_tmdb_api_key';
	const META_STATUS    = '_lunara_imdb_guard_status';
	const META_MESSAGE   = '_lunara_imdb_guard_message';
	const META_EXPECTED  = '_lunara_imdb_guard_expected_id';
	const META_TITLE     = '_lunara_imdb_guard_expected_title';
	const META_YEAR      = '_lunara_imdb_guard_expected_year';
	const META_CHECKED   = '_lunara_imdb_guard_last_checked';
	const META_LOOKUP    = '_lunara_imdb_guard_lookup_title';
	
	// Custom fields for the TMDB image URLs
	const META_POSTER    = '_lunara_tmdb_poster_url';
	const META_BACKDROP  = '_lunara_tmdb_backdrop_url';

	/**
	 * Singleton instance.
	 *
	 * @var Lunara_IMDb_Guard|null
	 */
	private static $instance = null;

	/**
	 * Bootstrap singleton.
	 *
	 * @return Lunara_IMDb_Guard
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_review', array( $this, 'validate_review_on_save' ), 50, 3 );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );

		add_action( 'admin_post_lunara_imdb_guard_validate', array( $this, 'handle_validate_request' ) );
		add_action( 'admin_post_lunara_imdb_guard_apply', array( $this, 'handle_apply_request' ) );
		add_action( 'admin_post_lunara_imdb_guard_bulk_audit', array( $this, 'handle_bulk_audit_request' ) );
		add_action( 'admin_post_lunara_imdb_guard_save_settings', array( $this, 'handle_save_settings_request' ) );
	}

	/**
	 * Register the review-side meta box.
	 */
	public function register_meta_box() {
		add_meta_box(
			'lunara_imdb_guard',
			__( 'Lunara IMDb Guard', 'lunara-imdb-guard' ),
			array( $this, 'render_meta_box' ),
			'review',
			'side',
			'high'
		);
	}

	/**
	 * Add the audit page under Reviews.
	 */
	public function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=review',
			__( 'IMDb Guard', 'lunara-imdb-guard' ),
			__( 'IMDb Guard', 'lunara-imdb-guard' ),
			'edit_posts',
			'lunara-imdb-guard',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Save-time validator and missing-ID autofill.
	 *
	 * @param int      $post_id Review post id.
	 * @param WP_Post  $post    Current post.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 */
	public function validate_review_on_save( $post_id, $post, $update ) {
		if ( ! $post instanceof WP_Post || 'review' !== $post->post_type ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->save_lookup_override( $post_id );
		$this->validate_review( $post_id, true );
	}

	/**
	 * Render the editor meta box.
	 *
	 * @param WP_Post $post Current review post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$post_id        = (int) $post->ID;
		$year           = trim( (string) get_post_meta( $post_id, '_lunara_year', true ) );
		$current_id     = $this->normalize_imdb_id( get_post_meta( $post_id, '_lunara_imdb_title_id', true ) );
		$state          = $this->get_stored_state( $post_id );
		$map_path       = $this->get_theme_map_path();
		$map_state      = $map_path && is_writable( $map_path ) ? __( 'Writable', 'lunara-imdb-guard' ) : __( 'Read-only or missing', 'lunara-imdb-guard' );
		$lookup_context = $this->resolve_lookup_context( $post_id );
		$lookup_source  = $this->format_lookup_source_label( $lookup_context['source'] );
		
		$poster_url     = get_post_meta( $post_id, self::META_POSTER, true );
		$backdrop_url   = get_post_meta( $post_id, self::META_BACKDROP, true );

		$validate_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'lunara_imdb_guard_validate',
					'post_id' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'lunara_imdb_guard_validate_' . $post_id
		);

		$apply_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'lunara_imdb_guard_apply',
					'post_id' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'lunara_imdb_guard_apply_' . $post_id
		);
		?>
		<style>
			.lunara-imdb-guard-meta p { margin: 0 0 10px; }
			.lunara-imdb-guard-meta label { display:block; font-weight:600; margin-bottom:4px; }
			.lunara-imdb-guard-meta code { word-break: break-word; }
			.lunara-imdb-guard-status { display:inline-block; padding:4px 8px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
			.lunara-imdb-guard-status.is-verified,
			.lunara-imdb-guard-status.is-autofilled { background:#e8f7ee; color:#17653a; }
			.lunara-imdb-guard-status.is-missing { background:#edf1f5; color:#34495e; }
			.lunara-imdb-guard-status.is-mismatch { background:#fff0d8; color:#8a4d00; }
			.lunara-imdb-guard-status.is-no_match,
			.lunara-imdb-guard-status.is-error { background:#fce8e6; color:#8a1f17; }
			.lunara-imdb-guard-status.is-incomplete { background:#edf1f5; color:#34495e; }
			.lunara-imdb-guard-actions { display:grid; gap:8px; margin-top:12px; }
			.lunara-imdb-guard-actions .button { width:100%; text-align:center; }
			.lunara-imdb-guard-help { font-size:12px; color:#5b6670; line-height:1.5; }
			.lunara-imdb-guard-meta input[type="text"] { width:100%; }
			.lunara-imdb-guard-media-preview { margin-top: 10px; font-size: 11px; color: #646970; }
		</style>
		<div class="lunara-imdb-guard-meta">
			<?php wp_nonce_field( 'lunara_imdb_guard_meta_box', 'lunara_imdb_guard_nonce' ); ?>
			<p><strong><?php esc_html_e( 'Review Title', 'lunara-imdb-guard' ); ?></strong><br><?php echo esc_html( get_the_title( $post_id ) ); ?></p>
			<p><strong><?php esc_html_e( 'Review Year', 'lunara-imdb-guard' ); ?></strong><br><?php echo '' !== $year ? esc_html( $year ) : '<em>' . esc_html__( 'Missing year meta', 'lunara-imdb-guard' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<p><strong><?php esc_html_e( 'Current IMDb ID', 'lunara-imdb-guard' ); ?></strong><br><?php echo '' !== $current_id ? '<code>' . esc_html( $current_id ) . '</code>' : '<em>' . esc_html__( 'Missing', 'lunara-imdb-guard' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<p><strong><?php esc_html_e( 'Lookup Title Used', 'lunara-imdb-guard' ); ?></strong><br><?php echo '' !== $lookup_context['title'] ? esc_html( $lookup_context['title'] ) : '<em>' . esc_html__( 'Unavailable', 'lunara-imdb-guard' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><br><span class="lunara-imdb-guard-help"><?php echo esc_html( $lookup_source ); ?></span></p>
			<p>
				<label for="lunara_imdb_guard_lookup_title"><?php esc_html_e( 'Lookup Title Override', 'lunara-imdb-guard' ); ?></label>
				<input type="text" id="lunara_imdb_guard_lookup_title" name="lunara_imdb_guard_lookup_title" value="<?php echo esc_attr( get_post_meta( $post_id, self::META_LOOKUP, true ) ); ?>" placeholder="<?php esc_attr_e( 'Optional manual title for OMDb validation', 'lunara-imdb-guard' ); ?>">
				<span class="lunara-imdb-guard-help"><?php esc_html_e( 'Use this only when the review post title differs from the actual film title you want matched.', 'lunara-imdb-guard' ); ?></span>
			</p>
			<p>
				<strong><?php esc_html_e( 'Status', 'lunara-imdb-guard' ); ?></strong><br>
				<span class="lunara-imdb-guard-status is-<?php echo esc_attr( $state['status'] ); ?>">
					<?php echo esc_html( $this->format_status_label( $state['status'] ) ); ?>
				</span>
			</p>
			<?php if ( '' !== $state['message'] ) : ?>
				<p class="lunara-imdb-guard-help"><?php echo esc_html( $state['message'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $state['expected_id'] ) : ?>
				<p><strong><?php esc_html_e( 'Suggested IMDb ID', 'lunara-imdb-guard' ); ?></strong><br><code><?php echo esc_html( $state['expected_id'] ); ?></code></p>
			<?php endif; ?>
			<?php if ( '' !== $state['expected_title'] ) : ?>
				<p><strong><?php esc_html_e( 'Matched OMDb Title', 'lunara-imdb-guard' ); ?></strong><br><?php echo esc_html( $state['expected_title'] ); ?><?php echo '' !== $state['expected_year'] ? ' (' . esc_html( $state['expected_year'] ) . ')' : ''; ?></p>
			<?php endif; ?>
			
			<?php if ( '' !== $poster_url || '' !== $backdrop_url ) : ?>
				<div class="lunara-imdb-guard-media-preview">
					<strong><?php esc_html_e( 'TMDB Assets Synced:', 'lunara-imdb-guard' ); ?></strong><br>
					<?php if ( '' !== $poster_url ) : ?>
						&#10003; <a href="<?php echo esc_url( $poster_url ); ?>" target="_blank"><?php esc_html_e( 'Poster Image', 'lunara-imdb-guard' ); ?></a><br>
					<?php endif; ?>
					<?php if ( '' !== $backdrop_url ) : ?>
						&#10003; <a href="<?php echo esc_url( $backdrop_url ); ?>" target="_blank"><?php esc_html_e( 'Backdrop Image', 'lunara-imdb-guard' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<p style="margin-top:10px;"><strong><?php esc_html_e( 'Theme Map Sync', 'lunara-imdb-guard' ); ?></strong><br><?php echo esc_html( $map_state ); ?></p>

			<div class="lunara-imdb-guard-actions">
				<a class="button" href="<?php echo esc_url( $validate_url ); ?>"><?php esc_html_e( 'Validate IMDb ID', 'lunara-imdb-guard' ); ?></a>
				<?php if ( '' !== $state['expected_id'] && $state['expected_id'] !== $current_id ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $apply_url ); ?>"><?php esc_html_e( 'Apply Suggested IMDb ID', 'lunara-imdb-guard' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the admin audit page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'lunara-imdb-guard' ) );
		}

		$api_key       = $this->get_api_key();
		$tmdb_api_key  = trim( (string) get_option( self::OPTION_TMDB_API_KEY, '' ) );
		$map_path      = $this->get_theme_map_path();
		$reviews       = get_posts(
			array(
				'post_type'              => 'review',
				'post_status'            => 'publish',
				'posts_per_page'         => 150,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$bulk_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'lunara_imdb_guard_bulk_audit',
				),
				admin_url( 'admin-post.php' )
			),
			'lunara_imdb_guard_bulk_audit'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Lunara IMDb Guard', 'lunara-imdb-guard' ); ?></h1>
			<p><?php esc_html_e( 'This plugin validates review title/year data against OMDb, auto-fills clear missing IMDb IDs, syncs high-resolution assets from TMDB, and keeps an editorial audit trail inside WordPress.', 'lunara-imdb-guard' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:18px 0 28px;">
				<input type="hidden" name="action" value="lunara_imdb_guard_save_settings">
				<?php wp_nonce_field( 'lunara_imdb_guard_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lunara_imdb_guard_omdb_api_key"><?php esc_html_e( 'OMDb API Key', 'lunara-imdb-guard' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="lunara_imdb_guard_omdb_api_key" name="lunara_imdb_guard_omdb_api_key" value="<?php echo esc_attr( $api_key ); ?>">
							<p class="description"><?php esc_html_e( 'Used for title/year to IMDb ID validation. This plugin currently defaults to the same OMDb key used by your desktop lookup tool unless you override it here.', 'lunara-imdb-guard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lunara_imdb_guard_tmdb_api_key"><?php esc_html_e( 'TMDB API Key', 'lunara-imdb-guard' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="lunara_imdb_guard_tmdb_api_key" name="lunara_imdb_guard_tmdb_api_key" value="<?php echo esc_attr( $tmdb_api_key ); ?>">
							<p class="description"><?php esc_html_e( 'Used to sync poster and backdrop artwork from TMDB. Leave blank to reuse the Oscars Ledger TMDB key when that plugin is active.', 'lunara-imdb-guard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Theme Map File', 'lunara-imdb-guard' ); ?></th>
						<td>
							<code><?php echo esc_html( $map_path ? $map_path : __( 'Not found', 'lunara-imdb-guard' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'When writable, verified IDs also sync to the active theme imdb-title-map.json file.', 'lunara-imdb-guard' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'lunara-imdb-guard' ) ); ?>
			</form>

			<p><a class="button button-primary" href="<?php echo esc_url( $bulk_url ); ?>"><?php esc_html_e( 'Run Bulk Audit', 'lunara-imdb-guard' ); ?></a></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Review', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'Year', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'Current IMDb ID', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'Status', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'Suggested ID', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'TMDB Media', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'Last Checked', 'lunara-imdb-guard' ); ?></th>
						<th><?php esc_html_e( 'Action', 'lunara-imdb-guard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $reviews ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No published reviews found.', 'lunara-imdb-guard' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $reviews as $review_id ) : ?>
							<?php
							$state        = $this->get_stored_state( $review_id );
							$current_id   = $this->normalize_imdb_id( get_post_meta( $review_id, '_lunara_imdb_title_id', true ) );
							$year         = trim( (string) get_post_meta( $review_id, '_lunara_year', true ) );
							$has_poster   = '' !== get_post_meta( $review_id, self::META_POSTER, true );
							$has_backdrop = '' !== get_post_meta( $review_id, self::META_BACKDROP, true );
							
							$validate_id = wp_nonce_url(
								add_query_arg(
									array(
										'action'  => 'lunara_imdb_guard_validate',
										'post_id' => $review_id,
									),
									admin_url( 'admin-post.php' )
								),
								'lunara_imdb_guard_validate_' . $review_id
							);
							$apply_id = wp_nonce_url(
								add_query_arg(
									array(
										'action'  => 'lunara_imdb_guard_apply',
										'post_id' => $review_id,
									),
									admin_url( 'admin-post.php' )
								),
								'lunara_imdb_guard_apply_' . $review_id
							);
							?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $review_id ) ); ?>"><?php echo esc_html( get_the_title( $review_id ) ); ?></a></td>
								<td><?php echo esc_html( $year ); ?></td>
								<td><?php echo '' !== $current_id ? '<code>' . esc_html( $current_id ) . '</code>' : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td><?php echo esc_html( $this->format_status_label( $state['status'] ) ); ?></td>
								<td><?php echo '' !== $state['expected_id'] ? '<code>' . esc_html( $state['expected_id'] ) . '</code>' : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td>
									<?php 
									if ( $has_poster || $has_backdrop ) {
										echo '<span style="color:#17653a;">&#10003; ' . esc_html__( 'Synced', 'lunara-imdb-guard' ) . '</span>';
									} else {
										echo '<span style="color:#646970;">&mdash;</span>';
									}
									?>
								</td>
								<td><?php echo '' !== $state['checked_at'] ? esc_html( $state['checked_at'] ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td>
									<a class="button" href="<?php echo esc_url( $validate_id ); ?>"><?php esc_html_e( 'Validate', 'lunara-imdb-guard' ); ?></a>
									<?php if ( '' !== $state['expected_id'] && $state['expected_id'] !== $current_id ) : ?>
										<a class="button button-primary" href="<?php echo esc_url( $apply_id ); ?>" style="margin-top:6px;"><?php esc_html_e( 'Apply Match', 'lunara-imdb-guard' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle one-off validation.
	 *
	 * @return void
	 */
	public function handle_validate_request() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		check_admin_referer( 'lunara_imdb_guard_validate_' . $post_id );

		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to validate this review.', 'lunara-imdb-guard' ) );
		}

		$this->validate_review( $post_id, false );

		$this->redirect_back(
			$post_id,
			array(
				'lunara_imdb_guard_notice' => 'validated',
			)
		);
	}

	/**
	 * Apply the suggested IMDb id.
	 *
	 * @return void
	 */
	public function handle_apply_request() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		check_admin_referer( 'lunara_imdb_guard_apply_' . $post_id );

		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this review.', 'lunara-imdb-guard' ) );
		}

		$state = $this->get_stored_state( $post_id );
		if ( '' !== $state['expected_id'] ) {
			update_post_meta( $post_id, '_lunara_imdb_title_id', $state['expected_id'] );
			$this->sync_theme_map_entry(
				get_the_title( $post_id ),
				get_post_meta( $post_id, '_lunara_year', true ),
				$state['expected_id']
			);
			
			// Dynamic asset lookup following manual alignment application
			$this->sync_lunara_tmdb_images( $post_id, $state['expected_id'] );
		}

		$this->validate_review( $post_id, false );

		$this->redirect_back(
			$post_id,
			array(
				'lunara_imdb_guard_notice' => 'applied',
			)
		);
	}

	/**
	 * Run the audit across published reviews.
	 *
	 * @return void
	 */
	public function handle_bulk_audit_request() {
		check_admin_referer( 'lunara_imdb_guard_bulk_audit' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the audit.', 'lunara-imdb-guard' ) );
		}

		$review_ids = get_posts(
			array(
				'post_type'              => 'review',
				'post_status'            => 'publish',
				'posts_per_page'         => 150,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$count = 0;
		foreach ( $review_ids as $review_id ) {
			$this->validate_review( $review_id, true );
			$count++;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'                => 'review',
					'page'                     => 'lunara-imdb-guard',
					'lunara_imdb_guard_notice' => 'bulk',
					'audited'                  => $count,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Persist the API key.
	 *
	 * @return void
	 */
	public function handle_save_settings_request() {
		check_admin_referer( 'lunara_imdb_guard_save_settings' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'lunara-imdb-guard' ) );
		}

		$api_key = isset( $_POST['lunara_imdb_guard_omdb_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['lunara_imdb_guard_omdb_api_key'] ) ) : '';
		update_option( self::OPTION_API_KEY, $api_key );

		$tmdb_api_key = isset( $_POST['lunara_imdb_guard_tmdb_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['lunara_imdb_guard_tmdb_api_key'] ) ) : '';
		update_option( self::OPTION_TMDB_API_KEY, $tmdb_api_key );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'                => 'review',
					'page'                     => 'lunara-imdb-guard',
					'lunara_imdb_guard_notice' => 'settings_saved',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Render contextual admin notices after guard actions.
	 *
	 * @return void
	 */
	public function render_admin_notices() {
		if ( ! is_admin() ) {
			return;
		}

		$notice = isset( $_GET['lunara_imdb_guard_notice'] ) ? sanitize_key( wp_unslash( $_GET['lunara_imdb_guard_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'review' !== $screen->post_type ) {
			return;
		}

		$message = '';
		$class   = 'notice notice-success is-dismissible';

		switch ( $notice ) {
			case 'validated':
				$message = __( 'IMDb validation completed for this review.', 'lunara-imdb-guard' );
				break;

			case 'applied':
				$message = __( 'Suggested IMDb ID applied and the review was revalidated.', 'lunara-imdb-guard' );
				break;

			case 'bulk':
				$audited = isset( $_GET['audited'] ) ? absint( $_GET['audited'] ) : 0;
				$message = sprintf(
					/* translators: %d: number of reviews audited */
					_n( 'Bulk audit finished. %d review was checked.', 'Bulk audit finished. %d reviews were checked.', $audited, 'lunara-imdb-guard' ),
					$audited
				);
				break;

			case 'settings_saved':
				$message = __( 'IMDb Guard settings saved.', 'lunara-imdb-guard' );
				break;
		}

		if ( '' === $message ) {
			return;
		}
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Validate one review and optionally auto-fill a missing id.
	 *
	 * @param int  $post_id           Review post id.
	 * @param bool $auto_fill_missing Whether to fill missing ids automatically.
	 * @return array
	 */
	private function validate_review( $post_id, $auto_fill_missing = true ) {
		$post_id        = absint( $post_id );
		$lookup_context = $this->resolve_lookup_context( $post_id );
		$title          = $lookup_context['title'];
		$year           = trim( (string) get_post_meta( $post_id, '_lunara_year', true ) );
		$current_id     = $this->normalize_imdb_id( get_post_meta( $post_id, '_lunara_imdb_title_id', true ) );

		if ( '' === $year && '' !== $lookup_context['year'] ) {
			$year = $lookup_context['year'];
		}

		$result = array(
			'status'         => 'incomplete',
			'message'        => __( 'Review needs both a lookup title and release year before IMDb validation can run.', 'lunara-imdb-guard' ),
			'expected_id'    => '',
			'expected_title' => '',
			'expected_year'  => '',
			'checked_at'     => current_time( 'mysql' ),
		);

		if ( '' === $title || '' === $year ) {
			$this->store_state( $post_id, $result );
			return $result;
		}

		$lookup = $this->lookup_omdb( $title, $year );
		if ( 'error' === $lookup['status'] ) {
			$result['status']  = 'error';
			$result['message'] = $lookup['message'];
			$this->store_state( $post_id, $result );
			return $result;
		}

		if ( 'not_found' === $lookup['status'] ) {
			$result['status']  = 'no_match';
			$result['message'] = sprintf(
				/* translators: 1: lookup title, 2: year */
				__( 'OMDb did not find a confident movie match for "%1$s" (%2$s).', 'lunara-imdb-guard' ),
				$title,
				$year
			);
			$this->store_state( $post_id, $result );
			return $result;
		}

		$expected_id    = $lookup['imdb_id'];
		$expected_title = $lookup['title'];
		$expected_year  = $lookup['year'];

		$result['expected_id']    = $expected_id;
		$result['expected_title'] = $expected_title;
		$result['expected_year']  = $expected_year;

		if ( '' === $current_id && $auto_fill_missing && '' !== $expected_id ) {
			update_post_meta( $post_id, '_lunara_imdb_title_id', $expected_id );
			$current_id         = $expected_id;
			$result['status']   = 'autofilled';
			$result['message']  = sprintf(
				/* translators: 1: lookup title, 2: year */
				__( 'Missing IMDb ID was auto-filled from OMDb because "%1$s" (%2$s) matched cleanly.', 'lunara-imdb-guard' ),
				$title,
				$year
			);
			$this->sync_theme_map_entry( $title, $expected_year, $expected_id );
			
			// Process TMDB assets matching our newly autofilled ID
			$this->sync_lunara_tmdb_images( $post_id, $expected_id );
			
			$this->store_state( $post_id, $result );
			return $result;
		}

		if ( '' === $current_id ) {
			$result['status']  = 'missing';
			$result['message'] = __( 'Review is missing an IMDb ID, but a suggested match is available below.', 'lunara-imdb-guard' );
			$this->store_state( $post_id, $result );
			return $result;
		}

		if ( $current_id === $expected_id ) {
			$result['status']  = 'verified';
			$result['message'] = sprintf(
				/* translators: 1: lookup title, 2: year */
				__( 'Current IMDb ID matches the OMDb result for "%1$s" (%2$s).', 'lunara-imdb-guard' ),
				$title,
				$year
			);
			$this->sync_theme_map_entry( $title, $expected_year, $expected_id );
			
			// Keep images updated on active verification passes
			$this->sync_lunara_tmdb_images( $post_id, $current_id );
			
			$this->store_state( $post_id, $result );
			return $result;
		}

		$result['status']  = 'mismatch';
		$result['message'] = sprintf(
			/* translators: 1: current id, 2: suggested id */
			__( 'Current IMDb ID %1$s does not match the OMDb suggestion %2$s.', 'lunara-imdb-guard' ),
			$current_id,
			$expected_id
		);
		$this->store_state( $post_id, $result );

		return $result;
	}

	/**
	 * Query OMDb using title and year.
	 *
	 * @param string $title Review title.
	 * @param string $year  Review year.
	 * @return array
	 */
	private function lookup_omdb( $title, $year ) {
		$title = trim( (string) $title );
		$year  = trim( (string) $year );

		$cache_key = 'lunara_imdb_guard_v2_' . md5( strtolower( $title . '|' . $year ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return array(
				'status'  => 'error',
				'message' => __( 'No OMDb API key is configured for Lunara IMDb Guard.', 'lunara-imdb-guard' ),
			);
		}

		$exact_payload = $this->request_omdb(
			array(
				'apikey' => $api_key,
				't'      => $title,
				'y'      => $year,
				'type'   => 'movie',
			)
		);

		if ( is_wp_error( $exact_payload ) ) {
			return array(
				'status'  => 'error',
				'message' => $exact_payload->get_error_message(),
			);
		}

		$result = $this->build_exact_match_result( $exact_payload, $title, $year );
		if ( is_array( $result ) ) {
			set_transient( $cache_key, $result, 7 * DAY_IN_SECONDS );
			return $result;
		}

		$search_payload = $this->request_omdb(
			array(
				'apikey' => $api_key,
				's'      => $title,
				'y'      => $year,
				'type'   => 'movie',
			)
		);

		if ( is_wp_error( $search_payload ) ) {
			return array(
				'status'  => 'error',
				'message' => $search_payload->get_error_message(),
			);
		}

		$result = $this->build_search_match_result( $search_payload, $title, $year );
		if ( is_array( $result ) ) {
			set_transient( $cache_key, $result, 7 * DAY_IN_SECONDS );
			return $result;
		}

		$result = array(
			'status'  => 'not_found',
			'message' => __( 'OMDb returned no confident match.', 'lunara-imdb-guard' ),
		);

		set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Query TMDB to retrieve uncompressed poster and backdrop layouts using an IMDb ID.
	 *
	 * @param string $imdb_id The 'tt' string constant.
	 * @return array|false
	 */
	private function fetch_tmdb_images( $imdb_id ) {
		$api_key = $this->get_tmdb_api_key();
		if ( '' === $api_key ) {
			return false;
		}

		// Step 1: Resolve the internal numeric TMDB ID via External ID endpoint
		$find_url = "https://api.themoviedb.org/3/find/{$imdb_id}?api_key={$api_key}&external_source=imdb_id";
		$find_response = wp_remote_get( $find_url, array( 'timeout' => 10 ) );
		
		if ( is_wp_error( $find_response ) ) {
			return false;
		}
		
		$find_data = json_decode( wp_remote_retrieve_body( $find_response ), true );
		if ( empty( $find_data['movie_results'] ) || ! is_array( $find_data['movie_results'] ) ) {
			return false;
		}
		
		$tmdb_id = $find_data['movie_results'][0]['id'];
		
		// Step 2: Request image collection array
		$images_url = "https://api.themoviedb.org/3/movie/{$tmdb_id}/images?api_key={$api_key}";
		$images_response = wp_remote_get( $images_url, array( 'timeout' => 10 ) );
		
		if ( is_wp_error( $images_response ) ) {
			return false;
		}
		
		$images_data = json_decode( wp_remote_retrieve_body( $images_response ), true );
		
		// Original resolution path config
		$base_image_url = 'https://image.tmdb.org/t/p/original';
		$output = array(
			'poster'   => '',
			'backdrop' => '',
		);
		
		if ( ! empty( $images_data['posters'] ) && is_array( $images_data['posters'] ) ) {
			$output['poster'] = $base_image_url . $images_data['posters'][0]['file_path'];
		}
		
		if ( ! empty( $images_data['backdrops'] ) && is_array( $images_data['backdrops'] ) ) {
			$output['backdrop'] = $base_image_url . $images_data['backdrops'][0]['file_path'];
		}
		
		return $output;
	}

	/**
	 * Orchestrate image population and metadata storage for a specific post.
	 *
	 * @param int    $post_id Post context identifier.
	 * @param string $imdb_id The 'tt' string constant.
	 * @return void
	 */
	private function sync_lunara_tmdb_images( $post_id, $imdb_id ) {
		$images = $this->fetch_tmdb_images( $imdb_id );
		
		if ( is_array( $images ) ) {
			if ( ! empty( $images['poster'] ) ) {
				update_post_meta( $post_id, self::META_POSTER, esc_url_raw( $images['poster'] ) );
			} else {
				delete_post_meta( $post_id, self::META_POSTER );
			}
			
			if ( ! empty( $images['backdrop'] ) ) {
				update_post_meta( $post_id, self::META_BACKDROP, esc_url_raw( $images['backdrop'] ) );
			} else {
				delete_post_meta( $post_id, self::META_BACKDROP );
			}
		}
	}

	/**
	 * Persist an optional manual lookup-title override.
	 *
	 * @param int $post_id Review post ID.
	 * @return void
	 */
	private function save_lookup_override( $post_id ) {
		if ( ! isset( $_POST['lunara_imdb_guard_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lunara_imdb_guard_nonce'] ) ), 'lunara_imdb_guard_meta_box' ) ) {
			return;
		}

		$value = isset( $_POST['lunara_imdb_guard_lookup_title'] ) ? sanitize_text_field( wp_unslash( $_POST['lunara_imdb_guard_lookup_title'] ) ) : '';
		$value = $this->clean_lookup_title( $value );

		if ( '' === $value ) {
			delete_post_meta( $post_id, self::META_LOOKUP );
			return;
		}

		update_post_meta( $post_id, self::META_LOOKUP, $value );
	}

	/**
	 * Resolve the lookup title/year context used for validation.
	 *
	 * @param int $post_id Review post ID.
	 * @return array
	 */
	private function resolve_lookup_context( $post_id ) {
		$override = $this->clean_lookup_title( get_post_meta( $post_id, self::META_LOOKUP, true ) );
		if ( '' !== $override ) {
			return array(
				'title'  => $override,
				'year'   => '',
				'source' => 'override',
			);
		}

		$header = $this->extract_review_header_context( $post_id );
		if ( '' !== $header['title'] ) {
			return array(
				'title'  => $header['title'],
				'year'   => $header['year'],
				'source' => 'review_header',
			);
		}

		$post_title = trim( wp_strip_all_tags( get_the_title( $post_id ) ) );
		$stripped   = preg_replace( '/\s*\((?:19|20)\d{2}\)\s*$/', '', $post_title );
		$stripped   = $this->clean_lookup_title( $stripped );

		return array(
			'title'  => '' !== $stripped ? $stripped : $this->clean_lookup_title( $post_title ),
			'year'   => '',
			'source' => $stripped !== $post_title ? 'post_title_trimmed' : 'post_title',
		);
	}

	/**
	 * Extract the canonical title/year comment from the review body when present.
	 *
	 * @param int $post_id Review post ID.
	 * @return array
	 */
	private function extract_review_header_context( $post_id ) {
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( '' === trim( $content ) ) {
			return array(
				'title' => '',
				'year'  => '',
			);
		}

		if ( preg_match( '//u', $content, $matches ) ) {
			return array(
				'title' => $this->clean_lookup_title( $matches[1] ),
				'year'  => trim( (string) $matches[2] ),
			);
		}

		return array(
			'title' => '',
			'year'  => '',
		);
	}

	/**
	 * Normalize a candidate lookup title for OMDb matching.
	 *
	 * @param mixed $title Raw title value.
	 * @return string
	 */
	private function clean_lookup_title( $title ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );
		$title = preg_replace( '/\s+/', ' ', $title );

		return trim( (string) $title, " \t\n\r\0\x0B\"'“”" );
	}

	/**
	 * Render a human-friendly lookup-source label.
	 *
	 * @param string $source Internal lookup source.
	 * @return string
	 */
	private function format_lookup_source_label( $source ) {
		$labels = array(
			'override'           => __( 'Manual override', 'lunara-imdb-guard' ),
			'review_header'      => __( 'Review HTML header comment', 'lunara-imdb-guard' ),
			'post_title_trimmed' => __( 'Post title with the trailing year removed', 'lunara-imdb-guard' ),
			'post_title'         => __( 'Post title', 'lunara-imdb-guard' ),
		);

		return isset( $labels[ $source ] ) ? $labels[ $source ] : __( 'Unknown source', 'lunara-imdb-guard' );
	}

	/**
	 * Request OMDb and return the decoded payload or a WP_Error.
	 *
	 * @param array $query_args OMDb query args.
	 * @return array|WP_Error
	 */
	private function request_omdb( $query_args ) {
		$response = wp_remote_get(
			add_query_arg( $query_args, 'https://www.omdbapi.com/' ),
			array(
				'timeout' => 12,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'lunara_imdb_guard_invalid_payload', __( 'OMDb returned an unreadable response.', 'lunara-imdb-guard' ) );
		}

		return $payload;
	}

	/**
	 * Build a confident exact-title OMDb match result.
	 *
	 * @param array  $payload      OMDb payload.
	 * @param string $lookup_title Title being validated.
	 * @param string $lookup_year  Year being validated.
	 * @return array|null
	 */
	private function build_exact_match_result( $payload, $lookup_title, $lookup_year ) {
		if ( empty( $payload['Response'] ) || 'True' !== $payload['Response'] ) {
			return null;
		}

		$result_title = trim( (string) ( $payload['Title'] ?? '' ) );
		$result_year  = trim( substr( (string) ( $payload['Year'] ?? '' ), 0, 4 ) );
		$result_id    = $this->normalize_imdb_id( $payload['imdbID'] ?? '' );

		if ( '' === $result_title || '' === $result_year || '' === $result_id ) {
			return null;
		}

		if ( $result_year !== trim( (string) $lookup_year ) ) {
			return null;
		}

		if ( $this->normalize_title_key( $result_title ) !== $this->normalize_title_key( $lookup_title ) ) {
			return null;
		}

		return array(
			'status'  => 'ok',
			'title'   => $result_title,
			'year'    => $result_year,
			'imdb_id' => $result_id,
		);
	}

	/**
	 * Build a confident search-result OMDb match result.
	 *
	 * @param array  $payload      OMDb search payload.
	 * @param string $lookup_title Title being validated.
	 * @param string $lookup_year  Year being validated.
	 * @return array|null
	 */
	private function build_search_match_result( $payload, $lookup_title, $lookup_year ) {
		if ( empty( $payload['Response'] ) || 'True' !== $payload['Response'] || empty( $payload['Search'] ) || ! is_array( $payload['Search'] ) ) {
			return null;
		}

		$normalized_lookup = $this->normalize_title_key( $lookup_title );
		$lookup_year       = trim( (string) $lookup_year );

		foreach ( $payload['Search'] as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$candidate_title = trim( (string) ( $candidate['Title'] ?? '' ) );
			$candidate_year  = trim( substr( (string) ( $candidate['Year'] ?? '' ), 0, 4 ) );
			$candidate_type  = sanitize_key( (string) ( $candidate['Type'] ?? '' ) );
			$candidate_id    = $this->normalize_imdb_id( $candidate['imdbID'] ?? '' );

			if ( 'movie' !== $candidate_type || '' === $candidate_title || '' === $candidate_year || '' === $candidate_id ) {
				continue;
			}

			if ( $candidate_year !== $lookup_year ) {
				continue;
			}

			if ( $this->normalize_title_key( $candidate_title ) !== $normalized_lookup ) {
				continue;
			}

			return array(
				'status'  => 'ok',
				'title'   => $candidate_title,
				'year'    => $candidate_year,
				'imdb_id' => $candidate_id,
			);
		}

		return null;
	}

	/**
	 * Return the configured OMDb API key.
	 *
	 * @return string
	 */
	private function get_api_key() {
		$option = trim( (string) get_option( self::OPTION_API_KEY, '' ) );
		if ( '' !== $option ) {
			return $option;
		}

		if ( defined( 'LUNARA_IMDB_GUARD_OMDB_API_KEY' ) ) {
			return trim( (string) constant( 'LUNARA_IMDB_GUARD_OMDB_API_KEY' ) );
		}

		return LUNARA_IMDB_GUARD_DEFAULT_OMDB_API_KEY;
	}

	/**
	 * Return the configured TMDB API key.
	 *
	 * Resolves, in order, from this plugin's own option, the Oscars Ledger
	 * AAT_TMDB_API_KEY constant (so a single configured key serves both plugins),
	 * a wp-config LUNARA_IMDB_GUARD_TMDB_API_KEY constant, or empty. The key is
	 * never hardcoded so it stays out of source control and can be rotated
	 * without a code change; poster/backdrop sync is skipped when it is empty.
	 *
	 * @return string
	 */
	private function get_tmdb_api_key() {
		$option = trim( (string) get_option( self::OPTION_TMDB_API_KEY, '' ) );
		if ( '' !== $option ) {
			return $option;
		}

		if ( defined( 'AAT_TMDB_API_KEY' ) && '' !== (string) constant( 'AAT_TMDB_API_KEY' ) ) {
			return trim( (string) constant( 'AAT_TMDB_API_KEY' ) );
		}

		if ( defined( 'LUNARA_IMDB_GUARD_TMDB_API_KEY' ) ) {
			return trim( (string) constant( 'LUNARA_IMDB_GUARD_TMDB_API_KEY' ) );
		}

		return '';
	}

	/**
	 * Normalize any IMDb string into a tt id when possible.
	 *
	 * @param mixed $raw Raw IMDb value.
	 * @return string
	 */
	private function normalize_imdb_id( $raw ) {
		$raw = trim( (string) $raw );

		if ( preg_match( '/\btt\d{7,10}\b/i', $raw, $matches ) ) {
			return strtolower( $matches[0] );
		}

		if ( preg_match( '#imdb\.com/title/(tt\d{7,10})#i', $raw, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return '';
	}

	/**
	 * Fetch stored audit state.
	 *
	 * @param int $post_id Review post id.
	 * @return array
	 */
	private function get_stored_state( $post_id ) {
		$status = trim( (string) get_post_meta( $post_id, self::META_STATUS, true ) );
		if ( '' === $status ) {
			$status = 'unchecked';
		}

		return array(
			'status'         => $status,
			'message'        => trim( (string) get_post_meta( $post_id, self::META_MESSAGE, true ) ),
			'expected_id'    => trim( (string) get_post_meta( $post_id, self::META_EXPECTED, true ) ),
			'expected_title' => trim( (string) get_post_meta( $post_id, self::META_TITLE, true ) ),
			'expected_year'  => trim( (string) get_post_meta( $post_id, self::META_YEAR, true ) ),
			'checked_at'     => trim( (string) get_post_meta( $post_id, self::META_CHECKED, true ) ),
		);
	}

	/**
	 * Persist audit state.
	 *
	 * @param int   $post_id Review post id.
	 * @param array $result  Validation result.
	 * @return void
	 */
	private function store_state( $post_id, $result ) {
		update_post_meta( $post_id, self::META_STATUS, sanitize_text_field( $result['status'] ) );
		update_post_meta( $post_id, self::META_MESSAGE, sanitize_text_field( $result['message'] ) );
		update_post_meta( $post_id, self::META_EXPECTED, sanitize_text_field( $result['expected_id'] ) );
		update_post_meta( $post_id, self::META_TITLE, sanitize_text_field( $result['expected_title'] ) );
		update_post_meta( $post_id, self::META_YEAR, sanitize_text_field( $result['expected_year'] ) );
		update_post_meta( $post_id, self::META_CHECKED, sanitize_text_field( $result['checked_at'] ) );
	}

	/**
	 * Format human status labels.
	 *
	 * @param string $status Internal status code.
	 * @return string
	 */
	private function format_status_label( $status ) {
		$labels = array(
			'unchecked'  => __( 'Unchecked', 'lunara-imdb-guard' ),
			'incomplete' => __( 'Incomplete', 'lunara-imdb-guard' ),
			'missing'    => __( 'Missing ID', 'lunara-imdb-guard' ),
			'autofilled' => __( 'Auto-Filled', 'lunara-imdb-guard' ),
			'verified'   => __( 'Verified', 'lunara-imdb-guard' ),
			'mismatch'   => __( 'Mismatch', 'lunara-imdb-guard' ),
			'no_match'   => __( 'No Match', 'lunara-imdb-guard' ),
			'error'      => __( 'Error', 'lunara-imdb-guard' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );
	}

	/**
	 * Get the active theme's map path.
	 *
	 * @return string
	 */
	private function get_theme_map_path() {
		$path = trailingslashit( get_stylesheet_directory() ) . 'assets/data/imdb-title-map.json';
		return file_exists( $path ) || is_dir( dirname( $path ) ) ? $path : '';
	}

	/**
	 * Sync a verified title-year pair into the active theme's imdb-title-map.json.
	 *
	 * @param string $title   Review title.
	 * @param string $year    Review year.
	 * @param string $imdb_id IMDb title id.
	 * @return void
	 */
	private function sync_theme_map_entry( $title, $year, $imdb_id ) {
		$path = $this->get_theme_map_path();
		if ( '' === $path ) {
			return;
		}

		$key = $this->build_map_key( $title, $year );
		if ( '' === $key || '' === $imdb_id ) {
			return;
		}

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( file_exists( $path ) && ! is_writable( $path ) ) {
			return;
		}

		if ( ! file_exists( $path ) && ! is_writable( $dir ) ) {
			return;
		}

		$data = array();
		if ( file_exists( $path ) ) {
			$loaded = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $loaded ) ) {
				$data = $loaded;
			}
		}

		$data[ $key ] = $imdb_id;
		ksort( $data, SORT_NATURAL | SORT_FLAG_CASE );

		file_put_contents( $path, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	}

	/**
	 * Build the theme map key.
	 *
	 * @param string $title Review title.
	 * @param string $year  Review year.
	 * @return string
	 */
	private function build_map_key( $title, $year ) {
		$title = $this->normalize_title_key( $title );
		$year  = trim( (string) $year );

		if ( '' === $title || '' === $year ) {
			return '';
		}

		return $title . '|' . $year;
	}

	/**
	 * Match Lunara's title normalization.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	private function normalize_title_key( $title ) {
		$title = strtolower( remove_accents( (string) $title ) );
		$title = str_replace( '&', 'and', $title );
		$title = preg_replace( '/[^a-z0-9]+/', ' ', $title );
		return trim( preg_replace( '/\s+/', ' ', $title ) );
	}

	/**
	 * Redirect back to editor or audit page.
	 *
	 * @param int   $post_id Review post id.
	 * @param array $args    Query args.
	 * @return void
	 */
	private function redirect_back( $post_id, $args = array() ) {
		$referer = wp_get_referer();
		$target  = $referer ? $referer : get_edit_post_link( $post_id, 'raw' );

		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}
}

Lunara_IMDb_Guard::instance();
