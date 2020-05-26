# EventBus

EventBus is a [MediaWiki](https://www.mediawiki.org/) extension that produces changes to a [RESTful event service](https://gerrit.wikimedia.org/r/#/admin/projects/eventlogging).

This extension is part of a larger effort to create a general purpose event system, to reliably propagate state changes from one part of the infrastructure, to another.  Since
this approach relies upon hooks, it is not atomic (delivery of an event could fail after MediaWiki has committed the corresponding change), and so does not provide the reliability
we're aiming for.  Therefore, this extension should be considered an interim solution, until the requisite changes can be made to MediaWiki core.

## Configuration

EventBus supports configuration of multiple event service endpoints via the `EventServices`
main config array.  It expects entries keyed by event service name pointing at arrays of
event service config.  E.g.

    $wgEventServices = {
        'eventbus-main' => {
            'url' => 'http://locahost:8085/v1/topics',
            'timeout' => 5,
        },
        'eventgate-main' => {
            'url' => 'http://localhost:8192/v1/topics',
        }
    }

EventBus instances should be created via the static `getInstance` method.  This method
takes one of the configued event service names from the `EventServices` main config.

## EventBus RCFeed

This extension also provides an RCFeedEngine and RCFeedFormatter implementation
That will allow RCFeed configuration to post to the EventBus service in the
`mediawiki.recentchange` topic.  To use,
add the following to your `LocalSettings.php`:

```php
use MediaWiki\Extension\EventBus\Adapters\RCFeed\EventBusRCFeedEngine;
use MediaWiki\Extension\EventBus\Adapters\RCFeed\EventBusRCFeedFormatter;

$wgRCFeeds['eventgate-main'] = array(
    'class'            => EventBusRCFeedEngine::class,
    'formatter'        => EventBusRCFeedFormatter::class,
    // This should be the name of an event service entry
    // defined in $wgEventServices.
    'eventServiceName' => 'eventgate-main',
);
```


## References

  * [Reliable publish / subscribe event bus](https://phabricator.wikimedia.org/T84923)
  * [Integrate event production into MediaWiki](https://phabricator.wikimedia.org/T116786)

## License

EventBus is licensed under the GNU General Public License 2.0 or any later version. You may obtain a copy of this license at <http://www.gnu.org/copyleft/gpl.html>.
