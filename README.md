# EventBus

EventBus is a [MediaWiki](https://www.mediawiki.org/) extension that produces changes to a [RESTful event service](https://gerrit.wikimedia.org/r/#/admin/projects/eventlogging).

This extension is part of a larger effort to create a general purpose event system, to reliably propagate state changes from one part of the infrastructure, to another.  Since
this approach relies upon hooks, it is not atomic (delivery of an event could fail after MediaWiki has committed the corresponding change), and so does not provide the reliability
we're aiming for.  Therefore, this extension should be considered an interim solution, until the requisite changes can be made to MediaWiki core.

## Configuration

To configure the URL of the event service:

    $wgEventServiceUrl = 'http://localhost:8085/v1/topics';

To configure the event service request timeout:

    $wgEventServiceTimeout = 5;    // 5 second timeout

## References

  * [Reliable publish / subscribe event bus](https://phabricator.wikimedia.org/T84923)
  * [Integrate event production into MediaWiki](https://phabricator.wikimedia.org/T116786)

## License

EventBus is licensed under the GNU General Public License 2.0 or any later version. You may obtain a copy of this license at <http://www.gnu.org/copyleft/gpl.html>.
