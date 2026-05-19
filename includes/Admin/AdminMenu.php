<?php
/**
 * Admin UI.
 *
 * @package SimilarRouteTrip\Admin
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Admin;

use SimilarRouteTrip\AI\AIConfig;
use SimilarRouteTrip\AI\AIKeyVault;
use SimilarRouteTrip\AI\AIService;
use SimilarRouteTrip\Content\ContentGenerator;
use SimilarRouteTrip\Content\ContentLengthProfile;
use SimilarRouteTrip\Content\ContentRepair;
use SimilarRouteTrip\Content\PromptTemplateManager;
use SimilarRouteTrip\Content\TopicTemplateRegistry;
use SimilarRouteTrip\Database\RouteRepository;
use SimilarRouteTrip\Logging\LogRepository;
use SimilarRouteTrip\Queue\QueueRepository;
use SimilarRouteTrip\Queue\QueueRunner;
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
		add_submenu_page( 'similar-route-trip', __( 'Logs', 'similar-route-trip' ), __( 'Logs', 'similar-route-trip' ), 'manage_options', 'srt-logs', [ self::class, 'render_logs' ] );
		add_submenu_page( 'similar-route-trip', __( 'Tools', 'similar-route-trip' ), __( 'Tools', 'similar-route-trip' ), 'manage_options', 'srt-tools', [ self::class, 'render_tools' ] );
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
		$settings = AIConfig::get();
		$keys     = AIConfig::keys( false, false );
		self::header( __( 'AI Settings', 'similar-route-trip' ), 'srt-ai-settings' );
		?>
		<div class="srt-card">
		<h2>Global AI Settings</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
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
			</table>
			<?php submit_button( __( 'Save AI Settings', 'similar-route-trip' ) ); ?>
		</form>
		</div>

		<div class="srt-card">
			<h2>Provider Registry</h2>
			<p class="description">Add, edit, test, and delete providers from one registry. Global settings stay separate from provider credentials.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="srt_save_ai_settings">
				<input type="hidden" name="mode" value="<?php echo esc_attr( (string) $settings['mode'] ); ?>">
				<input type="hidden" name="active_key_id" value="<?php echo esc_attr( (string) $settings['active_key_id'] ); ?>">
				<input type="hidden" name="selected_content_model" value="<?php echo esc_attr( (string) $settings['selected_content_model'] ); ?>">
				<input type="hidden" name="selected_image_model" value="<?php echo esc_attr( (string) $settings['selected_image_model'] ); ?>">
				<input type="hidden" name="temperature" value="<?php echo esc_attr( (string) $settings['temperature'] ); ?>">
				<input type="hidden" name="max_tokens" value="<?php echo esc_attr( (string) $settings['max_tokens'] ); ?>">
				<input type="hidden" name="timeout" value="<?php echo esc_attr( (string) $settings['timeout'] ); ?>">
				<input type="hidden" name="enable_content" value="<?php echo ! empty( $settings['enable_content'] ) ? '1' : '0'; ?>">
				<input type="hidden" name="enable_image" value="<?php echo ! empty( $settings['enable_image'] ) ? '1' : '0'; ?>">
				<input type="hidden" name="enable_auto_post" value="<?php echo ! empty( $settings['enable_auto_post'] ) ? '1' : '0'; ?>">
				<input type="hidden" name="enable_featured_image" value="<?php echo ! empty( $settings['enable_featured_image'] ) ? '1' : '0'; ?>">
				<table class="widefat striped srt-provider-table">
					<thead><tr><th>Enable</th><th>Label</th><th>Provider type</th><th>Base URL</th><th>API Key</th><th>Content model</th><th>Image model</th><th>Priority</th><th>Weight</th><th>Status</th><th>Actions</th></tr></thead>
					<tbody>
					<tr class="srt-provider-add-toggle-row">
						<td colspan="11">
							<button type="button" class="button button-primary srt-toggle-provider-editor" data-target="#srt-provider-add-row">Add Provider</button>
						</td>
					</tr>
					<tr id="srt-provider-add-row" class="srt-provider-editor-row" hidden>
						<td><input type="checkbox" checked disabled></td>
						<td><input type="text" class="regular-text" name="new_key[label]" placeholder="shop-main"></td>
						<td>
							<select name="new_key[provider]">
								<option value="shopaikey_compatible">ShopAIKey-compatible</option>
								<option value="openai_compatible">OpenAI-compatible</option>
								<option value="gemini_compatible">Gemini-compatible</option>
								<option value="custom_openai_compatible">Custom OpenAI-compatible</option>
							</select>
						</td>
						<td><input type="url" class="regular-text" name="new_key[base_url]" value="https://api.shopaikey.com"></td>
						<td><input type="password" class="regular-text" name="new_key[api_key]" autocomplete="new-password" placeholder="sk-..."></td>
						<td><textarea rows="2" name="new_key[content_models]" placeholder="gemini-2.5-flash-lite"></textarea></td>
						<td><textarea rows="2" name="new_key[image_models]" placeholder="gemini-2.5-flash-image"></textarea></td>
						<td><input type="number" min="1" max="100" name="new_key[priority]" value="10" style="width:70px;"></td>
						<td><input type="number" min="1" max="100" name="new_key[weight]" value="1" style="width:70px;"></td>
						<td><span class="srt-status srt-status-not_tested">new</span></td>
						<td>
							<input type="hidden" name="new_key[enabled]" value="1">
							<button type="submit" name="srt_ai_action" value="add_provider" class="button button-primary button-small">Save</button>
						</td>
					</tr>
					<?php if ( empty( $keys ) ) : ?>
						<tr><td colspan="11">No providers configured yet.</td></tr>
					<?php endif; ?>
					<?php foreach ( $keys as $index => $key ) : ?>
						<tr>
							<td><?php echo ! empty( $key['enabled'] ) ? 'Yes' : 'No'; ?></td>
							<td>
								<strong><?php echo esc_html( (string) ( $key['label'] ?? $key['id'] ?? '' ) ); ?></strong>
							</td>
							<td><?php echo esc_html( (string) ( $key['provider'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $key['base_url'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( AIKeyVault::mask( (string) ( $key['api_key'] ?? '' ) ) ); ?></code></td>
							<td><?php echo esc_html( implode( ', ', (array) ( $key['content_models'] ?? [] ) ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) ( $key['image_models'] ?? [] ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $key['priority'] ?? 10 ) ); ?></td>
							<td><?php echo esc_html( (string) ( $key['weight'] ?? 1 ) ); ?></td>
							<td>
								<span class="srt-status srt-status-<?php echo esc_attr( sanitize_html_class( (string) ( $key['last_status'] ?? 'not_tested' ) ) ); ?>"><?php echo esc_html( (string) ( $key['last_status'] ?? 'not_tested' ) ); ?></span><br>
								<small><?php echo esc_html( (string) ( $key['last_checked'] ?? '' ) ); ?></small>
							</td>
							<td>
								<button type="button" class="button button-small srt-toggle-provider-editor" data-target="#srt-provider-edit-<?php echo esc_attr( (string) $index ); ?>">Edit</button>
								<?php self::test_key_form( (string) ( $key['id'] ?? '' ) ); ?>
								<?php self::delete_key_form( (string) ( $key['id'] ?? '' ) ); ?>
							</td>
						</tr>
						<tr id="srt-provider-edit-<?php echo esc_attr( (string) $index ); ?>" class="srt-provider-editor-row" hidden>
							<td><input type="checkbox" name="keys[<?php echo esc_attr( (string) $index ); ?>][enabled]" value="1" <?php checked( ! empty( $key['enabled'] ) ); ?>></td>
							<td>
								<input type="hidden" name="keys[<?php echo esc_attr( (string) $index ); ?>][id]" value="<?php echo esc_attr( (string) ( $key['id'] ?? '' ) ); ?>">
								<input type="text" class="regular-text" name="keys[<?php echo esc_attr( (string) $index ); ?>][label]" value="<?php echo esc_attr( (string) ( $key['label'] ?? '' ) ); ?>">
								<div class="description">ID: <?php echo esc_html( (string) ( $key['id'] ?? '' ) ); ?></div>
							</td>
							<td>
								<select name="keys[<?php echo esc_attr( (string) $index ); ?>][provider]">
									<option value="shopaikey_compatible" <?php selected( $key['provider'] ?? 'shopaikey_compatible', 'shopaikey_compatible' ); ?>>ShopAIKey-compatible</option>
									<option value="openai_compatible" <?php selected( $key['provider'] ?? '', 'openai_compatible' ); ?>>OpenAI-compatible</option>
									<option value="gemini_compatible" <?php selected( $key['provider'] ?? '', 'gemini_compatible' ); ?>>Gemini-compatible</option>
									<option value="custom_openai_compatible" <?php selected( $key['provider'] ?? '', 'custom_openai_compatible' ); ?>>Custom OpenAI-compatible</option>
								</select>
							</td>
							<td><input type="url" class="regular-text" name="keys[<?php echo esc_attr( (string) $index ); ?>][base_url]" value="<?php echo esc_attr( (string) ( $key['base_url'] ?? 'https://api.shopaikey.com' ) ); ?>"></td>
							<td><input type="password" class="regular-text" name="keys[<?php echo esc_attr( (string) $index ); ?>][api_key]" value="" placeholder="<?php echo esc_attr( AIKeyVault::mask( (string) ( $key['api_key'] ?? '' ) ) ); ?>"></td>
							<td><textarea rows="2" name="keys[<?php echo esc_attr( (string) $index ); ?>][content_models]"><?php echo esc_textarea( implode( "\n", (array) ( $key['content_models'] ?? [] ) ) ); ?></textarea></td>
							<td><textarea rows="2" name="keys[<?php echo esc_attr( (string) $index ); ?>][image_models]"><?php echo esc_textarea( implode( "\n", (array) ( $key['image_models'] ?? [] ) ) ); ?></textarea></td>
							<td><input type="number" min="1" max="100" name="keys[<?php echo esc_attr( (string) $index ); ?>][priority]" value="<?php echo esc_attr( (string) ( $key['priority'] ?? 10 ) ); ?>" style="width:70px;"></td>
							<td><input type="number" min="1" max="100" name="keys[<?php echo esc_attr( (string) $index ); ?>][weight]" value="<?php echo esc_attr( (string) ( $key['weight'] ?? 1 ) ); ?>" style="width:70px;"></td>
							<td colspan="2">Editable row</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div class="srt-actions-row">
					<?php submit_button( __( 'Save Provider Changes', 'similar-route-trip' ), 'secondary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php self::button_form( 'srt_test_ai', __( 'Test Active Key', 'similar-route-trip' ) ); ?>
		<?php self::button_form( 'srt_test_ai_keys', __( 'Test All Keys', 'similar-route-trip' ) ); ?>
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
						<?php self::post_action_form( (int) $route['id'], $post_id > 0 ? __( 'Regenerate Draft', 'similar-route-trip' ) : __( 'Generate Draft', 'similar-route-trip' ), $post_id > 0 ); ?>
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
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="srt_create_post">
			<table class="form-table" role="presentation">
				<tr><th>Route</th><td><select name="route_id" id="srt-route-select"><?php foreach ( $routes as $route ) : ?><option value="<?php echo esc_attr( (string) $route['id'] ); ?>" data-route-label="<?php echo esc_attr( $route['from_city'] . ' - ' . $route['to_city'] ); ?>"><?php echo esc_html( $route['from_city'] . ' - ' . $route['to_city'] . ' (' . $route['slug'] . ')' ); ?></option><?php endforeach; ?></select></td></tr>
				<tr><th>Post type / Status</th><td><select name="post_type"><?php foreach ( get_post_types( [ 'public' => true ], 'names' ) as $post_type ) : ?><option value="<?php echo esc_attr( $post_type ); ?>"><?php echo esc_html( $post_type ); ?></option><?php endforeach; ?></select> <select name="status"><option value="draft">draft</option><option value="pending">pending</option><option value="publish">publish</option></select></td></tr>
				<tr><th>Topic</th><td><select name="topic" id="srt-topic-select"><?php foreach ( $topics as $topic_id => $topic ) : ?><option value="<?php echo esc_attr( $topic_id ); ?>" <?php selected( $topic_id, $selected_topic ); ?>><?php echo esc_html( (string) $topic['label'] ); ?></option><?php endforeach; ?></select></td></tr>
				<tr><th>Template</th><td><select name="template" id="srt-template-select"><?php foreach ( PromptTemplateManager::get() as $key => $template ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $selected_topic ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></option><?php endforeach; ?></select></td></tr>
				<tr><th>Content length</th><td><select name="content_length" id="srt-length-select"><option value="short">short</option><option value="standard" selected>standard</option><option value="long">long</option><option value="deep">deep</option><option value="custom">custom</option></select> Min <input type="number" id="srt-min-words" name="min_words" value="1000" min="500" max="12000"> Max <input type="number" id="srt-max-words" name="max_words" value="1400" min="500" max="12000"></td></tr>
				<tr><th>SEO keywords</th><td><input class="regular-text" id="srt-primary-keyword" name="primary_keyword" placeholder="taxi tra vinh di ben tre"> <br><input class="large-text" id="srt-secondary-keywords" name="secondary_keywords" placeholder="taxi lien tinh, taxi 7 cho, taxi mien tay"></td></tr>
				<tr><th>Options</th><td><label><input type="checkbox" name="use_ai" value="1"> Use AI content</label><br><label><input type="checkbox" name="regenerate" value="1"> Update existing post if exists</label></td></tr>
			</table>
			<?php submit_button( __( 'Create Draft/Post', 'similar-route-trip' ) ); ?>
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
		$stats = QueueRepository::stats();
		$queue = QueueRepository::recent( 20 );
		$queue_detail = null;
		$queue_id = isset( $_GET['queue_id'] ) ? (int) $_GET['queue_id'] : 0;
		if ( $queue_id > 0 ) {
			$queue_detail = QueueRepository::get( $queue_id );
		}
		self::header( __( 'Tools', 'similar-route-trip' ), 'srt-tools' );
		echo '<div class="srt-card">';
		echo '<p><strong>Queue stats:</strong> ';
		echo 'Pending ' . esc_html( (string) ( $stats['pending'] ?? 0 ) ) . ', ';
		echo 'Failed ' . esc_html( (string) ( $stats['failed'] ?? 0 ) ) . ', ';
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
			echo '<strong>Task:</strong> ' . esc_html( (string) ( $queue_detail['task_type'] ?? '' ) ) . '<br>';
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
		echo '<h2>Recent Queue Items</h2>';
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
			echo '<td>' . esc_html( (string) ( $item['task_type'] ?? '' ) ) . '</td>';
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
		$result = AIService::test_key( $key );
		self::notice( empty( $result['success'] ) ? 'error' : 'success', (string) ( $result['message'] ?? $result['error'] ?? 'Provider test complete.' ) );
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
		$result = ContentGenerator::create_post(
			(int) ( $_POST['route_id'] ?? 0 ),
			[
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
			]
		);
		$message = empty( $result['success'] )
			? (string) $result['error']
			: sprintf(
				'Post created/updated: #%d. Quality: %s. Similarity: %s. Edit: %s',
				(int) $result['post_id'],
				isset( $result['quality_score'] ) ? (string) $result['quality_score'] : '-',
				isset( $result['similarity']['score'] ) ? number_format( (float) $result['similarity']['score'], 4 ) : '-',
				(string) ( $result['edit_url'] ?? '' )
			);
		self::notice( empty( $result['success'] ) ? 'error' : 'success', $message );
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
		$result = QueueRunner::run_next_batch( 3 );
		self::notice( 'success', sprintf( 'Processed %d tasks, completed %d, failed %d.', (int) $result['processed'], (int) $result['completed'], (int) $result['failed'] ) );
		self::redirect( 'srt-tools' );
	}

	public static function handle_retry_failed_queue(): void {
		self::verify();
		$count = QueueRepository::retry_failed();
		self::notice( 'success', sprintf( 'Retried %d failed queue items.', $count ) );
		self::redirect( 'srt-tools' );
	}

	public static function handle_retry_queue_item(): void {
		self::verify();
		$queue_id = (int) ( $_POST['queue_id'] ?? 0 );
		if ( $queue_id <= 0 ) {
			self::notice( 'error', 'Queue item not found.' );
			self::redirect( 'srt-tools' );
		}
		$ok = QueueRepository::retry_item( $queue_id );
		self::notice( $ok ? 'success' : 'error', $ok ? 'Queue item moved back to pending.' : 'Unable to retry queue item.' );
		wp_safe_redirect( admin_url( 'admin.php?page=srt-tools&queue_id=' . $queue_id ) );
		exit;
	}

	public static function handle_clear_completed_queue(): void {
		self::verify();
		$count = QueueRepository::clear_completed();
		self::notice( 'success', sprintf( 'Cleared %d completed tasks.', $count ) );
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
			'srt-logs'            => 'Logs',
			'srt-tools'           => 'Tools',
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
