<?php
/**
 * Guest payment page (?prime_fleet_pay=TOKEN).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Frontend;

use Prime_Fleet\Payments\Payment_Service;
use Prime_Fleet\Payments\Stripe_Gateway;

defined( 'ABSPATH' ) || exit;

final class Pay_Page {

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 5 );
	}

	/**
	 * Standalone pay page when ?prime_fleet_pay= is present.
	 */
	public function maybe_render() {
		if ( empty( $_GET['prime_fleet_pay'] ) || is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['prime_fleet_pay'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$ack_notice = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! empty( $_POST['prime_fleet_pay_ack'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'prime_fleet_pay_ack_' . $token ) ) {
				$ack_notice = 'nonce';
			} else {
				$result = Payment_Service::acknowledge_paid( $token );
				$ack_notice = is_wp_error( $result ) ? 'error' : 'ok';
			}
		}

		$ctx = Payment_Service::resolve_token( $token );

		// After Stripe Checkout return (?sle_paid=1), confirm session if webhook has not arrived yet (local/dev).
		if ( $ctx && ! empty( $_GET['pf_paid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$reconciled = Stripe_Gateway::reconcile_return( $ctx['payment'] );
			if ( ! is_wp_error( $reconciled ) && $reconciled ) {
				$ctx = Payment_Service::resolve_token( $token );
			}
		}

		status_header( 200 );
		nocache_headers();

		$css = \PRIME_FLEET_PATH . 'assets/frontend/css/booking.css';
		wp_enqueue_style(
			'prime-fleet-fonts',
			'https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Nunito:wght@600;700&display=swap',
			array(),
			null
		);
		wp_enqueue_style(
			'prime-fleet',
			\PRIME_FLEET_URL . 'assets/frontend/css/booking.css',
			array( 'prime-fleet-fonts' ),
			file_exists( $css ) ? (string) filemtime( $css ) : \PRIME_FLEET_VERSION
		);

		?><!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Prime Fleet — Payment', 'prime-fleet' ); ?></title>
			<?php wp_head(); ?>
		</head>
		<body <?php body_class( 'prime-fleet-offer-page prime-fleet-pay-page' ); ?>>
			<main class="prime-fleet-offer-page__shell">
				<div class="prime-fleet-pay" id="prime-fleet-pay">
					<?php self::render_card( $ctx, $ack_notice ); ?>
				</div>
			</main>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * @param array|null $ctx Context from Payment_Service::resolve_token.
	 * @param string     $ack_notice Ack result flag.
	 */
	private static function render_card( $ctx, $ack_notice = '' ) {
		if ( ! $ctx ) {
			echo '<article class="prime-fleet-pay__card">';
			echo '<header class="prime-fleet-pay__header">';
			echo '<p class="prime-fleet-pay__brand">' . esc_html__( 'Prime Fleet', 'prime-fleet' ) . '</p>';
			echo '<p class="prime-fleet-pay__eyebrow">' . esc_html__( 'Payment', 'prime-fleet' ) . '</p>';
			echo '<h1 class="prime-fleet-pay__title">' . esc_html__( 'Link not found', 'prime-fleet' ) . '</h1>';
			echo '</header>';
			echo '<p class="prime-fleet-pay__copy">' . esc_html__( 'This payment link is invalid or has expired. Contact Prime Fleet with your booking reference.', 'prime-fleet' ) . '</p>';
			echo '</article>';
			return;
		}

		$payment  = $ctx['payment'];
		$booking  = $ctx['booking'];
		$method   = $ctx['method'];
		$settings = $ctx['settings'];
		$status   = (string) $payment['status'];
		$amount   = number_format( (float) $payment['amount'], 2 );
		$currency = $payment['currency'] ? (string) $payment['currency'] : 'USD';
		$ref      = (string) $booking['reference'];
		$label    = $method && ! empty( $method['label'] ) ? (string) $method['label'] : __( 'Payment', 'prime-fleet' );
		$paid     = ( 'paid' === $status || 'paid' === (string) $booking['payment_status'] );
		$refunded = ( 'refunded' === $status || 'refunded' === (string) $booking['payment_status'] );
		$pending  = ( 'pending_verification' === $status || 'pending_verification' === (string) $booking['payment_status'] );
		$expired  = ! $paid && ! $refunded && ! $pending && Payment_Service::is_expired( $payment );
		$state    = $paid ? 'paid' : ( $refunded ? 'refunded' : ( $pending ? 'pending' : ( $expired ? 'expired' : 'open' ) ) );

		echo '<article class="prime-fleet-pay__card is-' . esc_attr( $state ) . '">';
		echo '<header class="prime-fleet-pay__header">';
		echo '<p class="prime-fleet-pay__brand">' . esc_html__( 'Prime Fleet', 'prime-fleet' ) . '</p>';

		if ( 'paid' === $state ) {
			$eyebrow = __( 'Confirmed', 'prime-fleet' );
			$title   = __( 'Payment received', 'prime-fleet' );
			$copy    = sprintf(
				/* translators: %s booking reference */
				__( 'Thank you — payment for %s is confirmed.', 'prime-fleet' ),
				$ref
			);
			$amount_label = __( 'Amount paid', 'prime-fleet' );
		} elseif ( 'refunded' === $state ) {
			$eyebrow = __( 'Refunded', 'prime-fleet' );
			$title   = __( 'Payment refunded', 'prime-fleet' );
			$copy    = sprintf(
				/* translators: %s booking reference */
				__( 'The payment for %s has been refunded. Contact Prime Fleet if you have questions.', 'prime-fleet' ),
				$ref
			);
			$amount_label = __( 'Amount', 'prime-fleet' );
		} elseif ( 'pending' === $state ) {
			$eyebrow = __( 'Received', 'prime-fleet' );
			$title   = __( 'Awaiting verification', 'prime-fleet' );
			$copy    = sprintf(
				/* translators: %s booking reference */
				__( 'Thanks — we received your payment notice for %s. Prime Fleet will confirm shortly.', 'prime-fleet' ),
				$ref
			);
			$amount_label = __( 'Amount due', 'prime-fleet' );
		} elseif ( 'expired' === $state ) {
			$eyebrow = __( 'Expired', 'prime-fleet' );
			$title   = __( 'Link expired', 'prime-fleet' );
			$copy    = sprintf(
				/* translators: %s booking reference */
				__( 'This payment link for %s is no longer valid. Please contact Prime Fleet for a new link.', 'prime-fleet' ),
				$ref
			);
			$amount_label = __( 'Amount due', 'prime-fleet' );
		} else {
			$eyebrow = __( 'Payment', 'prime-fleet' );
			$title   = __( 'Complete payment', 'prime-fleet' );
			$copy    = sprintf(
				/* translators: %s booking reference */
				__( 'Please complete payment for booking %s.', 'prime-fleet' ),
				$ref
			);
			$amount_label = __( 'Amount due', 'prime-fleet' );
		}

		echo '<p class="prime-fleet-pay__eyebrow">' . esc_html( $eyebrow ) . '</p>';
		echo '<h1 class="prime-fleet-pay__title">' . esc_html( $title ) . '</h1>';
		echo '<p class="prime-fleet-pay__copy">' . esc_html( $copy ) . '</p>';
		echo '</header>';

		if ( 'ok' === $ack_notice ) {
			echo '<p class="prime-fleet-pay__fine" style="color:#1f7a45!important">' . esc_html__( 'Thanks — Prime Fleet will verify your payment shortly.', 'prime-fleet' ) . '</p>';
		} elseif ( 'error' === $ack_notice || 'nonce' === $ack_notice ) {
			echo '<p class="prime-fleet-pay__fine" style="color:#9b1a1a!important">' . esc_html__( 'Could not record your confirmation. Please try again or contact Prime Fleet.', 'prime-fleet' ) . '</p>';
		}

		echo '<div class="prime-fleet-pay__amount" aria-label="' . esc_attr( $amount_label ) . '">';
		echo '<span class="prime-fleet-pay__amount-label">' . esc_html( $amount_label ) . '</span>';
		echo '<span class="prime-fleet-pay__amount-value"><span class="prime-fleet-pay__currency">' . esc_html( $currency ) . '</span> ' . esc_html( $amount ) . '</span>';
		echo '</div>';

		echo '<ul class="prime-fleet-pay__meta">';
		self::meta( __( 'Reference', 'prime-fleet' ), $ref );
		self::meta( __( 'Method', 'prime-fleet' ), $label );
		echo '</ul>';

		if ( 'open' === $state ) {
			$instructions = isset( $settings['instructions'] ) ? trim( (string) $settings['instructions'] ) : '';
			$instructions = str_replace( array( "\r\n", "\r" ), "\n", $instructions );
			$instructions = str_replace( '\\n', "\n", $instructions );
			if ( $instructions ) {
				echo '<section class="prime-fleet-pay__instructions">';
				echo '<h2 class="prime-fleet-pay__sub">' . esc_html__( 'Payment instructions', 'prime-fleet' ) . '</h2>';
				echo '<div class="prime-fleet-pay__instructions-body">' . nl2br( esc_html( $instructions ), false ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</section>';
			}

			$out_url = ! empty( $payment['custom_url'] ) ? (string) $payment['custom_url'] : '';
			if ( ! $out_url && ! empty( $settings['default_url'] ) ) {
				$out_url = (string) $settings['default_url'];
			}
			$method_key = $method ? (string) $method['method_key'] : '';
			if ( $out_url ) {
				$btn = ( 'stripe' === $method_key )
					? __( 'Pay with card', 'prime-fleet' )
					: __( 'Continue to payment', 'prime-fleet' );
				echo '<div class="prime-fleet-pay__actions">';
				echo '<a class="prime-fleet-pay__btn" href="' . esc_url( $out_url ) . '" rel="noopener noreferrer"' . ( 'stripe' === $method_key ? '' : ' target="_blank"' ) . '>' . esc_html( $btn ) . '</a>';
				echo '</div>';
			}

			if ( 'instructions' === $method_key ) {
				echo '<form method="post" class="prime-fleet-pay__actions">';
				wp_nonce_field( 'prime_fleet_pay_ack_' . (string) $payment['token'] );
				echo '<input type="hidden" name="prime_fleet_pay_ack" value="1" />';
				echo '<button type="submit" class="prime-fleet-pay__btn prime-fleet-pay__btn--ghost">' . esc_html__( 'I’ve paid — notify Prime Fleet', 'prime-fleet' ) . '</button>';
				echo '</form>';
			}
		}

		echo '<p class="prime-fleet-pay__fine">' . esc_html__( 'Questions? Reply to your Prime Fleet email or contact concierge with your reference.', 'prime-fleet' ) . '</p>';
		if ( ! empty( $_GET['pf_paid'] ) && 'paid' !== $state ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<p class="prime-fleet-pay__fine" style="color:#1f7a45!important">' . esc_html__( 'If you just paid, confirmation may take a few seconds while Stripe notifies us. Refresh this page shortly.', 'prime-fleet' ) . '</p>';
		}
		if ( ! empty( $_GET['pf_cancel'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<p class="prime-fleet-pay__fine" style="color:#9b5b1a!important">' . esc_html__( 'Checkout was cancelled. You can try Pay with card again when ready.', 'prime-fleet' ) . '</p>';
		}
		echo '</article>';
	}

	/**
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function meta( $label, $value ) {
		echo '<li class="prime-fleet-pay__meta-item">';
		echo '<span class="prime-fleet-pay__meta-label">' . esc_html( $label ) . '</span>';
		echo '<span class="prime-fleet-pay__meta-value">' . esc_html( $value ) . '</span>';
		echo '</li>';
	}
}
