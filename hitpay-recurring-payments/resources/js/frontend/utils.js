/**
 * External dependencies
 */
import { getSetting } from '@woocommerce/settings';

/**
 * HitPay data comes form the server passed on a global object.
 */
export const getHitPayServerData = () => {
	const hitpayServerData = getSetting( 'hit_pay_data', null );
	if ( ! hitpayServerData || typeof hitpayServerData !== 'object' ) {
		throw new Error( 'Hitpay initialization data is not available' );
	}
	return hitpayServerData;
};