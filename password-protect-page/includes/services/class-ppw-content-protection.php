<?php

class PPW_Content_Protection {
	/**
	 * @var PPW_Content_Protection
	 */
	protected static $instance;

	const POST_TYPE = 'ppwp-section';

	const SETTING_META_KEY = 'ppw_pcp_setting';

	const PASSWORD_GLOBAL_TYPE = 'PCPSection';

	const PASSWORD_ROLE_TYPE = 'PCPSectionRole_';

	const COOKIE_NAME = 'ppw_section-';

	const ADMIN_PATH = 'edit.php?post_type=ppwp-section';

	const SHORTCODE_COLUMN = 'ppw_pcp_shortcode';

	/**
	 * @var PPW_Password_Services
	 */
	private $password_service;

	/**
	 * @var PPW_Repository_Passwords
	 */
	private $password_repo;

	/**
	 * @return PPW_Content_Protection
	 */
	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		$this->password_service = new PPW_Password_Services();
		$this->password_repo    = new PPW_Repository_Passwords();
	}

	public function register() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'wp_ajax_ppw_pcp_validate_password', array( $this, 'validate_password' ) );
		add_action( 'wp_ajax_nopriv_ppw_pcp_validate_password', array( $this, 'validate_password' ) );
		add_filter( 'ppw_shortcode_render_content', array( $this, 'handle_shortcode_with_area' ), 10, 2 );
		add_filter( 'et_builder_load_actions', array( $this, 'add_action_to_divi' ) );
		add_filter( 'ppw_pcp_valid_shortcode', array( $this, 'valid_shortcode' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_add_metabox' ) );
		add_filter( 'ppw_shortcode_unlock_content', array( $this, 'maybe_unlock_content_by_cookie' ), 15, 2 );
		add_filter( 'ppw_pcp_submenu_add_new_tab', array( $this, 'add_pcp_tab' ), 1000 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_shortcode_column' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array(
			$this,
			'render_content_custom_column',
		), 10, 2 );
		add_action( 'admin_notices', array( $this, 'handle_admin_notices' ) );
	}

	public function add_shortcode_column( $columns ) {
		$inserted = [ self::SHORTCODE_COLUMN => 'Shortcode' ];

		return array_slice( $columns, 0, 2, true )
		       + $inserted
		       + array_slice( $columns, 2, count( $columns ) - 1, true );
	}

	public function render_content_custom_column( $column, $post_id ) {
		if ( $column === self::SHORTCODE_COLUMN ) {
			echo '[ppwp section="' . $post_id . '" /]';
		}
	}

	public function add_pcp_tab( $tabs ) {
		$tabs[] = array(
			'tab'      => 'section',
			'tab_name' => 'Section Protection',
			'link'     => self::ADMIN_PATH,
		);

		return $tabs;
	}

	public function valid_shortcode( $content, $attrs ) {
		if ( ! isset( $attrs['section'] ) || ! is_numeric( $attrs['section'] ) ) {
			return $content;
		}

		if ( absint( $attrs['section'] ) > 0 ) {
			return true;
		}

		return $content;
	}

	public function register_post_type() {
		$args = array(
			'labels'          => array(
				'name'               => __( 'Section Protection', 'password-protect-page' ),
				'singular_name'      => __( 'Section Protection', 'password-protect-page' ),
				'add_new'            => __( 'Add New', 'password-protect-page' ),
				'add_new_item'       => __( 'Add New', 'password-protect-page' ),
				'edit_item'          => __( 'Edit', 'password-protect-page' ),
				'new_item'           => __( 'New', 'password-protect-page' ),
				'view_item'          => __( 'View', 'password-protect-page' ),
				'search_items'       => __( 'Search', 'password-protect-page' ),
				'not_found'          => __( 'No found', 'password-protect-page' ),
				'not_found_in_trash' => __( 'No found in Trash', 'password-protect-page' ),
			),
			'public'          => false,
			'hierarchical'    => true,
			'show_ui'         => true,
			'show_in_menu'    => self::ADMIN_PATH,
			'_builtin'        => false,
			'capability_type' => 'post',
			'supports'        => array( 'title', 'editor' ),
			'rewrite'         => false,
			'query_var'       => false,
			'show_in_rest'    => true,
		);

		if ( current_user_can( 'administrator' ) ) {
			$args['public']             = true;
			$args['publicly_queryable'] = true;
		}

//		if ( is_admin() ) {
//			global $pagenow, $typenow, $post;
//			if ( 'edit.php' === $pagenow || self::POST_TYPE === $typenow ) {
//				$args['show_in_menu'] = PPW_Constants::MENU_NAME;
//			} elseif ( isset( $_GET['post_type'] ) && self::POST_TYPE === $_GET['post_type'] ) {
//				$args['show_in_menu'] = PPW_Constants::MENU_NAME;
//			} elseif ( isset( $_GET['post'] ) && isset( $_GET['action'] ) ) {
//				$post_type = get_post_type( $_GET['post'] );
//
//				if ( $post_type === self::POST_TYPE ) {
//					$args['show_in_menu'] = PPW_Constants::MENU_NAME;
//				}
//			}
//		}

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Add ajax support for Divi builder.
	 *
	 * @param array $actions array of allowed actions.
	 *
	 * @return array
	 */
	public function add_action_to_divi( $actions ) {
		$actions[] = 'ppw_pcp_validate_password';

		return $actions;
	}

	public function handle_shortcode_with_area( $content, $attrs ) {
		if ( ! isset( $attrs['section'] ) ) {
			return $content;
		}
		$post_id = absint( $attrs['section'] );
		if ( ! $post_id ) {
			return $content;
		}

		$post = get_post( $post_id );
		if ( empty( $post ) || $post->post_type !== self::POST_TYPE ) {
			return $content;
		}

		$post_content = $this->massage_post_content( $post_id, $post->post_content );
		if ( is_null( $post_content ) ) {
			return $content;
		}

		return $post_content;
	}

	public function massage_post_content( $post_id, $post_content ) {
		$post_content = ppw_support_third_party_content_plugin( $post_id, $post_content );

		return apply_filters( 'the_content', $post_content );
	}

	public function maybe_add_metabox() {
		$attributes = apply_filters(
			'ppw_pcp_metabox_attributes',
			[
				'title'    => __( 'Password Protect WordPress', PPW_Constants::DOMAIN ),
				'callback' => array( $this, 'display_metabox' ),
				'screen'   => [ self::POST_TYPE ],
				'context'  => 'side',
				'priority' => 'high',
			]
		);

		add_meta_box(
			'ppw_pcp_meta_box',
			$attributes['title'],
			$attributes['callback'],
			$attributes['screen'],
			$attributes['context'],
			$attributes['priority']
		);
	}

	public function display_metabox() {
		ob_start();
		?>
		<div id="ppw-pcp-metabox"></div>
		<?php
		wp_enqueue_script( 'ppw-pcp-metabox', PPW_DIR_URL . 'admin/js/dist/pcp-metabox.js', array(), PPW_VERSION, true );
		wp_localize_script(
			'ppw-pcp-metabox',
			'pcpMetabox',
			array(
				'rest_url'      => get_rest_url( null, '/wppp/v1/pcp' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'post_id'       => get_the_ID(),
				'customize_url' => admin_url( 'customize.php' ),
				'extra_html'    => apply_filters( 'ppw_pcp_metabox_extra_html', '')
			)
		);

		echo ob_get_clean();
	}

	public function validate_single_password( $params ) {
		$password = $params['password'];
		$area     = $params['area'];
		$roles    = ppw_core_get_current_role();
		$args     = [
			'post_id'     => $area,
			'roles'       => $roles,
			'global_type' => self::PASSWORD_GLOBAL_TYPE,
			'role_type'   => self::PASSWORD_ROLE_TYPE,
		];

		$password_info = $this->password_repo->find_activated_password( $password, $args );
		if ( empty( $password_info ) ) {
			return false;
		}

		$this->password_service->set_cookie_bypass_cache( $password, self::COOKIE_NAME . $area . 'p' . $password_info->id );

		do_action( 'ppw_after_save_section_cookie', $password_info );

		return true;
	}

	public function validate_password() {
		if ( ! isset( $_POST['pss'] ) ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => 'Please enter the correct password!',
				),
				403
			);
			exit();
		}

		if ( ! isset( $_POST['area'] ) ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => 'Area value is required!',
				),
				403
			);
			exit();
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => 'Post ID is required!',
				),
				403
			);
			exit();
		}
		$password = $_POST['pss'];
		$area     = $_POST['area'];
		$post_id  = $_POST['post_id'];

		$post = get_post( $area );
		if ( empty( $post ) ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => 'Post does not exist',
				),
				403
			);
			exit();
		}
		if ( $post->post_type !== self::POST_TYPE ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => 'Post type is not valid',
				),
				403
			);
			exit();
		}

		$setting = get_post_meta( $area, self::SETTING_META_KEY, true );
		$params  = [
			'password' => $password,
			'area'     => $area,
			'post_id'  => $post_id,
			'setting'  => $setting,
		];

		$is_valid_by_single = $this->validate_single_password( $params );
		$is_valid_password  = apply_filters( 'ppw_pcp_area_is_valid_password', $is_valid_by_single, $params );
		if ( $is_valid_password ) {
			wp_send_json(
				array(
					'reload'  => ! ppw_core_get_setting_type_bool_by_option_name( PPW_Constants::NO_RELOAD_PAGE, PPW_Constants::MISC_OPTIONS ),
					'success' => true,
					'message' => '',
					'content' => $this->massage_post_content( $area, $post->post_content ),
				),
				200
			);
			exit();
		}

		wp_send_json(
			array(
				'success' => false,
				'message' => 'Please enter the correct password!',
			),
			400
		);
	}

	public function check_area_exist( $post ) {
		if ( empty( $post ) ) {
			return false;
		}

		if ( $post->post_type !== self::POST_TYPE ) {
			return false;
		}

		return true;
	}

	public function get_info_from_area_cookie( $area ) {
		if ( empty( $_COOKIE ) ) {
			return [];
		}

		$cookie_keys = array_filter(
			array_keys( $_COOKIE ),
			function ( $key ) {
				return false !== strpos( $key, self::COOKIE_NAME );
			}
		);

		if ( 0 === count( $cookie_keys ) ) {
			return array();
		}

		$results = [];
		foreach ( $cookie_keys as $cookie_key ) {
			$info = str_replace( self::COOKIE_NAME, '', $cookie_key );
			$info = str_replace( COOKIEHASH, '', $info );
			$info = explode( 'p', $info );

			$password_id = absint( ppw_get_value( $info, 1, 0 ) );
			$cookie_area = absint( ppw_get_value( $info, 0, 0 ) );

			if ( $area !== $cookie_area ) {
				continue;
			}

			$results[ $password_id ] = [
				'cookie_name'  => $cookie_key,
				'cookie_value' => $_COOKIE[ $cookie_key ],
			];
		}

		return $results;
	}

	public function validate_cookie( $area ) {
		$cookie_passwords = $this->get_info_from_area_cookie( $area );
		if ( empty( $cookie_passwords ) ) {
			return false;
		}

		$password_ids = array_keys( $cookie_passwords );
		$roles        = ppw_core_get_current_role();
		$params       = [
			'post_id'                => $area,
			'roles'                  => $roles,
			'global_type'            => self::PASSWORD_GLOBAL_TYPE,
			'role_type'              => self::PASSWORD_ROLE_TYPE,
			'allow_to_check_expired' => false,
		];

		$password_infos = $this->password_repo->find_activated_passwords_by_ids(
			$password_ids,
			$params
		);

		if ( empty( $password_infos ) ) {
			return false;
		}

		foreach ( $password_infos as $password_info ) {
			if ( ! isset( $cookie_passwords[ $password_info->id ] ) ) {
				continue;
			}

			$cookie          = $cookie_passwords[ $password_info->id ];
			$hashed_password = $cookie['cookie_value'];

			if ( ppw_free_check_password( $password_info->password, $hashed_password ) ) {
				return true;
			}
		}

		return false;
	}

	public function maybe_unlock_content_by_cookie( $is_valid, $attrs ) {
		if ( $is_valid || ! isset( $attrs['section'] ) ) {
			return $is_valid;
		}

		$area    = $attrs['section'];
		$setting = get_post_meta( $area, self::SETTING_META_KEY, true );

		$post = get_post( $area );
		if ( ! $this->check_area_exist( $post ) ) {
			return $is_valid;
		}

		$params = [
			'area'    => absint( $area ),
			'setting' => $setting,
		];

		$is_valid_cookie = $this->validate_cookie( $params['area'] );

		return apply_filters( 'ppw_pcp_valid_area_password', $is_valid_cookie, $params );
	}

	public function handle_admin_notices() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$current_screen = get_current_screen();
		if ( ! isset( $current_screen->post_type ) ) {
			return;
		}
		if ( $current_screen->post_type !== self::POST_TYPE ) {
			return;
		}

		$button_contexts = [ 'ppwp-section' ];
		if ( in_array( $current_screen->id, $button_contexts ) ) {
			$edit_url = admin_url( self::ADMIN_PATH );
			$button   = '<span style="float: right;">
						<a href="' . $edit_url . '">
							<button class="button button-primary">
								Go back to all sections
							</button>
						</a>
					</span>';
		} else {
			$button = '';
		}


		?>
		<div class="notice">
			<p>
				<div>
					<b>Password Protect WordPress: Section Protection</b>
					<?php echo $button;?>
				</div>
				<a target="_blank" rel="noopener noreferrer"
				   href="https://passwordprotectwp.com/docs/section-protection/">Protect section of your
					content</a> by creating section templates below. Add these sections to your content using
				auto-generated section shortcodes.
			</p>
		</div>
		<?php
	}
}
