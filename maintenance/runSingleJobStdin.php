<?php
/**
 * Run a single job from a definition passed from stdin
 *
 * Take a jobqueue-style job definition encoded as JSON as an input
 * via stdin and execute it using a jobexecutor in the same style as
 * rpc/RunSingleJob.php does on jobrunner hosts. This script can be
 * used to run any one-off job and might be useful for testing the
 * execution or rerunning of single jobs but is primarily designed for
 * use with mercurius in videoscaling tasks.
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
 * @ingroup Maintenance
 * @author Hugh Nowlan <hnowlan@wikimedia.org>
 */

namespace MediaWiki\Extensions\EventBus\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

use Exception;
use MediaWiki\Extension\EventBus\JobExecutor;
use MediaWiki\Maintenance\Maintenance;

class RunSingleJobStdin extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Run a single job from stdin' );
	}

	public function execute() {
		$input = file_get_contents( "php://stdin" );
		if ( $input === '' ) {
			$this->fatalError( 'No event received.' );
		}

		$event_data = json_decode( $input, true );

		if ( !isset( $event_data['database'] ) ) {
			$this->fatalError( 'Invalid event received - database field is missing!' );
		}

		$executor = new JobExecutor();
		try {
			$response = $executor->execute( $event_data );
			if ( $response['status'] === true ) {
				$this->output( "Job executed successfully: {$response['message']}\n" );
			} else {
				if ( $response['readonly'] ) {
					// TODO - T204154
					// if we detect that the DB is in read-only mode, we delay the return of the
					// response to keep request rate low
					$this->output( "Sleeping due to readonly response from executor" );
					sleep( rand( 40, 45 ) );
				}
				$this->fatalError( "Failed to execute job on {$event_data['database']}: {$response['message']}" );
			}
		} catch ( Exception $e ) {
			$this->fatalError( "Caught exception when executing event for {$event_data['database']}: " .
				get_class( $e ) . ": {$e->getMessage()}\n" . $e->getTraceAsString() );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = RunSingleJobStdin::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
