<?php
/**
 * Coupon validation (server-side).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Bookings;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Coupons {

	/**
	 * Validate a coupon against fare/context.
	 *
	 * @param string $code Code.
	 * @param array  $ctx service_id, fare_total, email.
	 * @return array|\WP_Error
	 */
	public static function validate( $code, array $ctx = array() ) {
		global $wpdb;
		$code = strtoupper( trim( (string) $code ) );
		if ( '' === $code ) {
			return new \WP_Error( 'prime_fleet_coupon', __( 'Enter a coupon code.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'coupons' ) . ' WHERE code = %s AND is_active = 1 LIMIT 1',
				$code
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return new \WP_Error( 'prime_fleet_coupon', __( 'This coupon is not valid.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$now = current_time( 'mysql' );
		if ( ! empty( $row['starts_at'] ) && $now < $row['starts_at'] ) {
			return new \WP_Error( 'prime_fleet_coupon', __( 'This coupon is not active yet.', 'prime-fleet' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $row['ends_at'] ) && $now > $row['ends_at'] ) {
			return new \WP_Error( 'prime_fleet_coupon', __( 'This coupon has expired.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$fare = isset( $ctx['fare_total'] ) ? (float) $ctx['fare_total'] : 0;
		if ( null !== $row['min_fare'] && '' !== $row['min_fare'] && $fare < (float) $row['min_fare'] ) {
			return new \WP_Error( 'prime_fleet_coupon', __( 'Fare is below the minimum for this coupon.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$service_id = absint( $ctx['service_id'] ?? 0 );
		$scope      = json_decode( (string) ( $row['applicable_services_json'] ?? '' ), true );
		if ( is_array( $scope ) && $scope && $service_id && ! in_array( $service_id, array_map( 'intval', $scope ), true ) ) {
			return new \WP_Error( 'prime_fleet_coupon', __( 'This coupon does not apply to the selected service.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$email = isset( $ctx['email'] ) ? strtolower( sanitize_email( (string) $ctx['email'] ) ) : '';
		if ( 'email_bound' === $row['use_type'] ) {
			$bound = strtolower( (string) $row['bound_email'] );
			if ( ! $email || $bound !== $email ) {
				return new \WP_Error( 'prime_fleet_coupon', __( 'This coupon is not valid for your email.', 'prime-fleet' ), array( 'status' => 400 ) );
			}
		}

		if ( null !== $row['max_uses'] && '' !== $row['max_uses'] ) {
			$used = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Schema::table( 'coupon_redemptions' ) . ' WHERE coupon_id = %d',
					(int) $row['id']
				)
			);
			if ( $used >= (int) $row['max_uses'] ) {
				return new \WP_Error( 'prime_fleet_coupon', __( 'This coupon has reached its usage limit.', 'prime-fleet' ), array( 'status' => 400 ) );
			}
		}

		if ( $email && null !== $row['max_per_email'] && '' !== $row['max_per_email'] ) {
			$used_email = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Schema::table( 'coupon_redemptions' ) . ' WHERE coupon_id = %d AND email = %s',
					(int) $row['id'],
					$email
				)
			);
			if ( $used_email >= (int) $row['max_per_email'] ) {
				return new \WP_Error( 'prime_fleet_coupon', __( 'This coupon has already been used for your email.', 'prime-fleet' ), array( 'status' => 400 ) );
			}
		}

		$discount = 0.0;
		if ( 'percent' === $row['discount_type'] ) {
			$discount = round( $fare * ( (float) $row['discount_value'] / 100 ), 2 );
		} else {
			$discount = min( $fare, (float) $row['discount_value'] );
		}
		$new_total = max( 0, round( $fare - $discount, 2 ) );

		return array(
			'code'           => $row['code'],
			'discount_type'  => $row['discount_type'],
			'discount_value' => (float) $row['discount_value'],
			'discount'       => $discount,
			'fare_before'    => $fare,
			'fare_after'     => $new_total,
			'message'        => sprintf(
				/* translators: %s discount amount */
				__( 'Coupon applied: −%s', 'prime-fleet' ),
				number_format( $discount, 2 )
			),
		);
	}
}
