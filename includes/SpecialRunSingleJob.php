<?php
/**
 * Implements Special:RunSingleJob
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;

/**
 * Special page designed for running a single background task (internal use only)
 *
 * @ingroup SpecialPage
 */
class SpecialRunSingleJob extends UnlistedSpecialPage {

	/** @var LoggerInterface instance for all SpecialRunJobs instances */
	private static $logger;

	public function __construct() {
		parent::__construct( 'RunSingleJob' );
	}

	public function doesWrites() {
		return true;
	}

	public function execute( $par = '' ) {
		$this->getOutput()->disable();

		if ( wfReadOnly() ) {
			wfHttpError( 423, 'Locked', 'Wiki is in read-only mode.' );
			return;
		}

		// Validate request method
		if ( !$this->getRequest()->wasPosted() ) {
			wfHttpError( 400, 'Bad Request', 'Request must be POSTed.' );
			return;
		}

		// get the info contained in the body
		$event = null;
		try {
			$event = FormatJson::decode( file_get_contents( "php://input" ), true );
		} catch ( Exception $e ) {
			wfHttpError( 500, 'Server Error', 'Could not decode the event' );
			return;
		}

		// check that we have the needed components of the event
		if ( !isset( $event['database'], $event['type'], $event['page_title'], $event['params'] ) ) {
			wfHttpError( 400, 'Bad Request', 'Invalid event received' );
			return;
		}

		if ( !isset( $event['mediawiki_signature'] ) ) {
			wfHttpError( 403, 'Forbidden', 'Missing mediawiki signature' );
			return;
		}
		$signature = $event['mediawiki_signature'];
		unset( $event['mediawiki_signature'] );
		$expected_signature = hash( 'sha256', JWT::encode(
			$event,
			MediaWikiServices::getInstance()->getMainConfig()->get( 'SecretKey' )
		) );
		if ( !hash_equals( $expected_signature, $signature ) ) {
			wfHttpError( 403, 'Forbidden', 'Invalid mediawiki signature' );
			return;
		}

		// check if there are any base64-encoded parameters and if so decode them
		foreach ( $event['params'] as $key => &$value ) {
			if ( !is_string( $value ) ) {
				continue;
			}
			if ( preg_match( '/^data:application\/octet-stream;base64,([\s\S]+)$/', $value, $match ) ) {
				$value = base64_decode( $match[1], true );
				if ( $value === false ) {
					wfHttpError(
						500,
						'Internal Server Error',
						"base64_decode() failed for parameter {$key} ({$match[1]})"
					);
					return;
				}
			}
		}
		unset( $value );

		$executor = new JobExecutor();

		try {
			// execute the job
			$response = $executor->execute( $event );
			if ( $response['status'] === true ) {
				HttpStatus::header( 200 );
				return;
			} else {
				wfHttpError( 500, 'Internal Server Error', $response['message'] );
			}
		} catch ( Exception $e ) {
			self::logger()->error(
				'Error running job ' . $event['meta']['id'] . ' of type ' . $event['type'],
				[
					'exception' => $e
				]
			);
			wfHttpError( 500, 'Internal Server Error', $e->getMessage() );
		}
	}

	/**
	 * Returns a singleton logger instance for all JobExecutor instances.
	 * Use like: self::logger()->info( $mesage )
	 * We use this so we don't have to check if the logger has been created
	 * before attempting to log a message.
	 * @return LoggerInterface
	 */
	private static function logger() {
		if ( !self::$logger ) {
			self::$logger = LoggerFactory::getInstance( 'RunSingleJob' );
		}
		return self::$logger;
	}
}
