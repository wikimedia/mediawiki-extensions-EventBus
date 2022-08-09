# EventBus

EventBus is a [MediaWiki](https://www.mediawiki.org/) extension that produces changes
to a [RESTful event intake service](https://wikitech.wikimedia.org/wiki/Event_Platform/EventGate).

This extension is part of a larger effort to create a general purpose event system, to
reliably propagate state changes from one part of the infrastructure, to another.  Since
this approach relies upon hooks, it is not atomic (delivery of an event could fail after
MediaWiki has committed the corresponding change), and so does not provide the reliability
we're aiming for.  Therefore, this extension should be considered an interim solution, until
the requisite changes can be made to MediaWiki core.

This EventBus extension is really meant to be just a producer client library, but over time it
has also become a place where the logic for constructing events themselves is placed.
EventBus was originally intended for the propagation of changes to core MediaWiki
entities (pages, revisions, users, etc.).

Until a better place becomes available, or MediaWiki core provides us with a default way
to do this, event construction logic for MediaWiki core entities should remain here in
EventBus extension

However, other usages of `EventBus` as a library to produce events should live in other
extensions that use the EventBus extension as a dependency.

## Configuration

EventBus supports configuration of multiple event intake service endpoints via the `EventServices`
main config array.  It expects entries keyed by event service name pointing at arrays of
event service config.  E.g.

    $wgEventServices = [
        'eventbus-main' => [
            'url' => 'http://locahost:8085/v1/events',
            'timeout' => 5,
        ],
        'eventgate-main' => [
            'url' => 'http://localhost:8192/v1/events',
        ],
    ];

EventBus instances should be created via either the static `EventBusFactory::getInstance` or
`EventBusFactory::getInstanceForStream` methods.

`EventBusFactory::getInstance` takes one of the configured event service
names from the `EventServices` main config.

`EventBusFactory::getInstanceForStream` takes a stream name and looks up the
`destination_event_service` configured for that stream in `$wgEventStreams` config array,
via the [EventStreamConfig](https://wikitech.wikimedia.org/wiki/Event_Platform/Stream_Configuration)
extension (see below)

`EnableEventBus` config parameter specifies which types of events the extension will produce.
Possible options are `TYPE_NONE`, `TYPE_EVENT`, `TYPE_JOB`, `TYPE_PURGE` or `TYPE_ALL`.
Specifying multiple types using `|` as a delimiter is supported. Example: `TYPE_JOB|TYPE_PURGE`

EventBus also supports per stream event service configuration, meaning you can configure
specifically which event service should be used for a particular stream name.  This
is handled via the EventStreamConfig extension.  See docs there on how to configure
`$wgEventStreams`.  To use `$wgEventStreams` to specify an event service, add
the 'destination_event_service' setting to your stream's config entry.  E.g.

    $wgEventStreams = [
        [
            'stream' => 'mediawiki.my-event',
            'destination_event_service' => 'eventgate-main'
        ]
    ];

Per stream configuration via EventStreamConfig is optional.  The default behavior is to
produce all streams to the service specified by `$wgEventServiceDefault`.
You must set `$wgEventServiceDefault` to an entry in `$wgEventServices` to be
used in case a stream's `destination_event_service` setting is not provided.

    $wgEventServiceDefault = 'eventgate-main';

## EventBus RCFeed

This extension also provides an FormattedRCFeed and RCFeedFormatter implementation
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


## EventBus HookHandlers and generated events

As noted above, this EventBus extension should probably be just a producer library.
At the very least, events that are not about carrying MW core state to external systems
shouldn't be here.

For the time being though, this EventBus extension contains several MW hook handlers that
actually produce events.

`EventBusHooks` and `EventFactory` are deprecated in favor of HookHandler classes and
specific Serializers that serialize from MediaWiki classes to event objects.

Each single stream that is produced by this extension should have its own HookHandler class
that is solely responsible for producing events to that stream.

### EventBusStreamNamesMap config
HookHandlers/Mediawiki/PageChangeHooks.php adds a new config `EventBusStreamNamesMap` that
maps from internal logical names of streams to the actual stream name that will be produced.
This allows developers to vary the stream name used for e.g. mediawiki.page_change in testing
and staging environments.  Perhaps you want to produce page change events to a release candidate
stream before promoting it to 'production'.  Instead of having 'mediawiki.page_change' hardcoded
into the hook handler, the stream name to produce will be looked up in config from
`EventBusStreamNamesMap['mediawiki_page_change']`, defaulting to 'mediawiki.page_change'.

Any HookHandlers that produce events should support configuring the stream name that
they produce to using `EventBusStreamNamesMap` in this way.

TODO: The logic to look up the stream name to produce should be DRYed into its own location
the next time we add a new HookHandler here.





## References

  * Wikimedia Foundation's [Event Platform](https://wikitech.wikimedia.org/wiki/Event_Platform)
  * [Reliable publish / subscribe event bus](https://phabricator.wikimedia.org/T84923)
  * [Integrate event production into MediaWiki](https://phabricator.wikimedia.org/T116786)

## License

EventBus is licensed under the GNU General Public License 2.0 or any later version.
You may obtain a copy of this license at <http://www.gnu.org/copyleft/gpl.html>.
