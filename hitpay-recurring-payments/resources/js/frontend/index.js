
import { sprintf, __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';

import ContentComponent from './component';
import { getHitPayServerData } from './utils';

const defaultLabel = __(
	'HitPay Payment Gateway',
	'woo-gutenberg-products-block'
);

const label = decodeEntities( getHitPayServerData().title ) || defaultLabel;
/**
 * Content component
 */
const Content = () => {
	return decodeEntities( getHitPayServerData().description || '' );
};
/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = ( props ) => {
	const { PaymentMethodLabel } = props.components;
	return <PaymentMethodLabel text={ label } />;
};

const HitPayComponent = ( { RenderedComponent, ...props } ) => {
	return <RenderedComponent { ...props } />;
};

/**
 * HitPay payment method config object.
 */
const HitPay = {
	name: "hit_pay",
	label: <Label />,
	content: <HitPayComponent RenderedComponent={ ContentComponent }/>,
	edit: <HitPayComponent RenderedComponent={ ContentComponent }/>,
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: getHitPayServerData().supports,
	},
};

registerPaymentMethod( HitPay );
