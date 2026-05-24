<?php
/**
 * Admin UI.
 *
 * @package SimilarRouteTrip\Admin
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Admin;

use SimilarRouteTrip\AI\AIConfig;
use SimilarRouteTrip\AI\ContentProviderRegistry;
use SimilarRouteTrip\AI\ImageProviderRegistry;
use SimilarRouteTrip\AI\AIKeyVault;
use SimilarRouteTrip\AI\AIService;
use SimilarRouteTrip\Content\ContentGenerator;
use SimilarRouteTrip\Content\ContentLengthProfile;
use SimilarRouteTrip\Content\ContentRepair;
use SimilarRouteTrip\Content\PromptTemplateManager;
use SimilarRouteTrip\Content\TopicTemplateRegistry;
use SimilarRouteTrip\Database\RouteRepository;
use SimilarRouteTrip\Image\SRT_Image_Manager;
use SimilarRouteTrip\Image\SRT_Image_Source_Config;
use SimilarRouteTrip\Logging\LogRepository;
use SimilarRouteTrip\Queue\JobRepository;
use SimilarRouteTrip\Queue\QueueManager;
use SimilarRouteTrip\Queue\QueueRepository;
use SimilarRouteTrip\Queue\QueueWorkerConfig;
use SimilarRouteTrip\Queue\QueueRunner;
use SimilarRouteTrip\Queue\Worker;
use SimilarRouteTrip\Routes\RouteCreator;
use SimilarRouteTrip\Routes\RouteImporter;
use SimilarRouteTrip\SEO\PromptBuilder;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {

	private const NONCE = 'srt_admin_action';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_post_srt_import_theme', [ self::class, 'handle_import_theme' ] );
		add_action( 'admin_post_srt_import_tre', [ self::class, 'handle_import_tre' ] );
		add_action( 'admin_post_srt_sync_prices', [ self::class, 'handle_sync_prices' ] );
		add_action( 'admin_post_srt_save_ai_settings', [ self::class, 'handle_save_ai_settings' ] );
		add_action( 'admin_post_srt_save_image_sources', [ self::class, 'handle_save_image_sources' ] );
		add_action( 'admin_post_srt_test_ai', [ self::class, 'handle_test_ai' ] );
		add_action( 'admin_post_srt_test_ai_keys', [ self::class, 'handle_test_ai_keys' ] );
		add_action( 'admin_post_srt_test_ai_key', [ self::class, 'handle_test_ai_key' ] );
		add_action( 'admin_post_srt_delete_ai_key', [ self::class, 'handle_delete_ai_key' ] );
		add_action( 'admin_post_srt_bulk_create_routes', [ self::class, 'handle_bulk_create_routes' ] );
		add_action( 'admin_post_srt_create_post', [ self::class, 'handle_create_post' ] );
		add_action( 'admin_post_srt_unlink_post', [ self::class, 'handle_unlink_post' ] );
		add_action( 'admin_post_srt_save_prompt_templates', [ self::class, 'handle_save_prompt_templates' ] );
		add_action( 'admin_post_srt_reset_prompt_templates', [ self::class, 'handle_reset_prompt_templates' ] );
		add_action( 'admin_post_srt_run_queue', [ self::class, 'handle_run_queue' ] );
		add_action( 'admin_post_srt_retry_failed_queue', [ self::class, 'handle_retry_failed_queue' ] );
		add_action( 'admin_post_srt_retry_queue_item', [ self::class, 'handle_retry_queue_item' ] );
		add_action( 'admin_post_srt_clear_completed_queue', [ self::class, 'handle_clear_completed_queue' ] );
		add_action( 'admin_post_srt_repair_generated_posts', [ self::class, 'handle_repair_generated_posts' ] );
		add_action( 'wp_ajax_srt_ai_upsert_provider', [ self::class, 'ajax_upsert_provider' ] );
		add_action( 'wp_ajax_srt_ai_delete_provider', [ self::class, 'ajax_delete_provider' ] );
		add_action( 'wp_ajax_srt_ai_test_provider', [ self::class, 'ajax_test_provider' ] );
		add_action( 'wp_ajax_srt_ai_test_runtime', [ self::class, 'ajax_test_runtime' ] );
	}

	public static function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'similar-route-trip' ) && false === strpos( $hook, 'srt-' ) ) {
			return;
		}
		wp_enqueue_style( 'srt-admin', SRT_PLUGIN_URL . 'assets/admin/admin.css', [], SRT_VERSION );
		wp_enqueue_script( 'srt-admin', SRT_PLUGIN_URL . 'assets/admin/admin.js', [], SRT_VERSION, true );
		wp_localize_script(
			'srt-admin',
			'SRTAdmin',
			[
				'restUrl'         => esc_url_raw( rest_url( 'similar-route-trip/v1' ) ),
				'restNonce'       => wp_create_nonce( 'wp_rest' ),
				'previewEndpoint' => 'content/generate-preview',
				'ajaxUrl'         => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'adminNonce'      => wp_create_nonce( self::NONCE ),
			]
		);
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Tuyen Di Pho Bien', 'similar-route-trip' ),
			__( 'Tuyen Di Pho Bien', 'similar-route-trip' ),
			'manage_options',
			'similar-route-trip',
			[ self::class, 'render_routes' ],
			'dashicons-location',
			58
		);
		add_submenu_page( 'similar-route-trip', __( 'All Routes', 'similar-route-trip' ), __( 'All Routes', 'similar-route-trip' ), 'manage_options', 'similar-route-trip', [ self::class, 'render_routes' ] );
		add_submenu_page( 'similar-route-trip', __( 'Import / Sync', 'similar-route-trip' ), __( 'Import / Sync', 'similar-route-trip' ), 'manage_options', 'srt-import-sync', [ self::class, 'render_import_sync' ] );
		add_submenu_page( 'similar-route-trip', __( 'Route Generator', 'similar-route-trip' ), __( 'Route Generator', 'similar-route-trip' ), 'manage_options', 'srt-route-generator', [ self::class, 'render_route_generator' ] );
		add_submenu_page( 'similar-route-trip', __( 'Content Generator', 'similar-route-trip' ), __( 'Content Generator', 'similar-route-trip' ), 'manage_options', 'srt-content-generator', [ self::class, 'render_content_generator' ] );
		add_submenu_page( 'similar-route-trip', __( 'Prompt Templates', 'similar-route-trip' ), __( 'Prompt Templates', 'similar-route-trip' ), 'manage_options', 'srt-prompt-templates', [ self::class, 'render_prompt_templates' ] );
		add_submenu_page( 'similar-route-trip', __( 'AI Settings', 'similar-route-trip' ), __( 'AI Settings', 'similar-route-trip' ), 'manage_options', 'srt-ai-settings', [ self::class, 'render_ai_settings' ] );
		add_submenu_page( 'similar-route-trip', __( 'Image Sources', 'similar-route-trip' ), __( 'Image Sources', 'similar-route-trip' ), 'manage_options', 'srt-image-sources', [ self::class, 'render_image_sources' ] );
		add_submenu_page( 'similar-route-trip', __( 'Logs', 'similar-route-trip' ), __( 'Logs', 'similar-route-trip' ), 'manage_options', 'srt-logs', [ self::class, 'render_logs' ] );
		add_submenu_page( 'similar-route-trip', __( 'Queue / Workers', 'similar-route-trip' ), __( 'Queue / Workers', 'similar-route-trip' ), 'manage_options', 'srt-tools', [ self::class, 'render_tools' ] );
	}

	public static function render_routes(): void {
		self::guard();
		$routes = RouteRepository::all( [ 'active' => false, 'limit' => 200 ] );
		self::header( __( 'Tuyen Di Pho Bien (Similar Route Trip)', 'similar-route-trip' ), 'similar-route-trip' );
		?>
		<div class="srt-card">
		<p><strong>Version:</strong> <?php echo esc_html( SRT_VERSION ); ?> | <strong>DB:</strong> <?php echo esc_html( SRT_DB_VERSION ); ?></p>
			<table class="widefat striped">
				<thead><tr><th>Slug</th><th>Tuyen</th><th>Km</th><th>Phut</th><th>Gia tu</th><th>Post</th><th>Quality</th><th>Similarity</th><th>AI</th><th>Shortcode</th></tr></thead>
				<tbody>
				<?php if ( empty( $routes ) ) : ?>
					<tr><td colspan="10"><?php esc_html_e( 'Chua co route. Hay import truoc.', 'similar-route-trip' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $routes as $route ) : ?>
					<?php
					$post_id = (int) ( $route['post_id'] ?? 0 );
					$quality_score = $post_id > 0 ? (int) get_post_meta( $post_id, '_srt_quality_score', true ) : 0;
					$similarity_score = $post_id > 0 ? (float) get_post_meta( $post_id, '_srt_similarity_score', true ) : 0.0;
					?>
					<tr>
						<td><code><?php echo esc_html( (string) $route['slug'] ); ?></code></td>
						<td><?php echo esc_html( $route['from_city'] . ' - ' . $route['to_city'] ); ?></td>
						<td><?php echo esc_html( number_format( (float) $route['distance_km'], 1 ) ); ?></td>
						<td><?php echo esc_html( (string) $route['duration_min'] ); ?></td>
						<td><?php echo esc_html( (string) $route['price_display'] ); ?></td>
						<td><?php echo $post_id > 0 ? esc_html( (string) $route['post_status'] ) : esc_html__( 'No post', 'similar-route-trip' ); ?></td>
						<td><?php echo $post_id > 0 ? esc_html( (string) $quality_score ) : esc_html__( '-', 'similar-route-trip' ); ?></td>
						<td><?php echo $post_id > 0 ? esc_html( number_format( $similarity_score, 4 ) ) : esc_html__( '-', 'similar-route-trip' ); ?></td>
						<td><?php echo esc_html( (string) ( $route['ai_status'] ?: '-' ) ); ?></td>
						<td><code>[srt_route_card slug="<?php echo esc_attr( (string) $route['slug'] ); ?>"]</code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
		self::footer();
	}

	public static function render_import_sync(): void {
		self::guard();
		self::header( __( 'Import / Sync', 'similar-route-trip' ), 'srt-import-sync' );
		?>
		<div class="srt-card">
		<p><?php esc_html_e( 'Plugin moi chay song song, chi doc du lieu cu va ghi vao bang wp_srt_routes rieng.', 'similar-route-trip' ); ?></p>
		<?php self::button_form( 'srt_import_theme', __( 'Import tu Theme Options', 'similar-route-trip' ), 'primary' ); ?>
		<?php self::button_form( 'srt_import_tre', __( 'Import tu Taxi Route Engine cu', 'similar-route-trip' ) ); ?>
		<?php self::button_form( 'srt_sync_prices', __( 'Sync gia tu Distance Calculator', 'similar-route-trip' ) ); ?>
		<?php
		$routes = RouteRepository::all( [ 'active' => false, 'limit' => 1 ] );
		if ( ! empty( $routes ) ) :
			?>
			<h2><?php esc_html_e( 'Prompt mau viet bai taxi', 'similar-route-trip' ); ?></h2>
			<textarea class="large-text code" rows="12" readonly><?php echo esc_textarea( PromptBuilder::route_article_prompt( $routes[0] ) ); ?></textarea>
		<?php endif; ?>
		</div>
		<?php
		self::footer();
	}

	public static function render_ai_settings(): void {
		self::guard();
		$settings          = AIConfig::get();
		$keys              = AIConfig::keys( false, false );
		$provider_summaries = self::ai_provider_summaries();
		self::header( __( 'AI Settings', 'similar-route-trip' ), 'srt-ai-settings' );
		?>
		<div id="srt-ai-settings-page" class="srt-ai-settings-page" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
		<div class="srt-card">
		<h2>Runtime Settings</h2>
		<form id="srt-runtime-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="srt_save_ai_settings">
			<input type="hidden" name="selected_content_model" value="<?php echo esc_attr( (string) $settings['selected_content_model'] ); ?>">
			<input type="hidden" name="selected_image_model" value="<?php echo esc_attr( (string) $settings['selected_image_model'] ); ?>">
			<input type="hidden" name="provider" value="<?php echo esc_attr( (string) $settings['provider'] ); ?>">
			<input type="hidden" name="base_url" value="<?php echo esc_attr( (string) $settings['base_url'] ); ?>">
			<input type="hidden" name="model_content" value="<?php echo esc_attr( (string) $settings['model_content'] ); ?>">
			<input type="hidden" name="model_image" value="<?php echo esc_attr( (string) $settings['model_image'] ); ?>">
			<table class="form-table" role="presentation">
				<tr><th>AI Provider Mode</th><td><select name="mode"><option value="disabled" <?php selected( $settings['mode'], 'disabled' ); ?>>Disabled</option><option value="own" <?php selected( $settings['mode'], 'own' ); ?>>Own Config</option><option value="ai_commerce_agent" <?php selected( $settings['mode'], 'ai_commerce_agent' ); ?>>Use AI Commerce Agent Config</option></select><p class="description">Own Config luu rieng trong plugin nay. AI Commerce Agent mode chi doc runtime tu bang wp_ai_provider_settings, khong copy API key.</p></td></tr>
				<tr>
					<th>Active Provider</th>
					<td>
						<select name="active_key_id">
							<option value="">Auto route by priority/weight</option>
							<?php foreach ( $keys as $key ) : ?>
								<option value="<?php echo esc_attr( (string) ( $key['id'] ?? '' ) ); ?>" <?php selected( (string) ( $settings['active_key_id'] ?? '' ), (string) ( $key['id'] ?? '' ) ); ?>>
									<?php echo esc_html( (string) ( $key['label'] ?? $key['id'] ?? '' ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr><th>Temperature</th><td><input type="number" step="0.1" min="0" max="2" name="temperature" value="<?php echo esc_attr( (string) $settings['temperature'] ); ?>"></td></tr>
				<tr><th>Max tokens</th><td><input type="number" min="100" max="16000" name="max_tokens" value="<?php echo esc_attr( (string) $settings['max_tokens'] ); ?>"></td></tr>
				<tr><th>Timeout</th><td><input type="number" min="5" max="180" name="timeout" value="<?php echo esc_attr( (string) $settings['timeout'] ); ?>"></td></tr>
				<tr><th>Runtime features</th><td>
					<label><input type="checkbox" name="enable_content" value="1" <?php checked( $settings['enable_content'] ); ?>> Enable content generation</label><br>
					<label><input type="checkbox" name="enable_image" value="1" <?php checked( $settings['enable_image'] ); ?>> Enable image generation</label><br>
					<label><input type="checkbox" name="enable_auto_post" value="1" <?php checked( $settings['enable_auto_post'] ); ?>> Enable auto post creation</label><br>
					<label><input type="checkbox" name="enable_featured_image" value="1" <?php checked( $settings['enable_featured_image'] ); ?>> Enable auto featured image</label>
				</td></tr>
				<tr><th>Image source mode</th><td><select name="image_source_mode"><option value="disabled" <?php selected( $settings['image_source_mode'] ?? 'disabled', 'disabled' ); ?>>Disabled</option><option value="ai_generated" <?php selected( $settings['image_source_mode'] ?? '', 'ai_generated' ); ?>>AI Generated</option><option value="free_stock" <?php selected( $settings['image_source_mode'] ?? '', 'free_stock' ); ?>>Free Stock API</option><option value="mixed_ai_first" <?php selected( $settings['image_source_mode'] ?? '', 'mixed_ai_first' ); ?>>Mixed: AI first, stock fallback</option><option value="mixed_stock_first" <?php selected( $settings['image_source_mode'] ?? '', 'mixed_stock_first' ); ?>>Stock first, AI fallback</option></select></td></tr>
				<tr><th>Number of images per post</th><td><select name="images_per_post"><option value="0" <?php selected( (string) ( $settings['images_per_post'] ?? '1' ), '0' ); ?>>0</option><option value="1" <?php selected( (string) ( $settings['images_per_post'] ?? '1' ), '1' ); ?>>1</option><option value="2" <?php selected( (string) ( $settings['images_per_post'] ?? '1' ), '2' ); ?>>2</option><option value="3" <?php selected( (string) ( $settings['images_per_post'] ?? '1' ), '3' ); ?>>3</option><option value="5" <?php selected( (string) ( $settings['images_per_post'] ?? '1' ), '5' ); ?>>5</option><option value="custom" <?php selected( (string) ( $settings['images_per_post'] ?? '1' ), 'custom' ); ?>>custom</option></select> <input type="number" min="0" max="8" name="images_per_post_custom" value="<?php echo esc_attr( (string) ( $settings['images_per_post_custom'] ?? 1 ) ); ?>" style="width:90px;"></td></tr>
				<tr><th>Featured image mode</th><td><select name="featured_image_mode"><option value="first_generated" <?php selected( $settings['featured_image_mode'] ?? 'first_generated', 'first_generated' ); ?>>First generated image</option><option value="best_matched" <?php selected( $settings['featured_image_mode'] ?? '', 'best_matched' ); ?>>Best matched image</option><option value="manual_only" <?php selected( $settings['featured_image_mode'] ?? '', 'manual_only' ); ?>>Manual only</option><option value="disabled" <?php selected( $settings['featured_image_mode'] ?? '', 'disabled' ); ?>>Disabled</option></select></td></tr>
				<tr><th>Insert content images</th><td><label><input type="checkbox" name="insert_images_into_content" value="1" <?php checked( ! empty( $settings['insert_images_into_content'] ) ); ?>> Insert images into article content</label></td></tr>
				<tr><th>Image placement</th><td><select name="image_placement"><option value="after_intro" <?php selected( $settings['image_placement'] ?? 'after_intro', 'after_intro' ); ?>>after intro</option><option value="before_first_h2" <?php selected( $settings['image_placement'] ?? '', 'before_first_h2' ); ?>>before first H2</option><option value="after_every_n_headings" <?php selected( $settings['image_placement'] ?? '', 'after_every_n_headings' ); ?>>after every N headings</option><option value="end_of_article" <?php selected( $settings['image_placement'] ?? '', 'end_of_article' ); ?>>end of article</option><option value="shortcode_placeholder" <?php selected( $settings['image_placement'] ?? '', 'shortcode_placeholder' ); ?>>shortcode placeholder</option></select> Every <input type="number" min="1" max="10" name="image_heading_interval" value="<?php echo esc_attr( (string) ( $settings['image_heading_interval'] ?? 2 ) ); ?>" style="width:70px;"> headings</td></tr>
				<tr><th>Image size</th><td><select name="image_size"><option value="1024x576" <?php selected( $settings['image_size'] ?? '1024x576', '1024x576' ); ?>>1024x576</option><option value="1200x675" <?php selected( $settings['image_size'] ?? '', '1200x675' ); ?>>1200x675</option><option value="1024x1024" <?php selected( $settings['image_size'] ?? '', '1024x1024' ); ?>>1024x1024</option><option value="custom" <?php selected( $settings['image_size'] ?? '', 'custom' ); ?>>custom</option></select> <input type="text" name="image_size_custom" value="<?php echo esc_attr( (string) ( $settings['image_size_custom'] ?? '' ) ); ?>" placeholder="1400x788"></td></tr>
				<tr><th>Image style</th><td><select name="image_style"><option value="realistic" <?php selected( $settings['image_style'] ?? 'realistic', 'realistic' ); ?>>realistic</option><option value="local_travel" <?php selected( $settings['image_style'] ?? '', 'local_travel' ); ?>>local travel</option><option value="taxi_service" <?php selected( $settings['image_style'] ?? '', 'taxi_service' ); ?>>taxi service</option><option value="documentary" <?php selected( $settings['image_style'] ?? '', 'documentary' ); ?>>documentary</option><option value="clean_banner" <?php selected( $settings['image_style'] ?? '', 'clean_banner' ); ?>>clean website banner</option><option value="custom" <?php selected( $settings['image_style'] ?? '', 'custom' ); ?>>custom</option></select> <input type="text" name="image_style_custom" value="<?php echo esc_attr( (string) ( $settings['image_style_custom'] ?? '' ) ); ?>" placeholder="custom style"></td></tr>
				<tr><th>Image API format</th><td><select name="image_api_format"><option value="openai_images" <?php selected( $settings['image_api_format'] ?? 'openai_images', 'openai_images' ); ?>>OpenAI-compatible Images API</option><option value="google_genai_image" <?php selected( $settings['image_api_format'] ?? '', 'google_genai_image' ); ?>>Google GenAI image response</option></select></td></tr>
				<tr><th>Image endpoint</th><td><input type="text" class="regular-text" name="image_endpoint" value="<?php echo esc_attr( (string) ( $settings['image_endpoint'] ?? '/images/generations' ) ); ?>"> Edit endpoint <input type="text" class="regular-text" name="image_edit_endpoint" value="<?php echo esc_attr( (string) ( $settings['image_edit_endpoint'] ?? '/images/edits' ) ); ?>"></td></tr>
				<tr><th>Image response</th><td><select name="image_response_format"><option value="auto" <?php selected( $settings['image_response_format'] ?? 'auto', 'auto' ); ?>>auto</option><option value="url" <?php selected( $settings['image_response_format'] ?? '', 'url' ); ?>>url</option><option value="b64_json" <?php selected( $settings['image_response_format'] ?? '', 'b64_json' ); ?>>b64_json</option></select> <select name="image_quality"><option value="standard" <?php selected( $settings['image_quality'] ?? 'standard', 'standard' ); ?>>standard</option><option value="high" <?php selected( $settings['image_quality'] ?? '', 'high' ); ?>>high</option><option value="low" <?php selected( $settings['image_quality'] ?? '', 'low' ); ?>>low</option><option value="auto" <?php selected( $settings['image_quality'] ?? '', 'auto' ); ?>>auto</option></select> <input type="text" name="image_style_preset" value="<?php echo esc_attr( (string) ( $settings['image_style_preset'] ?? '' ) ); ?>" placeholder="style preset"></td></tr>
				<tr><th>Alt text mode</th><td><select name="alt_text_mode"><option value="ai-generated" <?php selected( $settings['alt_text_mode'] ?? '', 'ai-generated' ); ?>>AI generated</option><option value="route-based" <?php selected( $settings['alt_text_mode'] ?? 'route-based', 'route-based' ); ?>>route-based</option><option value="title-based" <?php selected( $settings['alt_text_mode'] ?? '', 'title-based' ); ?>>title-based</option></select></td></tr>
				<tr><th>Image safety</th><td><label><input type="checkbox" name="overwrite_existing_images" value="1" <?php checked( ! empty( $settings['overwrite_existing_images'] ) ); ?>> Overwrite existing images</label><br><label><input type="checkbox" name="save_image_prompt_to_meta" value="1" <?php checked( ! empty( $settings['save_image_prompt_to_meta'] ) ); ?>> Save image prompt to post meta</label></td></tr>
			</table>
			<?php submit_button( __( 'Save AI Settings', 'similar-route-trip' ) ); ?>
		</form>
		</div>

		<div class="srt-card">
			<h2>Provider Registry</h2>
			<p class="description">Provider CRUD va test da tach khoi Runtime Settings de tranh conflict form state.</p>
			<div class="srt-actions-row">
				<button type="button" class="button button-primary" id="srt-provider-add"><?php esc_html_e( 'Add Provider', 'similar-route-trip' ); ?></button>
				<button type="button" class="button" id="srt-test-runtime-active"><?php esc_html_e( 'Test Active Runtime', 'similar-route-trip' ); ?></button>
				<button type="button" class="button" id="srt-test-runtime-all"><?php esc_html_e( 'Test All Providers', 'similar-route-trip' ); ?></button>
			</div>
			<div id="srt-ai-runtime-test-result" class="notice inline" style="display:none;"></div>
			<table class="widefat striped srt-provider-table">
				<thead><tr><th>Label</th><th>Type</th><th>Models</th><th>Priority</th><th>Usage</th><th>Status</th><th>Actions</th></tr></thead>
				<tbody id="srt-provider-table-body">
					<?php if ( empty( $provider_summaries ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No providers configured yet.', 'similar-route-trip' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $provider_summaries as $provider ) : ?>
							<tr data-provider-id="<?php echo esc_attr( (string) $provider['id'] ); ?>">
								<td>
									<strong><?php echo esc_html( (string) $provider['label'] ); ?></strong><br>
									<small><?php echo esc_html( (string) $provider['api_key_masked'] ); ?></small>
								</td>
								<td><?php echo esc_html( (string) $provider['provider'] ); ?></td>
								<td>
									<?php if ( '' !== (string) $provider['content_models_preview'] ) : ?>
										<div><strong>Content:</strong> <?php echo esc_html( (string) $provider['content_models_preview'] ); ?></div>
									<?php endif; ?>
									<?php if ( '' !== (string) $provider['image_models_preview'] ) : ?>
										<div><strong>Image:</strong> <?php echo esc_html( (string) $provider['image_models_preview'] ); ?></div>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (string) $provider['priority'] ); ?></td>
								<td><?php echo ! empty( $provider['enabled'] ) ? 'Enabled' : 'Disabled'; ?> - W<?php echo esc_html( (string) $provider['weight'] ); ?></td>
								<td>
									<span class="srt-status srt-status-<?php echo esc_attr( sanitize_html_class( (string) $provider['last_status'] ) ); ?>"><?php echo esc_html( (string) $provider['last_status'] ); ?></span>
									<?php if ( '' !== (string) $provider['last_checked'] ) : ?>
										<br><small><?php echo esc_html( (string) $provider['last_checked'] ); ?></small>
									<?php endif; ?>
									<?php if ( '' !== (string) $provider['last_message'] ) : ?>
										<br><small><?php echo esc_html( (string) $provider['last_message'] ); ?></small>
									<?php endif; ?>
								</td>
								<td>
									<button type="button" class="button button-small srt-provider-edit" data-provider="<?php echo esc_attr( wp_json_encode( $provider['edit_payload'] ) ); ?>"><?php esc_html_e( 'Edit', 'similar-route-trip' ); ?></button>
									<button type="button" class="button button-small srt-provider-test" data-provider-id="<?php echo esc_attr( (string) $provider['id'] ); ?>"><?php esc_html_e( 'Test', 'similar-route-trip' ); ?></button>
									<button type="button" class="button button-small srt-provider-delete" data-provider-id="<?php echo esc_attr( (string) $provider['id'] ); ?>"><?php esc_html_e( 'Delete', 'similar-route-trip' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<script type="application/json" id="srt-provider-data"><?php echo wp_json_encode( $provider_summaries ); ?></script>
		</div>

		<div class="srt-card">
			<h2><?php esc_html_e( 'Operational Links', 'similar-route-trip' ); ?></h2>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=srt-tools' ) ); ?>"><?php esc_html_e( 'Queue / Workers', 'similar-route-trip' ); ?></a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=srt-logs' ) ); ?>"><?php esc_html_e( 'Logs', 'similar-route-trip' ); ?></a></p>
		</div>

		<div id="srt-provider-modal" class="srt-modal" hidden>
			<div class="srt-modal-dialog">
				<div class="srt-modal-header">
					<h3 id="srt-provider-modal-title"><?php esc_html_e( 'Provider', 'similar-route-trip' ); ?></h3>
					<button type="button" class="button-link" id="srt-provider-modal-close" aria-label="<?php esc_attr_e( 'Close', 'similar-route-trip' ); ?>">&times;</button>
				</div>
				<form id="srt-provider-modal-form">
					<input type="hidden" name="provider_id" id="srt-provider-id" value="">
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Enabled', 'similar-route-trip' ); ?></th><td><label><input type="checkbox" id="srt-provider-enabled" name="enabled" value="1" checked> <?php esc_html_e( 'Provider enabled', 'similar-route-trip' ); ?></label></td></tr>
						<tr><th><?php esc_html_e( 'Label', 'similar-route-trip' ); ?></th><td><input type="text" class="regular-text" id="srt-provider-label" name="label" required></td></tr>
						<tr><th><?php esc_html_e( 'Provider type', 'similar-route-trip' ); ?></th><td><select id="srt-provider-type" name="provider"><option value="shopaikey_compatible">ShopAIKey-compatible</option><option value="openai_compatible">OpenAI-compatible</option><option value="gemini_compatible">Gemini-compatible</option><option value="custom_openai_compatible">Custom OpenAI-compatible</option></select></td></tr>
						<tr><th><?php esc_html_e( 'Base URL', 'similar-route-trip' ); ?></th><td><input type="url" class="regular-text" id="srt-provider-base-url" name="base_url" placeholder="https://api.shopaikey.com"></td></tr>
						<tr><th><?php esc_html_e( 'API Key', 'similar-route-trip' ); ?></th><td><input type="password" class="regular-text" id="srt-provider-api-key" name="api_key" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave empty to keep current key', 'similar-route-trip' ); ?>"></td></tr>
						<tr><th><?php esc_html_e( 'Content models', 'similar-route-trip' ); ?></th><td><textarea rows="3" id="srt-provider-content-models" name="content_models" placeholder="gemini-2.5-flash-lite"></textarea></td></tr>
						<tr><th><?php esc_html_e( 'Image models', 'similar-route-trip' ); ?></th><td><textarea rows="3" id="srt-provider-image-models" name="image_models" placeholder="gemini-2.5-flash-image"></textarea></td></tr>
						<tr><th><?php esc_html_e( 'Image endpoint', 'similar-route-trip' ); ?></th><td><input type="text" class="regular-text" id="srt-provider-image-endpoint" name="image_endpoint" value="/images/generations"> <input type="text" class="regular-text" id="srt-provider-image-edit-endpoint" name="image_edit_endpoint" value="/images/edits"></td></tr>
						<tr><th><?php esc_html_e( 'Image API format', 'similar-route-trip' ); ?></th><td><select id="srt-provider-image-api-format" name="image_api_format"><option value="openai_images">OpenAI images</option><option value="google_genai_image">Google GenAI image</option></select></td></tr>
						<tr><th><?php esc_html_e( 'Priority / Weight', 'similar-route-trip' ); ?></th><td><input type="number" min="1" max="100" id="srt-provider-priority" name="priority" value="10" style="width:80px;"> <input type="number" min="1" max="100" id="srt-provider-weight" name="weight" value="1" style="width:80px;"></td></tr>
					</table>
					<div class="srt-actions-row">
						<button type="submit" class="button button-primary" id="srt-provider-save"><?php esc_html_e( 'Save Provider', 'similar-route-trip' ); ?></button>
						<button type="button" class="button" id="srt-provider-cancel"><?php esc_html_e( 'Cancel', 'similar-route-trip' ); ?></button>
					</div>
					<div id="srt-provider-modal-message" class="notice inline" style="display:none;"></div>
				</form>
			</div>
		</div>
		</div>
		<?php
		self::footer();
	}

	public static function render_route_generator(): void {
		self::guard();
		self::header( __( 'Route Generator', 'similar-route-trip' ), 'srt-route-generator' );
		?>
		<div class="srt-card">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="srt_bulk_create_routes">
			<table class="form-table" role="presentation">
				<tr><th>From location</th><td><input class="regular-text" name="from_location" required></td></tr>
				<tr><th>To locations</th><td><textarea class="large-text" rows="8" name="to_locations" required></textarea><p class="description">Moi dong la mot diem den.</p></td></tr>
				<tr><th>Distance / Duration / Price</th><td><input type="number" step="0.1" name="distance_km" placeholder="Km"> <input type="number" name="duration_min" placeholder="Phut"> <input type="number" name="price_min" placeholder="Gia tu"></td></tr>
				<tr><th>Duplicate</th><td><label><input type="checkbox" name="detect_reverse" value="1" checked> Check route dao chieu</label><br><label><input type="checkbox" name="overwrite" value="1"> Cho phep cap nhat route trung slug</label></td></tr>
			</table>
			<?php submit_button( __( 'Bulk Create Routes', 'similar-route-trip' ) ); ?>
		</form>
		</div>
		<?php self::footer(); ?>
		<?php
	}

	public static function render_image_sources(): void {
		self::guard();
		$settings = SRT_Image_Source_Config::get();
		self::header( __( 'Image Sources', 'similar-route-trip' ), 'srt-image-sources' );
		?>
		<div class="srt-card">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="srt_save_image_sources">
			<table class="form-table" role="presentation">
				<tr><th>Source priority</th><td><input type="text" class="large-text" name="source_priority" value="<?php echo esc_attr( implode( ', ', (array) ( $settings['source_priority'] ?? [] ) ) ); ?>"><p class="description">Allowed: ai, unsplash, pexels, pixabay, placeholder. Recommended production order: ai, pexels, pixabay, placeholder, unsplash.</p></td></tr>
				<tr><th>Unsplash</th><td><label><input type="checkbox" name="unsplash_enabled" value="1" <?php checked( ! empty( $settings['unsplash_enabled'] ) ); ?>> Enable experimental source</label><br><label for="srt-unsplash-access-key"><strong>Access Key</strong></label><br><input id="srt-unsplash-access-key" type="password" class="regular-text" name="unsplash_access_key" value="" placeholder="<?php echo esc_attr( AIKeyVault::mask( (string) ( $settings['unsplash_access_key'] ?? '' ) ) ); ?>"><p class="description">Use the Unsplash <strong>Access Key</strong> for server-side search requests with <code>Authorization: Client-ID ...</code>. Do not paste the Secret Key here. Secret Key is tied to OAuth-style flows and is not used by this plugin's stock search integration. Warning: Unsplash API guidelines emphasize hotlinking and download tracking; this plugin imports to Media Library, so keep Unsplash optional.</p><select name="unsplash_orientation"><option value="landscape" <?php selected( $settings['unsplash_orientation'] ?? 'landscape', 'landscape' ); ?>>landscape</option><option value="portrait" <?php selected( $settings['unsplash_orientation'] ?? '', 'portrait' ); ?>>portrait</option><option value="squarish" <?php selected( $settings['unsplash_orientation'] ?? '', 'squarish' ); ?>>squarish</option></select> <select name="unsplash_order_by"><option value="relevant" <?php selected( $settings['unsplash_order_by'] ?? 'relevant', 'relevant' ); ?>>relevant</option><option value="latest" <?php selected( $settings['unsplash_order_by'] ?? '', 'latest' ); ?>>latest</option></select> <select name="unsplash_content_filter"><option value="low" <?php selected( $settings['unsplash_content_filter'] ?? 'low', 'low' ); ?>>content low</option><option value="high" <?php selected( $settings['unsplash_content_filter'] ?? '', 'high' ); ?>>content high</option></select> <select name="unsplash_color"><option value="" <?php selected( $settings['unsplash_color'] ?? '', '' ); ?>>any color</option><option value="black_and_white" <?php selected( $settings['unsplash_color'] ?? '', 'black_and_white' ); ?>>black_and_white</option><option value="black" <?php selected( $settings['unsplash_color'] ?? '', 'black' ); ?>>black</option><option value="white" <?php selected( $settings['unsplash_color'] ?? '', 'white' ); ?>>white</option><option value="yellow" <?php selected( $settings['unsplash_color'] ?? '', 'yellow' ); ?>>yellow</option><option value="orange" <?php selected( $settings['unsplash_color'] ?? '', 'orange' ); ?>>orange</option><option value="red" <?php selected( $settings['unsplash_color'] ?? '', 'red' ); ?>>red</option><option value="purple" <?php selected( $settings['unsplash_color'] ?? '', 'purple' ); ?>>purple</option><option value="magenta" <?php selected( $settings['unsplash_color'] ?? '', 'magenta' ); ?>>magenta</option><option value="green" <?php selected( $settings['unsplash_color'] ?? '', 'green' ); ?>>green</option><option value="teal" <?php selected( $settings['unsplash_color'] ?? '', 'teal' ); ?>>teal</option><option value="blue" <?php selected( $settings['unsplash_color'] ?? '', 'blue' ); ?>>blue</option></select> <select name="unsplash_credit_mode"><option value="caption" <?php selected( $settings['unsplash_credit_mode'] ?? 'caption', 'caption' ); ?>>Caption credit</option><option value="description" <?php selected( $settings['unsplash_credit_mode'] ?? '', 'description' ); ?>>Description credit</option><option value="none" <?php selected( $settings['unsplash_credit_mode'] ?? '', 'none' ); ?>>Do not show credit text</option></select><p style="margin-top:8px;"><input type="text" class="regular-text srt-stock-query" data-provider="unsplash" placeholder="Search query preview" value="mekong delta taxi"> <button type="button" class="button srt-test-image-source" data-provider="unsplash">Test Source</button> <button type="button" class="button srt-search-image-source" data-provider="unsplash">Search Preview</button></p><div class="srt-image-source-result" id="srt-source-result-unsplash"></div></td></tr>
				<tr><th>Pexels</th><td><label><input type="checkbox" name="pexels_enabled" value="1" <?php checked( ! empty( $settings['pexels_enabled'] ) ); ?>> Enable</label><br><label for="srt-pexels-api-key"><strong>API Key</strong></label><br><input id="srt-pexels-api-key" type="password" class="regular-text" name="pexels_api_key" value="" placeholder="<?php echo esc_attr( AIKeyVault::mask( (string) ( $settings['pexels_api_key'] ?? '' ) ) ); ?>"><p class="description">Doc-backed fields: orientation, size, color, locale. Pexels asks you to credit photographers whenever possible.</p><select name="pexels_orientation"><option value="landscape" <?php selected( $settings['pexels_orientation'] ?? 'landscape', 'landscape' ); ?>>landscape</option><option value="portrait" <?php selected( $settings['pexels_orientation'] ?? '', 'portrait' ); ?>>portrait</option><option value="square" <?php selected( $settings['pexels_orientation'] ?? '', 'square' ); ?>>square</option></select> <select name="pexels_size"><option value="large" <?php selected( $settings['pexels_size'] ?? '', 'large' ); ?>>large</option><option value="medium" <?php selected( $settings['pexels_size'] ?? 'medium', 'medium' ); ?>>medium</option><option value="small" <?php selected( $settings['pexels_size'] ?? '', 'small' ); ?>>small</option></select> <input type="text" name="pexels_color" value="<?php echo esc_attr( (string) ( $settings['pexels_color'] ?? '' ) ); ?>" placeholder="color or #ffffff"> <input type="text" name="pexels_locale" value="<?php echo esc_attr( (string) ( $settings['pexels_locale'] ?? 'en-US' ) ); ?>" placeholder="en-US"><p style="margin-top:8px;"><input type="text" class="regular-text srt-stock-query" data-provider="pexels" placeholder="Search query preview" value="mekong delta taxi"> <button type="button" class="button srt-test-image-source" data-provider="pexels">Test Source</button> <button type="button" class="button srt-search-image-source" data-provider="pexels">Search Preview</button></p><div class="srt-image-source-result" id="srt-source-result-pexels"></div></td></tr>
				<tr><th>Pixabay</th><td><label><input type="checkbox" name="pixabay_enabled" value="1" <?php checked( ! empty( $settings['pixabay_enabled'] ) ); ?>> Enable</label><br><label for="srt-pixabay-api-key"><strong>API Key</strong></label><br><input id="srt-pixabay-api-key" type="password" class="regular-text" name="pixabay_api_key" value="" placeholder="<?php echo esc_attr( AIKeyVault::mask( (string) ( $settings['pixabay_api_key'] ?? '' ) ) ); ?>"><p class="description">Doc-backed fields: image_type, orientation, safesearch, order, category, colors, editors_choice. Pixabay search API accepts per_page from 3 to 200.</p><select name="pixabay_image_type"><option value="photo" <?php selected( $settings['pixabay_image_type'] ?? 'photo', 'photo' ); ?>>photo</option><option value="all" <?php selected( $settings['pixabay_image_type'] ?? '', 'all' ); ?>>all</option><option value="illustration" <?php selected( $settings['pixabay_image_type'] ?? '', 'illustration' ); ?>>illustration</option><option value="vector" <?php selected( $settings['pixabay_image_type'] ?? '', 'vector' ); ?>>vector</option></select> <select name="pixabay_orientation"><option value="horizontal" <?php selected( $settings['pixabay_orientation'] ?? 'horizontal', 'horizontal' ); ?>>horizontal</option><option value="vertical" <?php selected( $settings['pixabay_orientation'] ?? '', 'vertical' ); ?>>vertical</option><option value="all" <?php selected( $settings['pixabay_orientation'] ?? '', 'all' ); ?>>all</option></select> <label><input type="checkbox" name="pixabay_safesearch" value="1" <?php checked( ! empty( $settings['pixabay_safesearch'] ) ); ?>> safesearch</label> <label><input type="checkbox" name="pixabay_editors_choice" value="1" <?php checked( ! empty( $settings['pixabay_editors_choice'] ) ); ?>> editors choice</label><br><select name="pixabay_order"><option value="popular" <?php selected( $settings['pixabay_order'] ?? 'popular', 'popular' ); ?>>popular</option><option value="latest" <?php selected( $settings['pixabay_order'] ?? '', 'latest' ); ?>>latest</option></select> <select name="pixabay_category"><option value="" <?php selected( $settings['pixabay_category'] ?? '', '' ); ?>>all categories</option><option value="backgrounds" <?php selected( $settings['pixabay_category'] ?? '', 'backgrounds' ); ?>>backgrounds</option><option value="fashion" <?php selected( $settings['pixabay_category'] ?? '', 'fashion' ); ?>>fashion</option><option value="nature" <?php selected( $settings['pixabay_category'] ?? '', 'nature' ); ?>>nature</option><option value="science" <?php selected( $settings['pixabay_category'] ?? '', 'science' ); ?>>science</option><option value="education" <?php selected( $settings['pixabay_category'] ?? '', 'education' ); ?>>education</option><option value="feelings" <?php selected( $settings['pixabay_category'] ?? '', 'feelings' ); ?>>feelings</option><option value="health" <?php selected( $settings['pixabay_category'] ?? '', 'health' ); ?>>health</option><option value="people" <?php selected( $settings['pixabay_category'] ?? '', 'people' ); ?>>people</option><option value="religion" <?php selected( $settings['pixabay_category'] ?? '', 'religion' ); ?>>religion</option><option value="places" <?php selected( $settings['pixabay_category'] ?? '', 'places' ); ?>>places</option><option value="animals" <?php selected( $settings['pixabay_category'] ?? '', 'animals' ); ?>>animals</option><option value="industry" <?php selected( $settings['pixabay_category'] ?? '', 'industry' ); ?>>industry</option><option value="computer" <?php selected( $settings['pixabay_category'] ?? '', 'computer' ); ?>>computer</option><option value="food" <?php selected( $settings['pixabay_category'] ?? '', 'food' ); ?>>food</option><option value="sports" <?php selected( $settings['pixabay_category'] ?? '', 'sports' ); ?>>sports</option><option value="transportation" <?php selected( $settings['pixabay_category'] ?? '', 'transportation' ); ?>>transportation</option><option value="travel" <?php selected( $settings['pixabay_category'] ?? '', 'travel' ); ?>>travel</option><option value="buildings" <?php selected( $settings['pixabay_category'] ?? '', 'buildings' ); ?>>buildings</option><option value="business" <?php selected( $settings['pixabay_category'] ?? '', 'business' ); ?>>business</option><option value="music" <?php selected( $settings['pixabay_category'] ?? '', 'music' ); ?>>music</option></select> <input type="text" name="pixabay_colors" value="<?php echo esc_attr( (string) ( $settings['pixabay_colors'] ?? '' ) ); ?>" placeholder="red, blue, grayscale"><p style="margin-top:8px;"><input type="text" class="regular-text srt-stock-query" data-provider="pixabay" placeholder="Search query preview" value="mekong delta taxi"> <button type="button" class="button srt-test-image-source" data-provider="pixabay">Test Source</button> <button type="button" class="button srt-search-image-source" data-provider="pixabay">Search Preview</button></p><div class="srt-image-source-result" id="srt-source-result-pixabay"></div></td></tr>
			</table>
			<?php submit_button( __( 'Save Image Sources', 'similar-route-trip' ) ); ?>
		</form>
		<p class="description">Fields on this screen are restricted to parameters documented by each provider. Use the REST endpoints or Content Generator preview controls to test individual providers and preview stock results.</p>
		</div>
		<?php
		self::footer();
	}

	public static function render_content_generator(): void {
		self::guard();
		$routes = RouteRepository::all( [ 'active' => false, 'limit' => 200 ] );
		$topics = TopicTemplateRegistry::all();
		$lengths = ContentLengthProfile::profiles();
		$selected_route = ! empty( $routes ) ? $routes[0] : [];
		$selected_topic = 'route_landing';
		$selected_length = 'standard';
		self::header( __( 'Content Generator', 'similar-route-trip' ), 'srt-content-generator' );
		?>
		<div class="srt-card">
		<div class="srt-preview-panel">
			<h2><?php esc_html_e( 'Content Preview', 'similar-route-trip' ); ?></h2>
			<p><strong>Topic:</strong> <span id="srt-preview-topic"><?php echo esc_html( (string) ( $topics[ $selected_topic ]['label'] ?? 'Route Landing' ) ); ?></span></p>
			<p><strong>Length:</strong> <span id="srt-preview-length"><?php echo esc_html( $selected_length ); ?></span></p>
			<p><strong>Route:</strong> <span id="srt-preview-route"><?php echo esc_html( (string) ( $selected_route['from_city'] ?? '' ) . ' - ' . (string) ( $selected_route['to_city'] ?? '' ) ); ?></span></p>
			<p><strong>Quality gate:</strong> bài sẽ phải qua kiểm tra word count, H1, meta, FAQ và similarity trước khi tạo post.</p>
			<p><strong>Fallback:</strong> nếu AI trả thiếu cấu trúc, hệ thống sẽ chuyển sang bài dự phòng theo topic.</p>
			<hr>
			<p><strong>Live preview status:</strong> <span id="srt-preview-status">Chua tai preview</span></p>
			<p><strong>Quality score:</strong> <span id="srt-preview-quality">-</span></p>
			<p><strong>Similarity:</strong> <span id="srt-preview-similarity">-</span></p>
			<p><strong>SEO title:</strong> <span id="srt-preview-seo-title">-</span></p>
			<p><strong>Meta description:</strong> <span id="srt-preview-meta-description">-</span></p>
			<div id="srt-preview-warnings" class="srt-preview-warnings"></div>
			<div id="srt-preview-content" class="srt-preview-content"></div>
			<p><button type="button" class="button" id="srt-refresh-preview">Refresh Preview</button></p>
		</div>
		<div class="srt-preview-panel" style="margin-bottom:16px;">
			<p><strong>Cảnh báo similarity:</strong> nếu bài mới quá gần bài cũ, plugin sẽ chặn tạo và ghi log breakdown theo text, heading, intro.</p>
		</div>
		<table class="widefat striped" style="margin-bottom:16px;">
			<thead><tr><th>Length</th><th>Words</th><th>Use case</th></tr></thead>
			<tbody>
				<tr><td>short</td><td>700-900</td><td>Route phụ, nhu cầu nhanh, vẫn đủ giá trị.</td></tr>
				<tr><td>standard</td><td>1000-1400</td><td>Mặc định cho route thường.</td></tr>
				<tr><td>long</td><td>1600-2200</td><td>Route chính, intent mạnh hơn, nhiều section hơn.</td></tr>
				<tr><td>deep</td><td>2500-3500</td><td>Pillar page, topic hub, nội dung sâu.</td></tr>
				<tr><td>custom</td><td><?php echo esc_html( 'Min ' . (int) ( $lengths['standard']['min'] ?? 1000 ) . ' / Max ' . (int) ( $lengths['standard']['max'] ?? 1400 ) ); ?></td><td>Tự nhập theo route/topic.</td></tr>
			</tbody>
		</table>
		<table class="widefat striped">
			<thead><tr><th>Route</th><th>Price</th><th>Distance</th><th>Post Status</th><th>Action</th></tr></thead>
			<tbody>
			<?php foreach ( $routes as $route ) : ?>
				<?php $post_id = (int) ( $route['post_id'] ?? 0 ); ?>
				<tr>
					<td><strong><?php echo esc_html( $route['from_city'] . ' - ' . $route['to_city'] ); ?></strong><br><code><?php echo esc_html( (string) $route['slug'] ); ?></code></td>
					<td><?php echo esc_html( (string) $route['price_display'] ); ?></td>
					<td><?php echo esc_html( number_format( (float) $route['distance_km'], 1 ) . ' km' ); ?></td>
					<td><?php echo $post_id > 0 ? esc_html( '#' . $post_id . ' - ' . (string) get_post_status( $post_id ) ) : esc_html__( 'No post', 'similar-route-trip' ); ?></td>
					<td>
						<?php self::post_action_form( (int) $route['id'], $post_id > 0 ? __( 'Queue Regenerate Draft', 'similar-route-trip' ) : __( 'Queue Draft Job', 'similar-route-trip' ), $post_id > 0 ); ?>
						<?php if ( $post_id > 0 ) : ?>
							<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) ); ?>">Edit Post</a>
							<a class="button button-small" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener">View Post</a>
							<?php self::unlink_form( (int) $route['id'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<div class="srt-card">
		<h2><?php esc_html_e( 'Manual Generator Form', 'similar-route-trip' ); ?></h2>
		<p>Preview route o truong dau van dung cho live preview. Neu chon nhieu route trong Bulk queue, form se enqueue nhieu job thay vi tao 1 bai ngay trong request admin.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="srt_create_post">
			<table class="form-table" role="presentation">
				<tr><th>Route</th><td><select name="route_id" id="srt-route-select"><?php foreach ( $routes as $route ) : ?><option value="<?php echo esc_attr( (string) $route['id'] ); ?>" data-route-label="<?php echo esc_attr( $route['from_city'] . ' - ' . $route['to_city'] ); ?>"><?php echo esc_html( $route['from_city'] . ' - ' . $route['to_city'] . ' (' . $route['slug'] . ')' ); ?></option><?php endforeach; ?></select></td></tr>
				<tr><th>Bulk queue routes</th><td><select name="route_ids[]" multiple size="8" style="min-width:420px;"><?php foreach ( $routes as $route ) : ?><option value="<?php echo esc_attr( (string) $route['id'] ); ?>"><?php echo esc_html( $route['from_city'] . ' - ' . $route['to_city'] . ' (' . $route['slug'] . ')' ); ?></option><?php endforeach; ?></select><p class="description">Bo trong neu chi muon queue route dang preview.</p></td></tr>
				<tr><th>Post type / Status</th><td><select name="post_type"><?php foreach ( get_post_types( [ 'public' => true ], 'names' ) as $post_type ) : ?><option value="<?php echo esc_attr( $post_type ); ?>"><?php echo esc_html( $post_type ); ?></option><?php endforeach; ?></select> <select name="status"><option value="draft">draft</option><option value="pending">pending</option><option value="publish">publish</option></select></td></tr>
				<tr><th>Topic</th><td><select name="topic" id="srt-topic-select"><?php foreach ( $topics as $topic_id => $topic ) : ?><option value="<?php echo esc_attr( $topic_id ); ?>" <?php selected( $topic_id, $selected_topic ); ?>><?php echo esc_html( (string) $topic['label'] ); ?></option><?php endforeach; ?></select></td></tr>
				<tr><th>Template</th><td><select name="template" id="srt-template-select"><?php foreach ( PromptTemplateManager::get() as $key => $template ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $selected_topic ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></option><?php endforeach; ?></select></td></tr>
				<tr><th>Content length</th><td><select name="content_length" id="srt-length-select"><option value="short">short</option><option value="standard" selected>standard</option><option value="long">long</option><option value="deep">deep</option><option value="custom">custom</option></select> Min <input type="number" id="srt-min-words" name="min_words" value="1000" min="500" max="12000"> Max <input type="number" id="srt-max-words" name="max_words" value="1400" min="500" max="12000"></td></tr>
				<tr><th>SEO keywords</th><td><input class="regular-text" id="srt-primary-keyword" name="primary_keyword" placeholder="taxi tra vinh di ben tre"> <br><input class="large-text" id="srt-secondary-keywords" name="secondary_keywords" placeholder="taxi lien tinh, taxi 7 cho, taxi mien tay"></td></tr>
				<tr><th>Options</th><td><label><input type="checkbox" name="use_ai" value="1"> Use AI content</label><br><label><input type="checkbox" name="regenerate" value="1"> Update existing post if exists</label><br><label><input type="checkbox" name="generate_images" value="1" checked> Generate images</label><br><label><input type="checkbox" name="insert_images_into_content" value="1"> Insert into article</label></td></tr>
				<tr><th>Image mode</th><td><select name="image_source_mode" id="srt-image-source-mode"><option value="">Use global setting</option><option value="ai_generated">AI Generated</option><option value="free_stock">Free Stock API</option><option value="mixed_ai_first">Mixed: AI first</option><option value="mixed_stock_first">Mixed: Stock first</option></select> <select name="image_count" id="srt-image-count"><option value="0">0</option><option value="1" selected>1</option><option value="2">2</option><option value="3">3</option><option value="5">5</option></select> <select name="image_style" id="srt-image-style"><option value="">Use global style</option><option value="realistic">realistic</option><option value="local_travel">local travel</option><option value="taxi_service">taxi service</option><option value="documentary">documentary</option><option value="clean_banner">clean banner</option></select></td></tr>
				<tr><th>Image prompt preview</th><td><textarea id="srt-image-prompt-preview" class="large-text code" rows="4" readonly></textarea><p><button type="button" class="button" id="srt-generate-image-preview">Generate Image Preview</button></p><div id="srt-image-preview-results"></div></td></tr>
			</table>
			<?php submit_button( __( 'Enqueue Draft/Post Jobs', 'similar-route-trip' ) ); ?>
		</form>
		</div>
		<?php self::footer(); ?>
		<?php
	}

	public static function render_prompt_templates(): void {
		self::guard();
		$templates = PromptTemplateManager::get();
		$topics    = TopicTemplateRegistry::all();
		$lengths   = ContentLengthProfile::profiles();
		self::header( __( 'Prompt Templates', 'similar-route-trip' ), 'srt-prompt-templates' );
		?>
		<div class="srt-card">
		<table class="widefat striped" style="margin-bottom:16px;">
			<thead><tr><th>Topic</th><th>Description</th><th>Intent</th><th>Default length</th><th>Recommended words</th><th>Sections</th><th>Forbidden</th><th>Schema</th><th>CTA</th></tr></thead>
			<tbody>
				<?php foreach ( $topics as $topic_id => $topic ) : ?>
					<tr>
						<td><strong><?php echo esc_html( (string) ( $topic['label'] ?? $topic_id ) ); ?></strong><br><code><?php echo esc_html( $topic_id ); ?></code></td>
						<td><?php echo esc_html( (string) ( $topic['description'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $topic['intent'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $topic['length_default'] ?? 'standard' ) ); ?></td>
						<td><?php echo esc_html( implode( ' - ', (array) ( $topic['recommended_words'] ?? [] ) ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', (array) ( $topic['required_sections'] ?? [] ) ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', (array) ( $topic['forbidden_patterns'] ?? [] ) ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', (array) ( $topic['schema'] ?? [] ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $topic['cta_style'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<table class="widefat striped" style="margin-bottom:16px;">
			<thead><tr><th>Length</th><th>Words</th><th>Usage</th></tr></thead>
			<tbody>
				<tr><td>short</td><td><?php echo esc_html( (int) ( $lengths['short']['min'] ?? 700 ) . ' - ' . (int) ( $lengths['short']['max'] ?? 900 ) ); ?></td><td>Route phụ, bài ngắn vẫn đủ ý.</td></tr>
				<tr><td>standard</td><td><?php echo esc_html( (int) ( $lengths['standard']['min'] ?? 1000 ) . ' - ' . (int) ( $lengths['standard']['max'] ?? 1400 ) ); ?></td><td>Mặc định cho route thường.</td></tr>
				<tr><td>long</td><td><?php echo esc_html( (int) ( $lengths['long']['min'] ?? 1600 ) . ' - ' . (int) ( $lengths['long']['max'] ?? 2200 ) ); ?></td><td>Route quan trọng, thêm insight và FAQ.</td></tr>
				<tr><td>deep</td><td><?php echo esc_html( (int) ( $lengths['deep']['min'] ?? 2500 ) . ' - ' . (int) ( $lengths['deep']['max'] ?? 3500 ) ); ?></td><td>Pillar page / route cluster.</td></tr>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="srt_save_prompt_templates">
			<?php foreach ( $templates as $key => $template ) : ?>
				<h2><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></h2>
				<textarea class="large-text code" rows="8" name="templates[<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( (string) $template ); ?></textarea>
			<?php endforeach; ?>
			<?php submit_button( __( 'Save Templates', 'similar-route-trip' ) ); ?>
		</form>
		<?php self::button_form( 'srt_reset_prompt_templates', __( 'Reset Default Templates', 'similar-route-trip' ) ); ?>
		</div>
		<?php self::footer(); ?>
		<?php
	}

	public static function render_logs(): void {
		self::guard();
		$logs = LogRepository::latest( 100 );
		self::header( __( 'Logs', 'similar-route-trip' ), 'srt-logs' );
		?>
		<div class="srt-card">
		<table class="widefat striped"><thead><tr><th>Time</th><th>Level</th><th>Event</th><th>Message</th></tr></thead><tbody>
		<?php foreach ( $logs as $log ) : ?>
			<tr><td><?php echo esc_html( (string) $log['created_at'] ); ?></td><td><?php echo esc_html( (string) $log['level'] ); ?></td><td><?php echo esc_html( (string) $log['event'] ); ?></td><td><?php echo esc_html( (string) $log['message'] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		</div>
		<?php self::footer(); ?>
		<?php
	}

	public static function render_tools(): void {
		self::guard();
		$stats = JobRepository::stats();
		$queue = JobRepository::recent( 20 );
		$legacy_stats = QueueRepository::stats();
		$legacy_queue = QueueRepository::recent( 10 );
		$worker_config = QueueWorkerConfig::get();
		$queue_detail = null;
		$queue_id = isset( $_GET['queue_id'] ) ? (int) $_GET['queue_id'] : 0;
		if ( $queue_id > 0 ) {
			$queue_detail = JobRepository::get( $queue_id );
		}
		self::header( __( 'Queue / Workers', 'similar-route-trip' ), 'srt-tools' );
		echo '<div class="srt-card">';
		echo '<p><strong>Worker config:</strong> ';
		echo 'Paused ' . esc_html( ! empty( $worker_config['paused'] ) ? 'yes' : 'no' ) . ', ';
		echo 'Workers ' . esc_html( (string) ( $worker_config['worker_count'] ?? 1 ) ) . ', ';
		echo 'Batch ' . esc_html( (string) ( $worker_config['batch_size_per_worker'] ?? 3 ) ) . ', ';
		echo 'Schedule ' . esc_html( (string) ( $worker_config['schedule_interval'] ?? 'five_minutes' ) ) . '</p>';
		echo '<p><strong>Job stats:</strong> ';
		echo 'Pending ' . esc_html( (string) ( $stats['pending'] ?? 0 ) ) . ', ';
		echo 'Processing ' . esc_html( (string) ( $stats['processing'] ?? 0 ) ) . ', ';
		echo 'Failed ' . esc_html( (string) ( $stats['failed'] ?? 0 ) ) . ', ';
		echo 'Retrying ' . esc_html( (string) ( $stats['retrying'] ?? 0 ) ) . ', ';
		echo 'Completed ' . esc_html( (string) ( $stats['completed'] ?? 0 ) ) . ', ';
		echo 'Total ' . esc_html( (string) ( $stats['total'] ?? 0 ) ) . '</p>';
		self::button_form( 'srt_run_queue', __( 'Run Next Batch', 'similar-route-trip' ), 'primary' );
		self::button_form( 'srt_retry_failed_queue', __( 'Retry Failed', 'similar-route-trip' ) );
		self::button_form( 'srt_clear_completed_queue', __( 'Clear Completed Queue', 'similar-route-trip' ) );
		self::button_form( 'srt_repair_generated_posts', __( 'Repair Generated Posts', 'similar-route-trip' ) );
		if ( $queue_detail ) {
			$route = RouteRepository::get_by_id( (int) ( $queue_detail['route_id'] ?? 0 ) );
			$route_label = $route ? (string) ( $route['from_city'] ?? '' ) . ' - ' . (string) ( $route['to_city'] ?? '' ) : 'Route #' . (int) ( $queue_detail['route_id'] ?? 0 );
			echo '<h2>Queue Detail #' . esc_html( (string) ( $queue_detail['id'] ?? '' ) ) . '</h2>';
			echo '<p><strong>Route:</strong> ' . esc_html( $route_label ) . '<br>';
			echo '<strong>Task:</strong> ' . esc_html( (string) ( $queue_detail['job_type'] ?? '' ) ) . '<br>';
			echo '<strong>Status:</strong> ' . esc_html( (string) ( $queue_detail['status'] ?? '' ) ) . '<br>';
			echo '<strong>Attempts:</strong> ' . esc_html( (string) ( $queue_detail['attempts'] ?? 0 ) ) . ' / ' . esc_html( (string) ( $queue_detail['max_attempts'] ?? 0 ) ) . '<br>';
			echo '<strong>Updated:</strong> ' . esc_html( (string) ( $queue_detail['updated_at'] ?? '' ) ) . '</p>';
			if ( ! empty( $queue_detail['error_message'] ) ) {
				echo '<p><strong>Error:</strong> ' . esc_html( (string) $queue_detail['error_message'] ) . '</p>';
			}
			echo '<p><strong>Payload:</strong></p>';
			echo '<pre style="white-space:pre-wrap;max-height:260px;overflow:auto;">' . esc_html( wp_json_encode( json_decode( (string) ( $queue_detail['payload_json'] ?? '{}' ), true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0;">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="action" value="srt_retry_queue_item">';
			echo '<input type="hidden" name="queue_id" value="' . esc_attr( (string) ( $queue_detail['id'] ?? 0 ) ) . '">';
			submit_button( __( 'Retry This Item', 'similar-route-trip' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '<h2>Recent Jobs</h2>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Route</th><th>Task</th><th>Status</th><th>Attempts</th><th>Max</th><th>Error</th><th>Updated</th><th>Actions</th></tr></thead><tbody>';
		if ( empty( $queue ) ) {
			echo '<tr><td colspan="9">No queue items yet.</td></tr>';
		}
		foreach ( $queue as $item ) {
			$route = RouteRepository::get_by_id( (int) ( $item['route_id'] ?? 0 ) );
			$route_label = $route ? (string) ( $route['from_city'] ?? '' ) . ' - ' . (string) ( $route['to_city'] ?? '' ) : 'Route #' . (int) ( $item['route_id'] ?? 0 );
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $item['id'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $route_label ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['job_type'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['status'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['attempts'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['max_attempts'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( wp_trim_words( (string) ( $item['error_message'] ?? '' ), 10 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['updated_at'] ?? '' ) ) . '</td>';
			echo '<td>';
			echo '<a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=srt-tools&queue_id=' . (int) ( $item['id'] ?? 0 ) ) ) . '">View</a> ';
			if ( 'completed' !== (string) ( $item['status'] ?? '' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 0 0 6px;">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="action" value="srt_retry_queue_item">';
				echo '<input type="hidden" name="queue_id" value="' . esc_attr( (string) ( $item['id'] ?? 0 ) ) . '">';
				submit_button( __( 'Retry', 'similar-route-trip' ), 'secondary button-small', 'submit', false );
				echo '</form>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<h2 style="margin-top:20px;">Legacy Queue</h2>';
		echo '<p>Pending ' . esc_html( (string) ( $legacy_stats['pending'] ?? 0 ) ) . ', Failed ' . esc_html( (string) ( $legacy_stats['failed'] ?? 0 ) ) . ', Completed ' . esc_html( (string) ( $legacy_stats['completed'] ?? 0 ) ) . '.</p>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Task</th><th>Status</th><th>Updated</th></tr></thead><tbody>';
		if ( empty( $legacy_queue ) ) {
			echo '<tr><td colspan="4">No legacy queue items.</td></tr>';
		}
		foreach ( $legacy_queue as $item ) {
			echo '<tr><td>' . esc_html( (string) ( $item['id'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $item['task_type'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $item['status'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $item['updated_at'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
		self::footer();
	}

	public static function handle_import_theme(): void {
		self::verify();
		self::notice_from_result( RouteImporter::import_theme_options() );
		self::redirect( 'srt-import-sync' );
	}

	public static function handle_import_tre(): void {
		self::verify();
		self::notice_from_result( RouteImporter::import_taxi_route_engine() );
		self::redirect( 'srt-import-sync' );
	}

	public static function handle_sync_prices(): void {
		self::verify();
		$result = RouteImporter::sync_prices();
		self::notice( empty( $result['errors'] ) ? 'success' : 'warning', sprintf( 'Updated %d routes from Distance Calculator.', (int) ( $result['updated'] ?? 0 ) ) );
		self::redirect( 'srt-import-sync' );
	}

	public static function handle_save_ai_settings(): void {
		self::verify();
		$payload = wp_unslash( $_POST );
		$current = AIConfig::get();
		$payload['keys'] = isset( $payload['keys'] ) && is_array( $payload['keys'] ) ? $payload['keys'] : ( $current['keys'] ?? [] );
		if ( 'add_provider' === ( $payload['srt_ai_action'] ?? '' ) && ! empty( $payload['new_key'] ) && is_array( $payload['new_key'] ) ) {
			$payload['keys'][] = $payload['new_key'];
		}
		AIConfig::save( $payload );
		self::notice( 'success', 'AI settings saved.' );
		self::redirect( 'srt-ai-settings' );
	}

	public static function handle_save_image_sources(): void {
		self::verify();
		SRT_Image_Source_Config::save( wp_unslash( $_POST ) );
		self::notice( 'success', 'Image source settings saved.' );
		self::redirect( 'srt-image-sources' );
	}

	public static function handle_test_ai(): void {
		self::verify();
		$result = AIService::provider()->test_connection();
		self::notice( empty( $result['success'] ) ? 'error' : 'success', (string) ( $result['message'] ?? $result['error'] ?? 'AI test complete.' ) );
		self::redirect( 'srt-ai-settings' );
	}

	public static function handle_test_ai_keys(): void {
		self::verify();
		$results = AIService::test_all_keys();
		$ok = 0;
		foreach ( $results as $item ) {
			if ( ! empty( $item['result']['success'] ) ) {
				$ok++;
			}
		}
		self::notice( $ok === count( $results ) ? 'success' : 'warning', sprintf( 'Tested %d keys, %d OK.', count( $results ), $ok ) );
		self::redirect( 'srt-ai-settings' );
	}

	public static function handle_test_ai_key(): void {
		self::verify();
		$key_id = sanitize_key( (string) ( $_POST['key_id'] ?? '' ) );
		$key = null;
		foreach ( AIConfig::keys( false, true ) as $item ) {
			if ( ( $item['id'] ?? '' ) === $key_id ) {
				$key = $item;
				break;
			}
		}
		if ( ! $key ) {
			self::notice( 'error', 'Provider key not found.' );
			self::redirect( 'srt-ai-settings' );
		}
		$results = [];
		if ( ! empty( $key['content_models'] ) ) {
			$results['content'] = AIService::test_key( $key, 'content' );
		}
		if ( ! empty( $key['image_models'] ) ) {
			$results['image'] = AIService::test_key( $key, 'image' );
		}
		if ( empty( $results ) ) {
			$results['content'] = AIService::test_key( $key, 'content' );
		}
		$ok = false;
		$messages = [];
		foreach ( $results as $purpose => $result ) {
			$ok = $ok || ! empty( $result['success'] );
			$messages[] = strtoupper( $purpose ) . ': ' . (string) ( $result['message'] ?? $result['error'] ?? 'done' );
		}
		self::notice( $ok ? 'success' : 'error', implode( ' | ', $messages ) );
		self::redirect( 'srt-ai-settings' );
	}

	public static function handle_delete_ai_key(): void {
		self::verify();
		$key_id = sanitize_key( (string) ( $_POST['key_id'] ?? '' ) );
		$settings = AIConfig::get();
		$keys = array_values(
			array_filter(
				(array) ( $settings['keys'] ?? [] ),
				static fn( $item ): bool => is_array( $item ) && ( (string) ( $item['id'] ?? '' ) !== $key_id )
			)
		);
		$settings['keys'] = $keys;
		if ( (string) ( $settings['active_key_id'] ?? '' ) === $key_id ) {
			$settings['active_key_id'] = '';
		}
		update_option( AIConfig::OPTION, $settings, false );
		$content_settings = ContentProviderRegistry::get();
		$content_settings['providers'] = array_values(
			array_filter(
				(array) ( $content_settings['providers'] ?? [] ),
				static fn( array $item ): bool => (string) ( $item['id'] ?? '' ) !== $key_id
			)
		);
		update_option( ContentProviderRegistry::OPTION, $content_settings, false );
		$image_settings = ImageProviderRegistry::get();
		$image_settings['providers'] = array_values(
			array_filter(
				(array) ( $image_settings['providers'] ?? [] ),
				static fn( array $item ): bool => ! in_array( (string) ( $item['id'] ?? '' ), [ 'img_' . $key_id, $key_id ], true )
			)
		);
		update_option( ImageProviderRegistry::OPTION, $image_settings, false );
		self::notice( 'success', 'Provider deleted.' );
		self::redirect( 'srt-ai-settings' );
	}

	public static function handle_bulk_create_routes(): void {
		self::verify();
		$result = RouteCreator::bulk_create(
			sanitize_text_field( wp_unslash( $_POST['from_location'] ?? '' ) ),
			self::lines( (string) wp_unslash( $_POST['to_locations'] ?? '' ) ),
			[
				'distance_km'    => (float) ( $_POST['distance_km'] ?? 0 ),
				'duration_min'   => (int) ( $_POST['duration_min'] ?? 0 ),
				'price_min'      => (int) ( $_POST['price_min'] ?? 0 ),
				'detect_reverse' => ! empty( $_POST['detect_reverse'] ),
				'overwrite'      => ! empty( $_POST['overwrite'] ),
			]
		);
		self::notice( empty( $result['errors'] ) ? 'success' : 'warning', sprintf( 'Previewed %d, created %d, skipped %d.', (int) $result['previewed'], (int) $result['created'], (int) $result['skipped'] ) );
		self::redirect( 'srt-route-generator' );
	}

	public static function handle_create_post(): void {
		self::verify();
		$runtime_settings = AIConfig::get();
		$image_settings   = ImageProviderRegistry::get();
		$default_image_count = 'custom' === (string) ( $image_settings['images_per_post'] ?? '' )
			? max( 0, min( 8, (int) ( $image_settings['images_per_post_custom'] ?? 1 ) ) )
			: max( 0, min( 8, (int) ( $image_settings['images_per_post'] ?? 1 ) ) );
		$default_generate_images = ! empty( $runtime_settings['enable_image'] ) && 'disabled' !== (string) ( $runtime_settings['image_source_mode'] ?? 'disabled' ) && $default_image_count > 0;

		$payload = [
			'post_type'  => sanitize_key( (string) wp_unslash( $_POST['post_type'] ?? 'post' ) ),
			'status'     => sanitize_key( (string) wp_unslash( $_POST['status'] ?? 'draft' ) ),
			'template'   => sanitize_key( (string) wp_unslash( $_POST['template'] ?? 'route_landing' ) ),
			'topic'      => sanitize_key( (string) wp_unslash( $_POST['topic'] ?? 'route_landing' ) ),
			'content_length' => sanitize_key( (string) wp_unslash( $_POST['content_length'] ?? 'standard' ) ),
			'min_words'  => (int) ( $_POST['min_words'] ?? 1000 ),
			'max_words'  => (int) ( $_POST['max_words'] ?? 1400 ),
			'primary_keyword' => sanitize_text_field( (string) wp_unslash( $_POST['primary_keyword'] ?? '' ) ),
			'secondary_keywords' => sanitize_text_field( (string) wp_unslash( $_POST['secondary_keywords'] ?? '' ) ),
			'use_ai'     => ! empty( $_POST['use_ai'] ),
			'regenerate' => ! empty( $_POST['regenerate'] ),
			'generate_images' => array_key_exists( 'generate_images', $_POST ) ? ! empty( $_POST['generate_images'] ) : $default_generate_images,
			'insert_images_into_content' => ! empty( $_POST['insert_images_into_content'] ),
			'image_source_mode' => sanitize_key( (string) wp_unslash( $_POST['image_source_mode'] ?? (string) ( $runtime_settings['image_source_mode'] ?? '' ) ) ),
			'image_count' => absint( $_POST['image_count'] ?? $default_image_count ),
			'image_style' => sanitize_key( (string) wp_unslash( $_POST['image_style'] ?? '' ) ),
		];
		$route_ids = [];
		if ( ! empty( $_POST['route_ids'] ) && is_array( $_POST['route_ids'] ) ) {
			$route_ids = array_filter( array_map( 'absint', (array) wp_unslash( $_POST['route_ids'] ) ) );
		}
		$single_route_id = absint( $_POST['route_id'] ?? 0 );
		if ( empty( $route_ids ) && $single_route_id > 0 ) {
			$route_ids = [ $single_route_id ];
		}
		$count = QueueManager::enqueue_bulk_content( $route_ids, $payload );
		self::notice( $count > 0 ? 'success' : 'error', $count > 0 ? sprintf( 'Queued %d content jobs.', $count ) : 'Unable to queue content job.' );
		self::redirect( 'srt-content-generator' );
	}

	public static function handle_unlink_post(): void {
		self::verify();
		$route_id = (int) ( $_POST['route_id'] ?? 0 );
		RouteRepository::update_generation_meta(
			$route_id,
			[
				'post_id'          => 0,
				'post_status'      => '',
				'ai_config_source' => '',
				'ai_status'        => '',
				'ai_error'         => '',
			]
		);
		self::notice( 'success', 'Route post link removed. The WordPress post was not deleted.' );
		self::redirect( 'srt-content-generator' );
	}

	public static function handle_save_prompt_templates(): void {
		self::verify();
		PromptTemplateManager::save( isset( $_POST['templates'] ) && is_array( $_POST['templates'] ) ? wp_unslash( $_POST['templates'] ) : [] );
		self::notice( 'success', 'Prompt templates saved.' );
		self::redirect( 'srt-prompt-templates' );
	}

	public static function handle_reset_prompt_templates(): void {
		self::verify();
		PromptTemplateManager::reset();
		self::notice( 'success', 'Prompt templates reset.' );
		self::redirect( 'srt-prompt-templates' );
	}

	public static function handle_run_queue(): void {
		self::verify();
		$worker_result = Worker::run( 'manual-admin', (int) ( QueueWorkerConfig::get()['batch_size_per_worker'] ?? 3 ) );
		$legacy_result = QueueRunner::run_next_batch( 3 );
		self::notice( 'success', sprintf( 'Jobs processed %d, completed %d, retrying %d, failed %d. Legacy queue processed %d.', (int) $worker_result['processed'], (int) $worker_result['completed'], (int) $worker_result['retrying'], (int) $worker_result['failed'], (int) $legacy_result['processed'] ) );
		self::redirect( 'srt-tools' );
	}

	public static function handle_retry_failed_queue(): void {
		self::verify();
		$count = JobRepository::retry_failed();
		$legacy = QueueRepository::retry_failed();
		self::notice( 'success', sprintf( 'Retried %d jobs and %d legacy queue items.', $count, $legacy ) );
		self::redirect( 'srt-tools' );
	}

	public static function handle_retry_queue_item(): void {
		self::verify();
		$queue_id = (int) ( $_POST['queue_id'] ?? 0 );
		if ( $queue_id <= 0 ) {
			self::notice( 'error', 'Queue item not found.' );
			self::redirect( 'srt-tools' );
		}
		$ok = JobRepository::retry_job( $queue_id );
		self::notice( $ok ? 'success' : 'error', $ok ? 'Job moved back to pending.' : 'Unable to retry job.' );
		wp_safe_redirect( admin_url( 'admin.php?page=srt-tools&queue_id=' . $queue_id ) );
		exit;
	}

	public static function handle_clear_completed_queue(): void {
		self::verify();
		$count = JobRepository::clear_completed();
		$legacy = QueueRepository::clear_completed();
		self::notice( 'success', sprintf( 'Cleared %d completed jobs and %d legacy tasks.', $count, $legacy ) );
		self::redirect( 'srt-tools' );
	}

	public static function handle_repair_generated_posts(): void {
		self::verify();
		$result = ContentRepair::run( 100 );
		self::notice(
			empty( $result['errors'] ) ? 'success' : 'warning',
			sprintf( 'Checked %d posts, repaired %d, skipped %d.', (int) ( $result['checked'] ?? 0 ), (int) ( $result['repaired'] ?? 0 ), (int) ( $result['skipped'] ?? 0 ) )
		);
		self::redirect( 'srt-tools' );
	}

	public static function ajax_upsert_provider(): void {
		self::verify_ajax();
		$input = isset( $_POST['provider'] ) && is_array( $_POST['provider'] ) ? wp_unslash( $_POST['provider'] ) : [];
		if ( empty( $input ) ) {
			wp_send_json_error( [ 'message' => 'Provider payload is required.' ], 400 );
		}

		$settings = AIConfig::get();
		$keys     = is_array( $settings['keys'] ?? null ) ? (array) $settings['keys'] : [];
		if ( empty( $keys ) ) {
			$keys = AIConfig::keys( false, false );
		}

		$provider_id = sanitize_key( (string) ( $input['id'] ?? '' ) );
		$row         = self::sanitize_provider_input( $input, $provider_id );
		$updated     = false;

		foreach ( $keys as $index => $existing ) {
			if ( (string) ( $existing['id'] ?? '' ) !== $provider_id ) {
				continue;
			}
			$keys[ $index ] = array_merge( (array) $existing, $row );
			$updated        = true;
			break;
		}

		if ( ! $updated ) {
			if ( '' === $provider_id ) {
				$provider_id = sanitize_key( (string) ( $row['label'] ?? '' ) );
			}
			if ( '' === $provider_id ) {
				$provider_id = sanitize_key( substr( md5( wp_json_encode( $row ) . microtime( true ) . wp_rand() ), 0, 12 ) );
			}
			$row['id'] = $provider_id;
			$keys[]    = $row;
		}

		self::save_ai_settings_with_keys( array_values( $keys ) );
		wp_send_json_success(
			[
				'message'   => $updated ? 'Provider updated.' : 'Provider added.',
				'providers' => self::ai_provider_summaries(),
			]
		);
	}

	public static function ajax_delete_provider(): void {
		self::verify_ajax();
		$provider_id = sanitize_key( (string) ( $_POST['provider_id'] ?? '' ) );
		if ( '' === $provider_id ) {
			wp_send_json_error( [ 'message' => 'Provider ID is required.' ], 400 );
		}

		$settings = AIConfig::get();
		$keys     = is_array( $settings['keys'] ?? null ) ? (array) $settings['keys'] : [];
		if ( empty( $keys ) ) {
			$keys = AIConfig::keys( false, false );
		}
		$keys = array_values(
			array_filter(
				$keys,
				static fn( $item ): bool => is_array( $item ) && ( (string) ( $item['id'] ?? '' ) !== $provider_id )
			)
		);

		$override = [];
		if ( (string) ( $settings['active_key_id'] ?? '' ) === $provider_id ) {
			$override['active_key_id'] = '';
		}
		self::save_ai_settings_with_keys( $keys, $override );
		wp_send_json_success(
			[
				'message'   => 'Provider deleted.',
				'providers' => self::ai_provider_summaries(),
			]
		);
	}

	public static function ajax_test_provider(): void {
		self::verify_ajax();
		$provider_id = sanitize_key( (string) ( $_POST['provider_id'] ?? '' ) );
		if ( '' === $provider_id ) {
			wp_send_json_error( [ 'message' => 'Provider ID is required.' ], 400 );
		}

		$key = null;
		foreach ( AIConfig::keys( false, true ) as $item ) {
			if ( (string) ( $item['id'] ?? '' ) === $provider_id ) {
				$key = $item;
				break;
			}
		}
		if ( ! $key ) {
			wp_send_json_error( [ 'message' => 'Provider not found.' ], 404 );
		}

		$results = [];
		if ( ! empty( $key['content_models'] ) ) {
			$results['content'] = AIService::test_key( $key, 'content' );
		}
		if ( ! empty( $key['image_models'] ) ) {
			$results['image'] = AIService::test_key( $key, 'image' );
		}
		if ( empty( $results ) ) {
			$results['content'] = AIService::test_key( $key, 'content' );
		}
		$ok = false;
		$messages = [];
		foreach ( $results as $purpose => $result ) {
			$ok = $ok || ! empty( $result['success'] );
			$messages[] = strtoupper( (string) $purpose ) . ': ' . (string) ( $result['message'] ?? $result['error'] ?? 'done' );
		}

		wp_send_json_success(
			[
				'message'   => implode( ' | ', $messages ),
				'success'   => $ok,
				'providers' => self::ai_provider_summaries(),
				'results'   => $results,
			]
		);
	}

	public static function ajax_test_runtime(): void {
		self::verify_ajax();
		$mode = sanitize_key( (string) ( $_POST['mode'] ?? 'active' ) );

		if ( 'all' === $mode ) {
			$results = AIService::test_all_keys();
			$ok      = 0;
			foreach ( $results as $item ) {
				if ( ! empty( $item['result']['success'] ) ) {
					$ok++;
				}
			}
			wp_send_json_success(
				[
					'message'   => sprintf( 'Tested %d providers, %d OK.', count( $results ), $ok ),
					'success'   => $ok === count( $results ) && count( $results ) > 0,
					'providers' => self::ai_provider_summaries(),
				]
			);
		}

		$candidate = AIService::first_content_candidate_config();
		if ( empty( $candidate ) ) {
			wp_send_json_error( [ 'message' => 'No active content provider available for runtime test.' ], 400 );
		}
		$result = AIService::provider_instance( $candidate )->test_connection();
		AIService::update_provider_status( 'content', (string) ( $candidate['provider_id'] ?? '' ), $result );
		wp_send_json_success(
			[
				'message'   => (string) ( $result['message'] ?? $result['error'] ?? 'Runtime test complete.' ),
				'success'   => ! empty( $result['success'] ),
				'providers' => self::ai_provider_summaries(),
			]
		);
	}

	private static function verify_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden.' ], 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function ai_provider_summaries(): array {
		$summaries = [];
		foreach ( AIConfig::keys( false, false ) as $key ) {
			$key_id = sanitize_key( (string) ( $key['id'] ?? '' ) );
			if ( '' === $key_id ) {
				continue;
			}
			$content_models = self::normalize_model_list( $key['content_models'] ?? [] );
			$image_models   = self::normalize_model_list( $key['image_models'] ?? [] );
			$summaries[]    = [
				'id'                     => $key_id,
				'label'                  => sanitize_text_field( (string) ( $key['label'] ?? $key_id ) ),
				'provider'               => sanitize_text_field( (string) ( $key['provider'] ?? '' ) ),
				'content_models_preview' => implode( ', ', $content_models ),
				'image_models_preview'   => implode( ', ', $image_models ),
				'priority'               => (int) ( $key['priority'] ?? 10 ),
				'weight'                 => (int) ( $key['weight'] ?? 1 ),
				'enabled'                => ! empty( $key['enabled'] ),
				'last_status'            => sanitize_key( (string) ( $key['last_status'] ?? 'not_tested' ) ),
				'last_message'           => sanitize_text_field( (string) ( $key['last_message'] ?? '' ) ),
				'last_checked'           => sanitize_text_field( (string) ( $key['last_checked'] ?? '' ) ),
				'api_key_masked'         => AIKeyVault::mask( AIKeyVault::decrypt( (string) ( $key['api_key'] ?? '' ) ) ),
				'edit_payload'           => [
					'id'                  => $key_id,
					'label'               => sanitize_text_field( (string) ( $key['label'] ?? $key_id ) ),
					'provider'            => sanitize_text_field( (string) ( $key['provider'] ?? 'shopaikey_compatible' ) ),
					'base_url'            => esc_url_raw( (string) ( $key['base_url'] ?? '' ) ),
					'enabled'             => ! empty( $key['enabled'] ) ? 1 : 0,
					'content_models'      => implode( "\n", $content_models ),
					'image_models'        => implode( "\n", $image_models ),
					'image_endpoint'      => sanitize_text_field( (string) ( $key['image_endpoint'] ?? '/images/generations' ) ),
					'image_edit_endpoint' => sanitize_text_field( (string) ( $key['image_edit_endpoint'] ?? '/images/edits' ) ),
					'image_api_format'    => sanitize_text_field( (string) ( $key['image_api_format'] ?? 'openai_images' ) ),
					'priority'            => (int) ( $key['priority'] ?? 10 ),
					'weight'              => (int) ( $key['weight'] ?? 1 ),
				],
			];
		}
		return $summaries;
	}

	private static function sanitize_provider_input( array $input, string $provider_id ): array {
		$provider = sanitize_text_field( (string) ( $input['provider'] ?? 'shopaikey_compatible' ) );
		if ( ! in_array( $provider, [ 'shopaikey_compatible', 'openai_compatible', 'gemini_compatible', 'custom_openai_compatible' ], true ) ) {
			$provider = 'shopaikey_compatible';
		}

		$image_api_format = sanitize_text_field( (string) ( $input['image_api_format'] ?? 'openai_images' ) );
		if ( ! in_array( $image_api_format, [ 'openai_images', 'google_genai_image' ], true ) ) {
			$image_api_format = 'openai_images';
		}

		return [
			'id'                  => $provider_id,
			'label'               => sanitize_text_field( (string) ( $input['label'] ?? '' ) ),
			'provider'            => $provider,
			'base_url'            => esc_url_raw( (string) ( $input['base_url'] ?? '' ) ),
			'api_key'             => trim( (string) ( $input['api_key'] ?? '' ) ),
			'content_models'      => sanitize_textarea_field( (string) ( $input['content_models'] ?? '' ) ),
			'image_models'        => sanitize_textarea_field( (string) ( $input['image_models'] ?? '' ) ),
			'image_endpoint'      => sanitize_text_field( (string) ( $input['image_endpoint'] ?? '/images/generations' ) ),
			'image_edit_endpoint' => sanitize_text_field( (string) ( $input['image_edit_endpoint'] ?? '/images/edits' ) ),
			'image_api_format'    => $image_api_format,
			'enabled'             => ! empty( $input['enabled'] ) ? 1 : 0,
			'priority'            => max( 1, min( 100, absint( $input['priority'] ?? 10 ) ) ),
			'weight'              => max( 1, min( 100, absint( $input['weight'] ?? 1 ) ) ),
		];
	}

	private static function normalize_model_list( $value ): array {
		if ( is_array( $value ) ) {
			$items = [];
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) ) {
					$items[] = sanitize_text_field( trim( (string) $item ) );
				}
			}
			return array_values( array_filter( $items, static fn( string $item ): bool => '' !== $item && 'array' !== strtolower( $item ) ) );
		}
		if ( ! is_scalar( $value ) ) {
			return [];
		}
		$parts = preg_split( '/[\r\n,]+/', (string) $value ) ?: [];
		$clean = array_map( static fn( string $part ): string => sanitize_text_field( trim( $part ) ), $parts );
		return array_values( array_filter( $clean, static fn( string $item ): bool => '' !== $item && 'array' !== strtolower( $item ) ) );
	}

	private static function save_ai_settings_with_keys( array $keys, array $overrides = [] ): void {
		$current          = AIConfig::get();
		$payload          = $current;
		$payload['api_key'] = '';
		$payload['keys']  = $keys;
		foreach ( $overrides as $key => $value ) {
			$payload[ $key ] = $value;
		}
		AIConfig::save( $payload );
	}

	private static function header( string $title, string $active_page ): void {
		$notice = get_transient( 'srt_admin_notice' );
		delete_transient( 'srt_admin_notice' );
		echo '<div class="wrap srt-admin"><h1>' . esc_html( $title ) . '</h1>';
		self::render_tabs( $active_page );
		$message = is_array( $notice ) ? trim( (string) ( $notice['message'] ?? '' ) ) : '';
		if ( '' !== $message ) {
			echo '<div class="notice notice-' . esc_attr( (string) ( $notice['type'] ?? 'success' ) ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	private static function render_tabs( string $active_page ): void {
		$tabs = [
			'similar-route-trip'  => 'All Routes',
			'srt-import-sync'     => 'Import / Sync',
			'srt-route-generator' => 'Route Generator',
			'srt-content-generator' => 'Content Generator',
			'srt-prompt-templates' => 'Prompt Templates',
			'srt-ai-settings'     => 'AI Settings',
			'srt-image-sources'   => 'Image Sources',
			'srt-logs'            => 'Logs',
			'srt-tools'           => 'Queue / Workers',
		];
		echo '<nav class="nav-tab-wrapper srt-tabs">';
		foreach ( $tabs as $slug => $label ) {
			$class = $slug === $active_page ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	private static function footer(): void {
		echo '</div>';
	}

	private static function button_form( string $action, string $label, string $type = 'secondary' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 8px 8px 0;">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php submit_button( $label, $type, 'submit', false ); ?>
		</form>
		<?php
	}

	private static function post_action_form( int $route_id, string $label, bool $regenerate ): void {
		$settings = AIConfig::get();
		$image_settings = ImageProviderRegistry::get();
		$image_count = 'custom' === (string) ( $image_settings['images_per_post'] ?? '' )
			? max( 0, min( 8, (int) ( $image_settings['images_per_post_custom'] ?? 1 ) ) )
			: max( 0, min( 8, (int) ( $image_settings['images_per_post'] ?? 1 ) ) );
		$default_generate_images = ! empty( $settings['enable_image'] ) && 'disabled' !== (string) ( $settings['image_source_mode'] ?? 'disabled' ) && $image_count > 0;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:4px;">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="srt_create_post">
			<input type="hidden" name="route_id" value="<?php echo esc_attr( (string) $route_id ); ?>">
			<input type="hidden" name="post_type" value="post">
			<input type="hidden" name="status" value="draft">
			<input type="hidden" name="template" value="route_landing">
			<input type="hidden" name="topic" value="route_landing">
			<input type="hidden" name="content_length" value="standard">
			<input type="hidden" name="min_words" value="1000">
			<input type="hidden" name="max_words" value="1400">
			<input type="hidden" name="use_ai" value="1">
			<input type="hidden" name="generate_images" value="<?php echo esc_attr( $default_generate_images ? '1' : '0' ); ?>">
			<input type="hidden" name="image_count" value="<?php echo esc_attr( (string) $image_count ); ?>">
			<input type="hidden" name="image_source_mode" value="<?php echo esc_attr( (string) ( $settings['image_source_mode'] ?? 'disabled' ) ); ?>">
			<input type="hidden" name="insert_images_into_content" value="<?php echo esc_attr( ! empty( $settings['insert_images_into_content'] ) ? '1' : '0' ); ?>">
			<?php if ( $regenerate ) : ?>
				<input type="hidden" name="regenerate" value="1">
			<?php endif; ?>
			<?php submit_button( $label, 'small', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function unlink_form( int $route_id ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:4px;">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="srt_unlink_post">
			<input type="hidden" name="route_id" value="<?php echo esc_attr( (string) $route_id ); ?>">
			<?php submit_button( __( 'Unlink', 'similar-route-trip' ), 'small', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'similar-route-trip' ) );
		}
	}

	private static function verify(): void {
		self::guard();
		check_admin_referer( self::NONCE );
	}

	private static function notice( string $type, string $message ): void {
		set_transient( 'srt_admin_notice', [ 'type' => $type, 'message' => $message ], 30 );
	}

	private static function redirect( string $page ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	private static function notice_from_result( array $result ): void {
		self::notice(
			empty( $result['errors'] ) ? 'success' : 'warning',
			sprintf(
				'Source %s: found %d, imported %d, skipped %d.',
				(string) ( $result['source'] ?? '' ),
				(int) ( $result['found'] ?? 0 ),
				(int) ( $result['imported'] ?? 0 ),
				(int) ( $result['skipped'] ?? 0 )
			)
		);
	}

	private static function lines( string $value ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R+/', $value ) ?: [] ) ) );
	}

	private static function test_key_form( string $key_id ): void {
		if ( '' === $key_id ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-top:4px;">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="srt_test_ai_key">
			<input type="hidden" name="key_id" value="<?php echo esc_attr( $key_id ); ?>">
			<?php submit_button( __( 'Test', 'similar-route-trip' ), 'small', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function delete_key_form( string $key_id ): void {
		if ( '' === $key_id ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-top:4px;">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="srt_delete_ai_key">
			<input type="hidden" name="key_id" value="<?php echo esc_attr( $key_id ); ?>">
			<?php submit_button( __( 'Delete', 'similar-route-trip' ), 'delete small', 'submit', false ); ?>
		</form>
		<?php
	}
}
