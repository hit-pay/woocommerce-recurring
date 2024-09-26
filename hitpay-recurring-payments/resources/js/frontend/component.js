import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

import { getHitPayServerData } from './utils';

const ContentComponent = ( { emitResponse, eventRegistration } ) => {
	const {
        description = '',
	} = getHitPayServerData();

	return (
		<>
            { decodeEntities( description ) }
		</>
	);
};

export default ContentComponent;