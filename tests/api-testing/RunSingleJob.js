const { action, assert, REST, utils, wiki } = require( 'api-testing' );
const crypto = require( 'crypto' );
const uuidv4 = require( 'uuid/v4' );

describe( 'Run Single Job', function () {
	const title = utils.title( 'RunJob' );
	const client = new REST( 'rest.php/eventbus/v0/internal' );
	let mindy, editResults, siteInfo;

	const getDeletePageJobEvent = () => {
		const event = {
			$schema: '/mediawiki/job/1.0.0',
			meta: {
				uri: 'https://placeholder.invalid/wiki/Special:Badtitle',
				request_id: 'XXXXXXXXXXXXXXXXXXXXXXX',
				id: uuidv4(),
				dt: new Date().toISOString(),
				domain: siteInfo.servername,
				stream: 'mediawiki.job.deletePage'
			},
			database: siteInfo.wikiid,
			type: 'deletePage',
			params: {
				namespace: 0,
				title,
				wikiPageId: editResults.pageid,
				reason: 'testing delete job',
				suppress: false,
				tags: [],
				logsubtype: 'delete'
			}
		};

		const secretKey = wiki.getSecretKey();
		const strEvent = JSON.stringify( event );
		event.mediawiki_signature = crypto.createHmac( 'sha1', secretKey ).update( strEvent ).digest( 'hex' );
		return event;
	};

	before( async () => {
		mindy = await action.mindy();
		editResults = await mindy.edit( title, { text: 'Create Page', summary: 'edit 1' } );
		siteInfo = await mindy.meta( 'siteinfo', {}, 'general' );
	} );

	it( 'should return a 200 for delete job', async () => {
		const event = getDeletePageJobEvent();
		const { body, status } = await client.post( '/job/execute', event );

		assert.equal( body.status, true );
		assert.isNull( body.error );
		assert.exists( body.timeMs );
		assert.equal( status, 200 );

		const { code } = await mindy.actionError( 'parse', { page: title } );
		assert.equal( code, 'missingtitle' );
	} );

	it( 'should return 400 for missing event database', async () => {
		const missingDatabaseEvent = getDeletePageJobEvent();
		delete missingDatabaseEvent.database;
		const { status, body } = await client.post( '/job/execute', missingDatabaseEvent );

		assert.equal( status, 400 );
		assert.deepEqual( body.missing_params, [ 'database' ] );
	} );

	it( 'should return 400 for missing event type', async () => {
		const missingTypeEvent = getDeletePageJobEvent();
		delete missingTypeEvent.type;
		const { status, body } = await client.post( '/job/execute', missingTypeEvent );

		assert.equal( status, 400 );
		assert.deepEqual( body.missing_params, [ 'type' ] );
	} );

	it( 'should return 400 for missing event params', async () => {
		const missingParamsEvent = getDeletePageJobEvent();
		delete missingParamsEvent.params;
		const { status, body } = await client.post( '/job/execute', missingParamsEvent );

		assert.equal( status, 400 );
		assert.deepEqual( body.missing_params, [ 'params' ] );
	} );

	it( 'should return 403 for missing signature', async () => {
		const missingSignatureEvent = getDeletePageJobEvent();
		delete missingSignatureEvent.mediawiki_signature;
		const { status, body } = await client.post( '/job/execute', missingSignatureEvent );

		assert.equal( status, 403 );
		assert.equal( body.message, 'Missing mediawiki signature' );

	} );

	it( 'should return 403 for invalid signature', async () => {
		const invalidSignatureEvent = getDeletePageJobEvent();
		invalidSignatureEvent.mediawiki_signature = '8765234567890dak98ufnjrkw2';
		const { status, body } = await client.post( '/job/execute', invalidSignatureEvent );

		assert.equal( status, 403 );
		assert.equal( body.message, 'Invalid mediawiki signature' );
	} );

	it( 'should return 415 for unsupported content-type', async () => {
		const event = JSON.stringify( getDeletePageJobEvent() );
		const { status, body } = await client.post( '/job/execute', event, 'text/plain' );

		assert.equal( status, 415 );
		assert.equal( body.message, 'Unsupported Content-Type' );
	} );
} );
