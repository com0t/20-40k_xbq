<?php

/**
 * Created by PhpStorm.
 * User: gaupoit
 * Date: 7/13/18
 * Time: 10:53
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PPW_Repository_Passwords' ) ) {
	/**
	 * DB class to create table and manage version
	 * Class PPW_Pro_DB
	 */
	class PPW_Repository_Passwords {
		/**
		 * Table version
		 * @var string
		 */
		private $tbl_version;
		/**
		 * Table name
		 * @var string
		 */
		private $tbl_name;

		/**
		 * @var object
		 */
		private $wpdb;

		/**
		 * Instance of PPW_Pro_Shortcode class.
		 *
		 * @var PPW_Repository_Passwords
		 */
		protected static $instance = null;

		/**
		 * PPW_Pro_DB constructor.
		 *
		 * @param $prefix
		 */
		public function __construct( $prefix = false ) {
			global $wpdb;
			$this->wpdb        = $wpdb;
			$this->tbl_version = $this->get_table_version();
			$this->tbl_name    = ! $prefix ? $this->wpdb->prefix . PPW_Constants::TBL_NAME : $prefix . PPW_Constants::TBL_NAME;
		}

		/**
		 * Get short code instance
		 *
		 * @return PPW_Repository_Passwords
		 */
		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				// Use static instead of self due to the inheritance later.
				// For example: ChildSC extends this class, when we call get_instance
				// it will return the object of child class. On the other hand, self function
				// will return the object of base class.
				self::$instance = new static();
			}

			return self::$instance;
		}

		/**
		 * Install table
		 */
		public function install() {
			// TODO: Check highest version to create table.
			$this->init_tbl();

			// Add new column.
			foreach ( PPW_Constants::DB_DATA_COLUMN_TABLE as $data ) {
				$this->add_new_column( $data['old_version'], $data['new_version'], $data['value'] );
			}

			// Update column.
			foreach ( PPW_Constants::DB_UPDATE_COLUMN_TABLE as $dt ) {
				$this->update_table( $dt['old_version'], $dt['new_version'], $dt['value'] );
			}

			// TODO: Add column for pro version.
			$this->update_label_and_post_types_column();
		}

		/**
		 * Uninstall table
		 */
		public function uninstall() {
			$this->wpdb->query( "DROP TABLE IF EXISTS $this->tbl_name" );
		}
		/**
		 * Init table
		 */
		private function init_tbl() {
			if ( $this->is_table_does_not_exist() ) {
				$charset_collate = $this->wpdb->get_charset_collate();
				$sql             = "CREATE TABLE $this->tbl_name (
						id mediumint(9) NOT NULL AUTO_INCREMENT,
						post_id mediumint(9) NOT NULL,
						contact_id mediumint(9) NULL,
						campaign_app_type varchar(50) DEFAULT '' NULL,
						password varchar(30) NOT NULL,
						is_activated tinyint(1) DEFAULT 1,
						created_time BIGINT DEFAULT NULL,
						expired_time BIGINT DEFAULT NULL,
						UNIQUE KEY id(id)
				) $charset_collate;";
				require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
				dbDelta( $sql );

				// Init setting when installing plugin firstly.
				update_option( PPW_Constants::MISC_OPTIONS, wp_json_encode( array( 'wpp_use_custom_form_action' => 'true' ) ), 'no' );

				$this->tbl_version = "1.0";
				$this->update_table_version( $this->tbl_version );
			}
		}

		/**
		 * Add new column for table
		 *
		 * @param $old_version
		 * @param $new_version
		 * @param $value
		 */
		private function add_new_column( $old_version, $new_version, $value ) {
			if ( $this->tbl_version === $old_version ) {
				if ( is_null( $this->check_column_exist( $value ) ) ) {
					$charset_collate = $this->wpdb->get_charset_collate();
					$sql             = "CREATE TABLE $this->tbl_name ( $value ) $charset_collate;";
					require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
					dbDelta( $sql );
				}
				$this->tbl_version = $new_version;
				$this->update_table_version( $this->tbl_version );
			}
		}

		/**
		 * Update value for column in table
		 *
		 * @param $old_version
		 * @param $new_version
		 * @param $value
		 */
		private function update_table( $old_version, $new_version, $value ) {
			if ( $this->tbl_version === $old_version ) {
				$sql = "ALTER TABLE $this->tbl_name CHANGE $value";
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				$this->wpdb->query( $sql );
				$this->tbl_version = $new_version;
				$this->update_table_version( $this->tbl_version );
			}
		}

		/**
		 * Check table is exist
		 *
		 * @return bool
		 */
		private function is_table_does_not_exist() {
			$query_string = 'SHOW TABLES LIKE %s';
			$preparation  = $this->wpdb->prepare( $query_string, $this->tbl_name );

			return $this->wpdb->get_var( $preparation ) != $this->tbl_name;
		}

		/**
		 * Get the plugin table's version
		 */
		private function get_table_version() {
			$version = get_option( PPW_Constants::TBL_VERSION, false );

			return ! $version ? '1.0' : $version;
		}

		/**
		 * Update table version
		 *
		 * @param $version
		 */
		private function update_table_version( $version ) {
			update_option( PPW_Constants::TBL_VERSION, $version );
		}

		/**
		 * Get password info by password and post id
		 *
		 * @param string $password The password.
		 *
		 * @return mixed
		 */
		public function get_master_password_info_by_password( $password ) {
			$query_string = $this->wpdb->prepare( "SELECT * FROM $this->tbl_name WHERE BINARY password = %s and campaign_app_type LIKE 'master_%' and post_id = 0 and is_activated = 1 and (expired_date is NULL OR expired_date > UNIX_TIMESTAMP()) and (usage_limit is NULL OR hits_count < usage_limit)", $password );

			return $this->wpdb->get_row( $query_string );
		}


		/**
		 * Get master password which activating.
		 *
		 * @return array|object|null Database query results.
		 */
		public function get_activate_master_passwords_info() {
			$query_string = "SELECT * FROM $this->tbl_name WHERE post_id = 0 AND campaign_app_type LIKE 'master_%' and is_activated = 1";

			return $this->wpdb->get_results( $query_string );
		}

		/**
		 * Get master password which in database.
		 *
		 * @return array|object|null Database query results.
		 */
		public function get_master_passwords_info() {
			$query_string = "SELECT * FROM $this->tbl_name WHERE post_id = 0 AND campaign_app_type LIKE 'master_%'";

			return $this->wpdb->get_results( $query_string );
		}

		/**
		 * Add a row in table by id.
		 *
		 * @param array $data Data to add.
		 *
		 * @return int|false The number of rows updated, or false on error.
		 */
		public function add_new_password( $data ) {
			$is_added = $this->wpdb->insert( $this->tbl_name, $data );
			if ( $is_added ) {
				return $this->wpdb->insert_id;
			}

			return false;
		}

		public function delete_passwords( $ids, $post_id ) {
			$ids     = implode( ',', array_map( 'absint', $ids ) );
			$post_id = absint( $post_id );
			$this->wpdb->query( "DELETE FROM {$this->tbl_name} WHERE id IN($ids) AND post_id = {$post_id}" );
		}

		/**
		 * Find password by post ID.
		 *
		 * @param string $password Password.
		 *
		 * @return array|object|void|null Database query result in format specified by $output or null on failure
		 */
		public function find_by_master_password( $password ) {
			$sql = $this->wpdb->prepare( "SELECT * FROM {$this->tbl_name} WHERE BINARY password = %s AND post_id = 0 AND campaign_app_type LIKE 'master_%'", $password );

			return $this->wpdb->get_row( $sql );
		}

		/**
		 * Find shared category password.
		 *
		 * @param string $password Password.
		 *
		 * @return array|object|void|null Database query result in format specified by $output or null on failure
		 */
		public function find_by_shared_category_password( $password ) {
			$sql = $this->wpdb->prepare( "SELECT * FROM {$this->tbl_name} WHERE BINARY password = %s AND post_id = 0 AND campaign_app_type = %s", $password, PPW_Category_Service::SHARED_CATEGORY_TYPE );

			return $this->wpdb->get_row( $sql );
		}

		/**
		 * Get all shared categories password.
		 *
		 * @return array|object|void|null Database query result in format specified by $output or null on failure
		 */
		public function get_all_shared_categories_password() {
			$sql = $this->wpdb->prepare( "SELECT * FROM {$this->tbl_name} WHERE post_id = 0 AND campaign_app_type = %s", PPW_Category_Service::SHARED_CATEGORY_TYPE );

			return $this->wpdb->get_results( $sql );
		}

		public function get_passwords_with_type_and_post_id( $type, $post_id, $column = '*' ) {
			$sql = $this->wpdb->prepare( "SELECT {$column} FROM {$this->tbl_name} WHERE post_id = %d AND campaign_app_type = %s", $post_id, $type );

			return $this->wpdb->get_results( $sql );
		}

		/***
		 * Get all custom categories's password
		 * @param $taxonomy_type
		 *
		 * @return mixed
		 */
		public function get_all_custom_categories_password( $taxonomy_type ) {
			$sql = $this->wpdb->prepare( "SELECT * FROM {$this->tbl_name} WHERE post_id = 0 AND campaign_app_type = %s", $taxonomy_type );

			return $this->wpdb->get_results( $sql );
		}

		/***
		 * Check password with custom category type.
		 *
		 * @param $password
		 * @param $taxonomy
		 *
		 * @return mixed
		 */
		public function find_by_shared_custom_category_password( $password, $taxonomy_type ) {
			$sql = $this->wpdb->prepare( "SELECT * FROM {$this->tbl_name} WHERE BINARY password = %s AND post_id = 0 AND campaign_app_type = %s", $password, $taxonomy_type );

			return $this->wpdb->get_row( $sql );
		}

			/**
		 * Get shared category password by password ID.
		 *
		 * @param int $password_id Password ID.
		 *
		 * @return array|object|void|null Database query result in format specified by $output or null on failure
		 */
		public function get_shared_category_password( $password_id ) {
			$sql = $this->wpdb->prepare( "SELECT * FROM {$this->tbl_name} WHERE BINARY id = %d AND campaign_app_type = %s", $password_id, PPW_Category_Service::SHARED_CATEGORY_TYPE );

			return $this->wpdb->get_row( $sql );
		}

		/**
		 * Get shared category password by password ID.
		 *
		 * @param int $password_id Password ID.
		 *
		 * @return array|object|void|null Database query result in format specified by $output or null on failure
		 */
		public function get_shared_custom_category_password( $password_id, $taxonomy ) {
			$sql = $this->wpdb->prepare( "SELECT * FROM {$this->tbl_name} WHERE id = %d AND campaign_app_type = %s", $password_id, $taxonomy );

			return $this->wpdb->get_row( $sql );
		}

		/**
		 * Delete a row in table by id.
		 *
		 * @param int $id ID.
		 *
		 * @return int|false The number of rows updated, or false on error.
		 */
		public function delete( $id ) {
			return $this->wpdb->delete(
				$this->tbl_name,
				array(
					'id' => absint( $id ),
				)
			);
		}

		/**
		 * Update a row in table by id.
		 *
		 * @param int   $id   ID.
		 * @param array $data Data to update.
		 *
		 * @return int|false The number of rows updated, or false on error.
		 */
		public function update_password( $id, $data ) {
			return $this->wpdb->update(
				$this->tbl_name,
				$data,
				array(
					'id' => absint( $id ),
				)
			);
		}

		/**
		 * Update label and post types column.
		 */
		public function update_label_and_post_types_column() {
			$this->add_new_column( '1.6', '1.7', 'label TINYTEXT' );
			$this->add_new_column( '1.7', '1.8', 'post_types varchar(255)' );
			$this->add_new_column( '1.8', '1.9', 'protection_types varchar(50)' );
		}

		/**
		 * Check column exist in database.
		 *
		 * @param string $value Value to add new column.
		 *
		 * @return string|null|false Database query result (as string), or null on failure
		 * @since 1.4.0 Init function.
		 */
		private function check_column_exist( $value ) {
			if ( empty( $value ) ) {
				return false;
			}
			$value_patterns = explode( ' ', $value );
			$field_name     = $value_patterns[0];
			$query          = "SHOW COLUMNS FROM $this->tbl_name LIKE '${field_name}'";

			return $this->wpdb->get_var( $query );
		}

		/**
		 * Get all backup post password.
		 *
		 * @return array|object|void|null Database query result in format specified by $output or null on failure
		 */
		public function get_wp_post_passwords() {
			$sql = "SELECT * FROM {$this->wpdb->postmeta} WHERE meta_key = 'ppwp_post_password_bk'";

			return $this->wpdb->get_results( $sql );
		}

		public function count_wp_post_passwords() {
			$sql = "SELECT COUNT(*) FROM {$this->wpdb->postmeta} WHERE meta_key = 'ppwp_post_password_bk'";

			return $this->wpdb->get_var( $sql );
		}


		/**
		 * Delete selected passwords by id
		 * String will convert to int
		 *
		 * @param array $selected_ids ID Passwords selected.
		 *
		 * @return mixed
		 */
		public function bulk_delete_passwords( $selected_ids ) {
			$selected_ids = implode( ',', array_map( 'absint', $selected_ids ) );

			return $this->wpdb->query( "DELETE FROM {$this->tbl_name} WHERE ID IN({$selected_ids})" );
		}

		public function delete_passwords_by_post_id( $post_id ) {
			return $this->wpdb->delete(
				$this->tbl_name,
				array(
					'post_id' => absint( $post_id ),
				)
			);
		}

		public function find_activated_password( $password, $params ) {
			$args = wp_parse_args(
				$params,
				[
					'post_id'                => 0,
					'roles'                  => [],
					'global_type'            => '',
					'role_type'              => '',
					'allow_to_check_expired' => true,
				]
			);

			$like_where = '';
			if ( $args['role_type'] ) {
				$like_where = $this->generate_where_like_for_roles( $args['roles'], $args['role_type'] );
			}

			$expired_where = '';
			if ( $args['allow_to_check_expired'] ) {
				$expired_where = " AND (expired_date IS NULL OR expired_date > UNIX_TIMESTAMP()) AND (usage_limit IS NULL OR hits_count < usage_limit) ";
			}

			$query = $this->wpdb->prepare( "
				SELECT * FROM {$this->tbl_name }
				WHERE BINARY password = %s
				AND is_activated = 1
				AND ( campaign_app_type = %s {$like_where})
				AND post_id = %d
				{$expired_where}
				",
				$password,
				$args['global_type'],
				$args['post_id']
			);

			return $this->wpdb->get_row( $query );
		}

		public function find_activated_passwords_by_ids( $password_ids, $params ) {
			$args = wp_parse_args(
				$params,
				[
					'post_id'                => 0,
					'roles'                  => [],
					'global_type'            => '',
					'role_type'              => '',
					'allow_to_check_expired' => true,
				]
			);

			$password_ids     = array_map( 'absint', $password_ids );
			$password_ids_str = implode( ', ', $password_ids );
			$like_where       = '';
			if ( $args['role_type'] ) {
				$like_where = $this->generate_where_like_for_roles( $args['roles'], $args['role_type'] );
			}

			$expired_where = '';
			if ( $args['allow_to_check_expired'] ) {
				$expired_where = " AND (expired_date IS NULL OR expired_date > UNIX_TIMESTAMP()) AND (usage_limit IS NULL OR hits_count < usage_limit) ";
			}

			$query = $this->wpdb->prepare( "
				SELECT * FROM {$this->tbl_name }
				WHERE id IN ({$password_ids_str})
				AND is_activated = 1
				AND ( campaign_app_type = %s {$like_where})
				AND post_id = %d
				{$expired_where}
				",
				$args['global_type'],
				$args['post_id']
			);

			return $this->wpdb->get_results( $query );
		}

		/**
		 * Generate query to get password roles type in DB
		 *
		 * @param array  $roles    User roles.
		 *
		 * @return string
		 */
		private function generate_where_like_for_roles( $roles, $role_type ) {
			$where_like_string = '';
			$pcp_role          = $role_type;
			if ( is_array( $roles ) && count( $roles ) > 0 ) {
				/**
				 * Generate roles to string with like condition.
				 * Example:
				 *    ['editor,'admin'] to ' OR campaign_app_type LIKE '%editor% OR campaign_app_type LIKE '%admin%'
				 */
				$where_like_string = array_reduce(
					$roles,
					function ( $carry, $role ) use ( $pcp_role ) {
						if ( ! empty( $role ) ) {
							$carry = $carry . "OR campaign_app_type LIKE '%{$pcp_role}{$role};%' OR campaign_app_type LIKE '%{$pcp_role}{$role}' ";
						}

						return $carry;
					}, $where_like_string );
				$where_like_string = ! empty( $where_like_string ) ? $where_like_string : '';
			}

			return $where_like_string;
		}

	}
}
