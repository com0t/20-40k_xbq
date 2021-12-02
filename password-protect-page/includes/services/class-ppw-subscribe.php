<?php
if ( ! class_exists( 'PPW_Password_Subscribe' ) ) {
	class PPW_Password_Subscribe {

		/**
		 * Handle subscriber request(Call api to save data for subscriber)
		 *
		 * @param string $email email user request.
		 *
		 * @return array
		 */
		public function handle_subscribe_request( $email ) {
			$ppw_config  = include PPW_DIR_PATH . 'config.php';
			$data        = array(
				'email'  => $email,
				'plugin' => 'ppwp',
			);
			$args        = array(
				'body'        => json_encode( $data ),
				'timeout'     => '100',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/json',
				),
			);
			$response    = wp_remote_post(
				$ppw_config->subscribe_api,
				$args
			);
			$status_code = absint( wp_remote_retrieve_response_code( $response ) );
			if ( is_wp_error( $response ) ) {
				return array(
					'error_message' => $response->get_error_message(),
				);
			} else if ( $status_code >= 400 ) {
				return array(
					'error_message' => __('Invalid email address', 'password-protect-page'),
				);
			} else {
				update_user_meta( get_current_user_id(), PPW_Constants::USER_SUBSCRIBE, true );
				return array(
					'data' => json_decode( wp_remote_retrieve_body( $response ) ),
				);
			}
		}
	}
}
